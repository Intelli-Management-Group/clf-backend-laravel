<?php

namespace App\Exceptions;

use App\Http\Responses\RouteNotFoundResponse;
use Exception;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array
     */
    protected $dontReport = [
        RequestBodyException::class,
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array
     */
    protected $dontFlash = [
        'password',
        'password_confirmation',
    ];

    /**
     * Report or log an exception.
     *
     * @param  \Exception  $exception
     * @return void
     */
    public function report(Exception $exception)
    {
        parent::report($exception);
    }

    /**
     * Render an exception into an HTTP response.
     *
     * Mirrors the Express app: unknown routes (or methods) get Express'
     * "Cannot METHOD /path" 404 page, and any other unhandled error is
     * answered like the error middleware did, with a 500 JSON response.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Exception  $exception
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request, Exception $exception)
    {
        if ($exception instanceof NotFoundHttpException || $exception instanceof MethodNotAllowedHttpException) {
            return new RouteNotFoundResponse($request);
        }

        if ($exception instanceof HttpExceptionInterface) {
            return new JsonResponse(
                ['message' => $exception->getMessage() ?: 'Service Unavailable'],
                $exception->getStatusCode(),
                $exception->getHeaders()
            );
        }

        return new JsonResponse(
            ['message' => 'Something went wrong!', 'error' => $exception->getMessage()],
            500,
            ['Content-Type' => 'application/json; charset=utf-8'],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }
}
