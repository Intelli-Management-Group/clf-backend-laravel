<?php

namespace App\Http\Middleware;

use App\Exceptions\RequestBodyException;
use App\Http\RequestBody;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\ParameterBag;

/**
 * Parses JSON and urlencoded bodies like `express.json()` and
 * `express.urlencoded({ extended: true })` with their 10mb limit.
 *
 * Bodies are read from the raw input so parsing behaves the same whether
 * or not PHP populated $_POST (see enable_post_data_reading in the README).
 */
class ParseRequestBody
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
        if (RequestBody::present($request)) {
            $type = RequestBody::mediaType($request);

            if ($type === 'application/json') {
                $this->parseJson($request);
            } elseif ($type === 'application/x-www-form-urlencoded') {
                $this->parseUrlEncoded($request);
            }
        }

        return $next($request);
    }

    private function parseJson(Request $request)
    {
        $content = $this->read($request);
        $data = $content === '' ? [] : $this->decode($content);

        $request->setJson(new ParameterBag($data));
        RequestBody::set($request, $data);
    }

    private function parseUrlEncoded(Request $request)
    {
        parse_str($this->read($request), $data);

        $request->request->replace($data);
        RequestBody::set($request, $data);
    }

    /**
     * Decode a JSON body in body-parser's strict mode (objects and arrays only).
     */
    private function decode(string $content): array
    {
        $trimmed = ltrim($content, " \t\n\r");
        $first = substr($trimmed, 0, 1);

        if ($first !== '{' && $first !== '[') {
            $snippet = strlen($trimmed) > 10 ? substr($trimmed, 0, 10).'...' : $trimmed;
            throw new RequestBodyException(sprintf('Unexpected token \'%s\', "%s" is not valid JSON', $first, $snippet));
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RequestBodyException(json_last_error_msg().' in JSON body');
        }

        return $data;
    }

    private function read(Request $request): string
    {
        $limit = config('clf.body_limit');

        if ((int) $request->headers->get('Content-Length') > $limit) {
            throw RequestBodyException::tooLarge();
        }

        $content = (string) $request->getContent();

        if (strlen($content) > $limit) {
            throw RequestBodyException::tooLarge();
        }

        return $content;
    }
}
