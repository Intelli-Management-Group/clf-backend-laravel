<?php

namespace App\Http;

use Illuminate\Http\Request;

/**
 * Tracks the parsed request body the way Express exposes `req.body`.
 *
 * In Express, `req.body` stays undefined unless a body parser handled the
 * request (JSON and urlencoded bodies everywhere, multipart only on routes
 * using Multer). Controllers read the body through this class so requests
 * without a parsable body behave exactly as they did before.
 */
final class RequestBody
{
    const ATTRIBUTE = '_parsed_body';

    /**
     * Store the parsed body for the request.
     */
    public static function set(Request $request, array $body)
    {
        $request->attributes->set(self::ATTRIBUTE, $body);
    }

    /**
     * The parsed body, or null when no body parser handled the request.
     *
     * @return array|null
     */
    public static function get(Request $request)
    {
        return $request->attributes->get(self::ATTRIBUTE);
    }

    /**
     * Whether the request carries a body (type-is `hasBody()`).
     */
    public static function present(Request $request): bool
    {
        return $request->headers->has('Transfer-Encoding')
            || is_numeric($request->headers->get('Content-Length'));
    }

    /**
     * The lower-cased media type of the request, without parameters.
     */
    public static function mediaType(Request $request): string
    {
        $type = (string) $request->headers->get('Content-Type');

        return strtolower(trim(explode(';', $type)[0]));
    }
}
