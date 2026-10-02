<?php

namespace App\Http\Controllers;

use App\Exceptions\MissingRequestBodyException;
use App\Http\RequestBody;
use App\Support\DuplicateKeyMessage;
use App\Support\Schema\CastError;
use App\Support\Schema\ValidationError;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;

class Controller extends BaseController
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    /**
     * JSON encoding matching JSON.stringify (no escaped slashes / unicode).
     */
    const JSON_OPTIONS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Send a JSON response.
     */
    protected function respond(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status, ['Content-Type' => 'application/json; charset=utf-8'], self::JSON_OPTIONS);
    }

    /**
     * The parsed request body (Express `req.body`).
     *
     * @param  string  $firstField  the first property the original handler destructured
     *
     * @throws MissingRequestBodyException when the request had no parsable body
     */
    protected function body(Request $request, string $firstField): array
    {
        $body = RequestBody::get($request);

        if ($body === null) {
            throw new MissingRequestBodyException($firstField);
        }

        return $body;
    }

    /**
     * Read a body field; absent keys are `undefined` and return null.
     */
    protected function field(array $body, string $key)
    {
        return array_key_exists($key, $body) ? $body[$key] : null;
    }

    /**
     * The subset of $keys present in the body, in the order of $keys.
     */
    protected function present(array $body, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $body)) {
                $values[$key] = $body[$key];
            }
        }

        return $values;
    }

    /**
     * The text returned in the `error` property for a failure. Unexpected
     * errors are also written to the log.
     */
    protected function errorMessage(\Throwable $exception, bool $fromUpdate = false): string
    {
        if (DuplicateKeyMessage::matches($exception)) {
            return DuplicateKeyMessage::from($exception, $fromUpdate);
        }

        if (! $exception instanceof CastError
            && ! $exception instanceof ValidationError
            && ! $exception instanceof MissingRequestBodyException) {
            report($exception);
        }

        return $exception->getMessage();
    }
}
