<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\GalleryResource;
use App\Http\Resources\GallerySummaryResource;
use App\Models\Gallery;
use App\Services\UploadService;
use App\Support\Js;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

            $catalogThumbnail = $thumbnail ? $this->uploads->store($thumbnail) : '';
            $sources = array_map([$this->uploads, 'store'], $photos);

            $attributes = Gallery::schema()->forCreate(
                $this->present($body, ['title', 'year', 'subTitle']) + ['catalogThumbnail' => $catalogThumbnail]
            );

            $gallery = DB::transaction(function () use ($attributes, $sources) {
                $gallery = (new Gallery)->fillFromApi($attributes);
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
     * PUT /api/gallery/{id} (multipart). Uploaded photos replace the
     * existing ones; a new catalogThumbnail replaces the old one.
     */
    public function update(Request $request, string $id)
    {
        try {
            $body = $this->body($request, 'title');
            list($thumbnail, $photos) = $this->uploadedImages($request);

            $updates = $this->present($body, ['title', 'year', 'subTitle']);
            if ($thumbnail) {
                $updates['catalogThumbnail'] = $this->uploads->store($thumbnail);
            }
            $sources = array_map([$this->uploads, 'store'], $photos);

            $key = Gallery::castId($id);
            $changes = Gallery::schema()->forUpdate($updates, false);
            $gallery = Gallery::query()->find($key);

            if (! $gallery) {
                return $this->notFound();
            }

            DB::transaction(function () use ($gallery, $changes, $sources) {
                $gallery->fillFromApi($changes)->touch();
                if ($sources) {
                    $gallery->replacePhotos($sources);
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
