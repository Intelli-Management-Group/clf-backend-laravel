<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * Laravel's TrimStrings, ConvertEmptyStringsToNull and ValidatePostSize
     * are intentionally not used: the API keeps request values exactly as
     * sent (like Express did) and enforces Express' body limits itself.
     *
     * @var array
     */
    protected $middleware = [
        \App\Http\Middleware\Cors::class,
        \App\Http\Middleware\TrustProxies::class,
        \App\Http\Middleware\CheckForMaintenanceMode::class,
        \App\Http\Middleware\ParseRequestBody::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * The API is stateless (no sessions, cookies or CSRF tokens) and, like
     * the original Express API, has no rate limiting.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'api' => [
            'bindings',
        ],
    ];

    /**
     * The application's route middleware.
     *
     * @var array
     */
    protected $routeMiddleware = [
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'multipart' => \App\Http\Middleware\ParseMultipartFormData::class,
    ];
}
