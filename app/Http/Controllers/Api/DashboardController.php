<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Gallery;
use App\Models\News;
use App\Models\Video;

class DashboardController extends Controller
{
    /**
     * GET /api/dashboard - admin dashboard counters.
     */
    public function index()
    {
        try {
            return $this->respond([
                'success' => true,
                'message' => 'Dashboard stats fetched successfully',
                'data' => [
                    'activatedNewsCount' => News::query()->where('is_active', true)->count(),
                    'imagesCount' => Gallery::query()->count(),
                    'videosCount' => Video::query()->count(),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to fetch dashboard stats',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }
}
