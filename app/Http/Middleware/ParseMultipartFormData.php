<?php

namespace App\Http\Middleware;

use App\Exceptions\RequestBodyException;
use App\Http\Multipart\MultipartParser;
use App\Http\RequestBody;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Route middleware equivalent to Multer's `upload.any()`.
 *
 * Text fields become the request body and every uploaded file is exposed in
 * `$request->files` under its exact field name as a list, in upload order.
 */
class ParseMultipartFormData
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        if (! RequestBody::present($request) || strpos(RequestBody::mediaType($request), 'multipart/') !== 0) {
            return $next($request);
        }

        if (RequestBody::mediaType($request) !== 'multipart/form-data') {
            throw new RequestBodyException('Unsupported content type: '.RequestBody::mediaType($request));
        }

        $boundary = MultipartParser::boundary((string) $request->headers->get('Content-Type'));
        if ($boundary === null) {
            throw new RequestBodyException('Multipart: Boundary not found');
        }

        $stream = $request->getContent(true);
        $first = (string) fread($stream, 8192);

        if ($first === '' && $this->parsedByPhp($request)) {
            // PHP already consumed the body (enable_post_data_reading=On).
            $fields = $request->request->all();
            $files = $this->normalizePhpFiles($request->files->all());
        } else {
            $parser = new MultipartParser(config('clf.multipart.field_size_limit'));
            list($fields, $files) = $parser->parse($stream, $boundary, $first);
        }

        $request->request->replace($fields);
        $request->files->replace($files);
        RequestBody::set($request, $fields);

        return $next($request);
    }

    private function parsedByPhp(Request $request): bool
    {
        return $request->files->count() > 0 || $request->request->count() > 0;
    }

    /**
     * Flatten PHP's $_FILES structure into field name => list of files.
     */
    private function normalizePhpFiles(array $files): array
    {
        $normalized = [];

        foreach ($files as $field => $value) {
            if ($value instanceof UploadedFile) {
                $normalized[$field][] = $value;
            } elseif (is_array($value)) {
                array_walk_recursive($value, function ($file) use (&$normalized, $field) {
                    if ($file instanceof UploadedFile) {
                        $normalized[$field][] = $file;
                    }
                });
            }
        }

        return $normalized;
    }
}
