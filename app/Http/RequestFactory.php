<?php

namespace App\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Builds the incoming request like Request::capture(), except that a
 * multipart body sent with PUT, PATCH or DELETE is left unread.
 *
 * On PHP >= 8.4 Symfony parses those bodies with request_parse_body(), which
 * keeps only the last value of a repeated field name (the admin panel sends
 * many `photos` files and `photoUrls` fields when a gallery is saved) and
 * consumes php://input. Leaving the body unread lets ParseMultipartFormData
 * parse it with Multer semantics, as it already does for POST.
 */
final class RequestFactory
{
    public static function capture(): Request
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');

        if (! in_array($method, ['PUT', 'PATCH', 'DELETE'], true)
            || stripos(ltrim($contentType), 'multipart/') !== 0) {
            return Request::capture();
        }

        Request::enableHttpMethodParameterOverride();

        return Request::createFromBase(new SymfonyRequest($_GET, [], [], $_COOKIE, [], $_SERVER));
    }
}
