<?php

namespace App\Providers;

use App\Services\UploadService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->singleton(UploadService::class, function ($app) {
            return new UploadService(
                $app['config']->get('clf.uploads.directory'),
                $app['config']->get('clf.uploads.url')
            );
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }
}
