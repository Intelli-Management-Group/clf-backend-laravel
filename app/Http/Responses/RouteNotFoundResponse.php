<?php

namespace App\Http\Responses;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The 404 page Express sends for unknown routes ("Cannot GET /path").
 */
class RouteNotFoundResponse extends Response
{
    public function __construct(Request $request)
    {
        // The raw path as the application received it (no sub-folder base path, no query string).
        $uri = explode('?', $request->getRequestUri(), 2)[0];
        $path = substr($uri, strlen($request->getBaseUrl())) ?: '/';
        $message = 'Cannot '.$request->getRealMethod().' '.$path;

        parent::__construct(
            "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>Error</title>\n</head>\n"
            ."<body>\n<pre>".$this->escape($message)."</pre>\n</body>\n</html>\n",
            404,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Security-Policy' => "default-src 'none'",
                'X-Content-Type-Options' => 'nosniff',
            ]
        );
    }

    /**
     * escape-html, as used by Express' finalhandler.
     */
    private function escape(string $value): string
    {
        return strtr($value, [
            '&' => '&amp;',
            '<' => '&lt;',
            '>' => '&gt;',
            '"' => '&quot;',
            "'" => '&#39;',
        ]);
    }
}
