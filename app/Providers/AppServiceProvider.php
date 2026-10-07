<?php

namespace App\Providers;

use App\Services\ImageOptimizer;
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
        $this->app->singleton(ImageOptimizer::class, function ($app) {
            $config = $app['config']->get('clf.uploads');

            return new ImageOptimizer($config['max_dimension'], $config['jpeg_quality'], $config['jpeg_quality']);
        });

        $this->app->singleton(UploadService::class, function ($app) {
            return new UploadService(
                $app['config']->get('clf.uploads.directory'),
                $app['config']->get('clf.uploads.url'),
                $app['config']->get('clf.uploads.compress') ? $app->make(ImageOptimizer::class) : null
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
