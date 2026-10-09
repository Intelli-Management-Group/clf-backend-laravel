<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
     * POST /api/gallery (multipart)
     */
    public function store(Request $request)
    {
        try {
            $body = $this->body($request, 'title');
            list($thumbnail, $photos) = $this->uploadedImages($request);

            // The id is chosen up front so the images go straight into the gallery's folder.
            $id = ObjectId::generate();
            $catalogThumbnail = $thumbnail ? $this->storeThumbnail($thumbnail, $id) : '';
            $sources = $this->storePhotos($photos, $id);

            $attributes = Gallery::schema()->forCreate(
                $this->present($body, ['title', 'year', 'subTitle']) + ['catalogThumbnail' => $catalogThumbnail]
            );

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
     * PUT /api/gallery/{id} (multipart). Uploaded photos replace existing
     * ones; otherwise submitted photoUrls keep only those existing images.
     */
    public function update(Request $request, string $id)
    {
        try {
            $body = $this->body($request, 'title');
            list($thumbnail, $photos) = $this->uploadedImages($request);

            $updates = $this->present($body, ['title', 'year', 'subTitle']);

            $key = Gallery::castId($id);
            $changes = Gallery::schema()->forUpdate($updates, false);
            $gallery = Gallery::query()->find($key);

            if (! $gallery) {
                return $this->notFound();
            }

            if ($thumbnail) {
                $changes += Gallery::schema()->forUpdate(['catalogThumbnail' => $this->storeThumbnail($thumbnail, $key)], false);
            }
            $sources = $this->storePhotos($photos, $key);
            $photoUrls = null;

            if (! $sources && array_key_exists('photoUrls', $body)) {
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
                $photoUrls = array_values(array_filter(array_map('trim', $photoUrls), function ($photoUrl) {
                    return $photoUrl !== '';
                }));
            }

            DB::transaction(function () use ($gallery, $changes, $sources, $photoUrls) {
                $gallery->fillFromApi($changes)->touch();
                if ($sources) {
                    $gallery->replacePhotos($sources);
                } elseif ($photoUrls !== null) {
                    $gallery->syncPhotoUrls($photoUrls);
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
