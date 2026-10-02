<?php

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Every endpoint of the original Express API, served under /api. Routes are
| stateless and unauthenticated, exactly as before. Gallery create/update
| accept multipart uploads through the "multipart" middleware (Multer's
| upload.any() equivalent).
|
*/

use App\Http\Controllers\Api\AboutController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\GalleryController;
use App\Http\Controllers\Api\NewsController;
use App\Http\Controllers\Api\VideoController;

Route::prefix('admin')->group(function () {
    Route::post('login', [AdminController::class, 'login']);
    Route::post('logout', [AdminController::class, 'logout']);
});

Route::prefix('about')->group(function () {
    Route::post('/', [AboutController::class, 'store']);
    Route::get('/', [AboutController::class, 'index']);
    Route::get('{id}', [AboutController::class, 'show']);
    Route::put('{id}', [AboutController::class, 'update']);
    Route::delete('{id}', [AboutController::class, 'destroy']);
});

Route::prefix('news')->group(function () {
    Route::post('/', [NewsController::class, 'store']);
    Route::get('/', [NewsController::class, 'index']);
    Route::get('{id}', [NewsController::class, 'show']);
    Route::put('{id}', [NewsController::class, 'update']);
    Route::delete('{id}', [NewsController::class, 'destroy']);
});

Route::prefix('gallery')->group(function () {
    Route::post('/', [GalleryController::class, 'store'])->middleware('multipart');
    Route::get('list-by-year', [GalleryController::class, 'listByYear']);
    Route::delete('{id}', [GalleryController::class, 'destroy']);
    Route::get('{id}', [GalleryController::class, 'show']);
    Route::put('{id}', [GalleryController::class, 'update'])->middleware('multipart');
});

Route::prefix('videos')->group(function () {
    Route::post('/', [VideoController::class, 'store']);
    Route::get('/', [VideoController::class, 'index']);
    Route::get('{id}', [VideoController::class, 'show']);
    Route::put('{id}', [VideoController::class, 'update']);
    Route::delete('{id}', [VideoController::class, 'destroy']);
});

Route::get('dashboard', [DashboardController::class, 'index']);

Route::post('contact', [ContactController::class, 'store']);
