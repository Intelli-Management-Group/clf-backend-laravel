<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\VideoResource;
use App\Models\Video;
use Illuminate\Http\Request;

class VideoController extends Controller
{
    const FIELDS = ['title', 'videoUrl', 'catalogThumbnail'];

    /**
     * GET /api/videos
     */
    public function index()
    {
        try {
            $videos = Video::query()->orderBy('created_at')->orderBy('id')->get();

            return $this->respond([
                'success' => true,
                'message' => 'Videos fetched successfully',
                'data' => VideoResource::many($videos),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch videos',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }

    /**
     * GET /api/videos/{id}
     */
    public function show(string $id)
    {
        try {
            $video = Video::findById($id);

            if (! $video) {
                return $this->notFound();
            }

            return $this->respond([
                'success' => true,
                'message' => 'Video fetched successfully',
                'data' => (new VideoResource($video))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch video',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }

    /**
     * POST /api/videos
     */
    public function store(Request $request)
    {
        try {
            $body = $this->body($request, 'title');
            $attributes = Video::schema()->forCreate($this->present($body, self::FIELDS));

            $video = (new Video)->fillFromApi($attributes);
            $video->save();

            return $this->respond([
                'success' => true,
                'message' => 'Video created successfully',
                'data' => (new VideoResource($video))->resolve(),
            ], 201);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to create video',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 400);
        }
    }

    /**
     * PUT /api/videos/{id}
     */
    public function update(Request $request, string $id)
    {
        try {
            $body = $this->body($request, 'title');
            $key = Video::castId($id);
            $changes = Video::schema()->forUpdate($this->present($body, self::FIELDS), false);
            $video = Video::query()->find($key);

            if (! $video) {
                return $this->notFound();
            }

            $video->fillFromApi($changes)->touch();

            return $this->respond([
                'success' => true,
                'message' => 'Video updated successfully',
                'data' => (new VideoResource($video))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to update video',
                'error' => $this->errorMessage($e, true),
                'data' => null,
            ], 400);
        }
    }

    /**
     * DELETE /api/videos/{id}
     */
    public function destroy(string $id)
    {
        try {
            $video = Video::findById($id);

            if (! $video) {
                return $this->notFound();
            }

            $video->delete();

            return $this->respond([
                'success' => true,
                'message' => 'Video deleted successfully',
                'data' => null,
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to delete video',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }

    private function notFound()
    {
        return $this->respond([
            'success' => false,
            'message' => 'Video not found',
            'data' => null,
        ], 404);
    }
}
