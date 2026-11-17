<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ParseMultipartFormData;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\GallerySummaryResource;
use App\Models\Gallery;
use App\Services\UploadService;
use App\Support\Js;
use App\Support\ObjectId;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Photo galleries. Create/update accept multipart/form-data with a
 * `catalogThumbnail` file and any number of `photos` files.
 */
class GalleryController extends Controller
{
    /**
     * @var UploadService
     */
    private $uploads;

    public function __construct(UploadService $uploads)
    {
        $this->uploads = $uploads;
    }

    /**
     * GET /api/gallery/list-by-year - galleries grouped by year
     * ("unknown" when the year is empty).
     */
    public function listByYear()
    {
        try {
            $galleries = Gallery::query()->with('photos')->orderBy('created_at')->orderBy('id')->get();

            $groups = [];
            foreach ($galleries as $gallery) {
                $year = Js::truthy($gallery->year) ? (string) $gallery->year : 'unknown';
                $groups[$year][] = (new GallerySummaryResource($gallery))->resolve();
            }

            return $this->respond([
                'success' => true,
                'message' => 'Gallery list grouped by year',
                'data' => $this->orderLikeJavaScriptObject($groups),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch galleries',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }

    /**
     * GET /api/gallery/{id}
     */
    public function show(string $id)
    {
        try {
            $gallery = Gallery::findById($id);

            if (! $gallery) {
                return $this->notFound();
            }

            return $this->respond([
                'success' => true,
                'message' => 'Gallery fetched successfully',
                'data' => (new GalleryResource($gallery->load('photos')))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch gallery',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }

    /**
     * POST /api/gallery (multipart). Photos are the uploaded `photos` files
     * and `photoUrls` strings, in the order sent; `catalogThumbnail` may be
     * a file or a URL string.
     */
    public function store(Request $request)
    {
        try {
            $this->allowLongUploads();
            $body = $this->body($request, 'title');
            list($thumbnail, $photos) = $this->uploadedImages($request);

            $fields = $this->present($body, ['title', 'year', 'subTitle']);
            $photoUrls = $this->photoUrls($body);

            // Validate before writing any file, so a rejected request leaves nothing on disk.
            Gallery::schema()->forCreate($fields);

            // The id is chosen up front so the images go straight into the gallery's folder.
            $id = ObjectId::generate();
            $catalogThumbnail = $thumbnail ? $this->storeThumbnail($thumbnail, $id) : $this->thumbnailUrl($body);
            $sources = $this->inPartOrder($request, $photoUrls ?: [], $this->storePhotos($photos, $id));

            $attributes = Gallery::schema()->forCreate($fields + ['catalogThumbnail' => $catalogThumbnail]);

            $gallery = DB::transaction(function () use ($id, $attributes, $sources) {
                $gallery = (new Gallery)->fillFromApi($attributes);
                $gallery->setAttribute($gallery->getKeyName(), $id);
                $gallery->save();
                $gallery->replacePhotos($sources);

                return $gallery;
            });

            return $this->respond([
                'success' => true,
                'message' => 'Gallery created successfully',
                'data' => (new GalleryResource($gallery->load('photos')))->resolve(),
            ], 201);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to create gallery',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }

    /**
     * PUT /api/gallery/{id} (multipart). When `photoUrls` is sent it lists
     * the existing photos to keep, and uploaded `photos` are added in the
     * order sent (this is what the admin panel does when photos are added to
     * a gallery). Without `photoUrls`, uploaded photos replace all existing ones.
     */
    public function update(Request $request, string $id)
    {
        try {
            $this->allowLongUploads();
            $body = $this->body($request, 'title');
            list($thumbnail, $photos) = $this->uploadedImages($request);

            $updates = $this->present($body, ['title', 'year', 'subTitle']);
            if (! $thumbnail && $this->thumbnailUrl($body) !== '') {
                $updates['catalogThumbnail'] = $this->thumbnailUrl($body);
            }

            $key = Gallery::castId($id);
            $changes = Gallery::schema()->forUpdate($updates, false);
            // Checked before any file is written.
            $photoUrls = $this->photoUrls($body);
            $gallery = Gallery::query()->find($key);

            if (! $gallery) {
                return $this->notFound();
            }

            if ($thumbnail) {
                $changes += Gallery::schema()->forUpdate(['catalogThumbnail' => $this->storeThumbnail($thumbnail, $key)], false);
            }
            $uploaded = $this->storePhotos($photos, $key);

            $sources = null;
            $replace = false;
            if ($photoUrls !== null) {
                $sources = $this->inPartOrder($request, $photoUrls, $uploaded);
            } elseif ($uploaded) {
                $sources = $uploaded;
                $replace = true;
            }

            DB::transaction(function () use ($gallery, $changes, $sources, $replace) {
                $gallery->fillFromApi($changes)->touch();
                if ($sources === null) {
                    return;
                }
                if ($replace) {
                    $gallery->replacePhotos($sources);
                } else {
                    // Keeps the ids of photos that stay, adds the new ones.
                    $gallery->syncPhotoUrls($sources);
                }
            });

            return $this->respond([
                'success' => true,
                'message' => 'Gallery updated successfully',
                'data' => (new GalleryResource($gallery->load('photos')))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update gallery',
                'error' => $this->errorMessage($e, true),
                'data' => null,
            ], 500);
        }
    }

    /**
     * DELETE /api/gallery/{id} - removes the gallery and its photo records
     * (image files are kept on disk, as before).
     */
    public function destroy(string $id)
    {
        try {
            $gallery = Gallery::findById($id);

            if (! $gallery) {
                return $this->notFound();
            }

            $gallery->delete();

            return $this->respond([
                'success' => true,
                'message' => 'Gallery deleted successfully',
                'data' => null,
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete gallery',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }

    /**
     * Folder of a gallery's images, relative to the uploads directory.
     */
    public static function folder(string $galleryId): string
    {
        return 'galleries/'.$galleryId;
    }

    private function storeThumbnail(UploadedFile $file, string $galleryId): string
    {
        return $this->uploads->store($file, self::folder($galleryId).'/thumbnail');
    }

    private function storePhotos(array $files, string $galleryId): array
    {
        return array_map(function (UploadedFile $file) use ($galleryId) {
            return $this->uploads->store($file, self::folder($galleryId));
        }, $files);
    }

    /**
     * The last `catalogThumbnail` file and all `photos` files, in upload order.
     */
    private function uploadedImages(Request $request): array
    {
        $thumbnails = (array) $request->files->get('catalogThumbnail', []);
        $photos = (array) $request->files->get('photos', []);

        return [$thumbnails ? end($thumbnails) : null, array_values($photos)];
    }

    /**
     * The submitted `photoUrls`, trimmed (null when the field is absent).
     *
     * @throws \InvalidArgumentException when it is not a string or a list of strings
     */
    private function photoUrls(array $body)
    {
        if (! array_key_exists('photoUrls', $body)) {
            return null;
        }

        $photoUrls = $body['photoUrls'];
        if (is_string($photoUrls)) {
            $photoUrls = [$photoUrls];
        }
        if (! is_array($photoUrls)) {
            throw new \InvalidArgumentException('photoUrls must be a string or an array of strings');
        }
        foreach ($photoUrls as $photoUrl) {
            if (! is_string($photoUrl)) {
                throw new \InvalidArgumentException('photoUrls must contain only strings');
            }
        }

        return array_values(array_map('trim', $photoUrls));
    }

    /**
     * A `catalogThumbnail` sent as text (an image URL or an existing
     * "/uploads/..." path) rather than as a file; '' when there is none.
     */
    private function thumbnailUrl(array $body): string
    {
        $value = $body['catalogThumbnail'] ?? null;
        if (is_array($value)) {
            // Repeated field: the last one wins, as for the file.
            $value = end($value);
        }

        return is_string($value) ? trim($value) : '';
    }

    /**
     * Merge photo URLs and uploaded photo paths back into the order their
     * parts were sent (the admin panel interleaves them). When that order is
     * unknown (JSON body, or a body PHP parsed itself) URLs come first.
     * Empty URLs are dropped.
     */
    private function inPartOrder(Request $request, array $urls, array $uploads): array
    {
        $ordered = [];
        $parts = $request->attributes->get(ParseMultipartFormData::PARTS);

        if (is_array($parts)) {
            foreach ($parts as list($name, $isFile)) {
                if ($isFile && $name === 'photos' && $uploads) {
                    $ordered[] = array_shift($uploads);
                } elseif (! $isFile && $name === 'photoUrls' && $urls) {
                    $ordered[] = array_shift($urls);
                }
            }
        }

        return array_values(array_filter(array_merge($ordered, $urls, $uploads), function ($source) {
            return $source !== '';
        }));
    }

    /**
     * Compressing dozens of phone photos in one request can outlast PHP's
     * usual 30 second max_execution_time, which would end in a fatal 500.
     */
    private function allowLongUploads()
    {
        $seconds = (int) config('clf.uploads.time_limit', 300);

        if ($seconds > 0 && function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }

    /**
     * Order keys like a JavaScript object: integer-like keys ascending,
     * then the remaining keys in insertion order. Always encodes as an object.
     */
    private function orderLikeJavaScriptObject(array $groups): \stdClass
    {
        $indexKeys = [];
        $otherKeys = [];

        foreach (array_keys($groups) as $key) {
            $key = (string) $key;
            if (preg_match('/^(0|[1-9]\d*)$/', $key) && (float) $key < 4294967295) {
                $indexKeys[] = $key;
            } else {
                $otherKeys[] = $key;
            }
        }

        usort($indexKeys, function ($a, $b) {
            return (float) $a <=> (float) $b;
        });

        $ordered = new \stdClass;
        foreach (array_merge($indexKeys, $otherKeys) as $key) {
            $ordered->{$key} = $groups[$key];
        }

        return $ordered;
    }

    private function notFound()
    {
        return $this->respond([
            'success' => false,
            'message' => 'Gallery not found',
            'data' => null,
        ], 404);
    }
}
