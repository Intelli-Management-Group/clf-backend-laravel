<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CORS headers identical to the `cors()` Express middleware defaults:
 * any origin, and preflight requests answered with 204 before routing.
 */
class Cors
{
    const ALLOWED_METHODS = 'GET,HEAD,PUT,PATCH,POST,DELETE';

    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if ($request->getRealMethod() === 'OPTIONS') {
            $response = new Response('', 204);
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', self::ALLOWED_METHODS);
            $response->headers->set('Vary', 'Access-Control-Request-Headers');

            $requestedHeaders = $request->headers->get('Access-Control-Request-Headers');
            if ($requestedHeaders) {
                $response->headers->set('Access-Control-Allow-Headers', $requestedHeaders);
            }

            // Stop PHP from adding its default "text/html" Content-Type to the empty 204.
            ini_set('default_mimetype', '');

            return $response;
        }

        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', '*');

        return $response;
    }
}
