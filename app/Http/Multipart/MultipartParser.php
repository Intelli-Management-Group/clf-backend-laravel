<?php

namespace App\Http\Multipart;

use App\Exceptions\RequestBodyException;
use Illuminate\Http\UploadedFile;

/**
 * Streaming multipart/form-data parser with Multer (`upload.any()`) semantics.
 *
 * PHP's own parser cannot serve the gallery endpoints the way Multer did:
 * it keeps only the last file of a repeated field name without "[]" (the
 * admin panel sends several `photos` files), stops at `max_file_uploads`,
 * and never parses PUT bodies. This parser reads the raw body instead and
 * streams file contents straight to temporary files.
 */
final class MultipartParser
{
    const CHUNK_SIZE = 65536;

    /**
     * busboy's per-part header size limit.
     */
    const MAX_HEADER_SIZE = 16384;

    /**
     * @var resource
     */
    private $stream;

    /**
     * @var string
     */
    private $buffer = '';

    /**
     * @var bool
     */
    private $eof = false;

    /**
     * @var int
     */
    private $fieldSizeLimit;

    /**
     * Temporary files created while parsing (removed at shutdown if unused).
     *
     * @var array
     */
    private static $temporaryFiles = [];

    public function __construct(int $fieldSizeLimit = 1048576)
    {
        $this->fieldSizeLimit = $fieldSizeLimit;
    }

    /**
     * Parse a multipart body.
     *
     * Returns [fields, files]: text fields keyed like Multer's `req.body`
     * (repeated names become arrays) and files grouped by their exact field
     * name, in the order they were sent.
     *
     * @param  resource  $stream
     * @return array
     *
     * @throws RequestBodyException
     */
    public function parse($stream, string $boundary, string $initial = ''): array
    {
        $this->stream = $stream;
        // A leading CRLF lets the first boundary match the same delimiter as the others.
        $this->buffer = "\r\n".$initial;
        $this->eof = false;

        $delimiter = "\r\n--".$boundary;
        $fields = [];
        $files = [];

        if (! $this->discardThrough($delimiter)) {
            throw new RequestBodyException('Unexpected end of form');
        }

        while (true) {
            $this->fillTo(2);
            if (strlen($this->buffer) < 2) {
                throw new RequestBodyException('Unexpected end of form');
            }

            if (strncmp($this->buffer, '--', 2) === 0) {
                break;
            }

            // Rest of the boundary line (optional transport padding).
            if ($this->readThrough("\r\n", 1024) === null) {
                throw new RequestBodyException('Unexpected end of form');
            }

            $this->fillTo(2);
            $headerBlock = strncmp($this->buffer, "\r\n", 2) === 0
                ? $this->readThrough("\r\n", 0)
                : $this->readThrough("\r\n\r\n", self::MAX_HEADER_SIZE);

            if ($headerBlock === null) {
                throw new RequestBodyException('Unexpected end of form');
            }

            $headers = $this->parseHeaders($headerBlock);
            list($disposition, $params) = $this->parseDisposition(
                isset($headers['content-disposition']) ? $headers['content-disposition'] : ''
            );

            if ($disposition !== 'form-data' || ! isset($params['name'])) {
                if (! $this->discardThrough($delimiter)) {
                    throw new RequestBodyException('Unexpected end of form');
                }
                continue;
            }

            $name = $params['name'];
            $filename = $this->filename($params);

            if ($filename === null) {
                $value = $this->readThrough($delimiter, $this->fieldSizeLimit, 'Field value too long');
                if ($value === null) {
                    throw new RequestBodyException('Unexpected end of form');
                }
                $this->appendField($fields, $name, $value);
                continue;
            }

            $path = $this->temporaryFile();
            $handle = fopen($path, 'wb');
            $complete = $this->copyThrough($delimiter, $handle);
            fclose($handle);

            if (! $complete) {
                throw new RequestBodyException('Unexpected end of form');
            }

            $mimeType = isset($headers['content-type']) ? $headers['content-type'] : 'application/octet-stream';
            $files[$name][] = new UploadedFile($path, $filename, $mimeType, UPLOAD_ERR_OK, true);
        }

        return [$fields, $files];
    }

    /**
     * Extract the boundary parameter of a multipart Content-Type header.
     *
     * @return string|null
     */
    public static function boundary(string $contentType)
    {
        if (preg_match('/;\s*boundary=(?:"([^"]+)"|([^;\s]+))/i', $contentType, $m)) {
            return $m[1] !== '' ? $m[1] : $m[2];
        }

        return null;
    }

    /**
     * Remove temporary files that were not moved into the uploads directory.
     */
    public static function cleanup()
    {
        foreach (self::$temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        self::$temporaryFiles = [];
    }

    private function temporaryFile(): string
    {
        if (! self::$temporaryFiles) {
            register_shutdown_function([self::class, 'cleanup']);
        }

        $path = tempnam(sys_get_temp_dir(), 'clf');
        self::$temporaryFiles[] = $path;

        return $path;
    }

    private function fill(): bool
    {
        if ($this->eof) {
            return false;
        }

        $chunk = fread($this->stream, self::CHUNK_SIZE);
        if ($chunk === false || $chunk === '') {
            $this->eof = true;

            return false;
        }

        $this->buffer .= $chunk;

        return true;
    }

    private function fillTo(int $length)
    {
        while (strlen($this->buffer) < $length && $this->fill()) {
            //
        }
    }

    /**
     * Consume the buffer through $needle, returning what preceded it
     * (null when the stream ends first).
     *
     * @return string|null
     *
     * @throws RequestBodyException when more than $limit bytes precede the needle
     */
    private function readThrough(string $needle, int $limit, string $error = 'Malformed part header')
    {
        $offset = 0;

        while (($position = strpos($this->buffer, $needle, $offset)) === false) {
            if (strlen($this->buffer) > $limit + strlen($needle)) {
                throw new RequestBodyException($error);
            }

            $offset = max(0, strlen($this->buffer) - strlen($needle) + 1);
            if (! $this->fill()) {
                return null;
            }
        }

        if ($position > $limit) {
            throw new RequestBodyException($error);
        }

        $data = substr($this->buffer, 0, $position);
        $this->buffer = (string) substr($this->buffer, $position + strlen($needle));

        return $data;
    }

    /**
     * Stream everything before $needle into $handle and consume the needle.
     *
     * @param  resource|null  $handle
     */
    private function copyThrough(string $needle, $handle): bool
    {
        $keep = strlen($needle) - 1;

        while (($position = strpos($this->buffer, $needle)) === false) {
            if (strlen($this->buffer) > $keep) {
                if ($handle) {
                    fwrite($handle, substr($this->buffer, 0, -$keep));
                }
                $this->buffer = substr($this->buffer, -$keep);
            }

            if (! $this->fill()) {
                return false;
            }
        }

        if ($handle) {
            fwrite($handle, substr($this->buffer, 0, $position));
        }
        $this->buffer = (string) substr($this->buffer, $position + strlen($needle));

        return true;
    }

    private function discardThrough(string $needle): bool
    {
        return $this->copyThrough($needle, null);
    }

    private function parseHeaders(string $block): array
    {
        $headers = [];

        foreach (preg_split('/\r\n(?![ \t])/', $block) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }

            list($name, $value) = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim(preg_replace('/\r\n[ \t]+/', ' ', $value));
        }

        return $headers;
    }

    /**
     * Split a Content-Disposition header into its type and parameters.
     */
    private function parseDisposition(string $header): array
    {
        $parts = explode(';', $header, 2);
        $type = strtolower(trim($parts[0]));
        $params = [];

        if (isset($parts[1])) {
            preg_match_all('/([^=;\s]+)\s*=\s*(?:"((?:[^"\\\\]|\\\\.)*)"|([^;]*))/', $parts[1], $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $key = strtolower($match[1]);
                if (! isset($params[$key])) {
                    // Like busboy, only \\ and \" are escapes; other backslashes are literal.
                    $params[$key] = isset($match[3]) && $match[3] !== ''
                        ? trim($match[3])
                        : preg_replace('/\\\\([\\\\"])/', '$1', $match[2]);
                }
            }
        }

        return [$type, $params];
    }

    /**
     * The uploaded file's base name, or null for a plain text field.
     *
     * @return string|null
     */
    private function filename(array $params)
    {
        if (isset($params['filename*']) && preg_match("/^([^']*)'[^']*'(.*)$/", $params['filename*'], $m)) {
            $name = rawurldecode($m[2]);
            if (strtolower($m[1]) !== 'utf-8') {
                $name = utf8_encode($name);
            }
        } elseif (isset($params['filename'])) {
            $name = $params['filename'];
        } else {
            return null;
        }

        if (! mb_check_encoding($name, 'UTF-8')) {
            $name = utf8_encode($name);
        }

        // busboy keeps only the last path segment of the client file name.
        $slash = max((int) strrpos($name, '/'), (int) strrpos($name, '\\'));
        if ($slash > 0 || (isset($name[0]) && ($name[0] === '/' || $name[0] === '\\'))) {
            $name = substr($name, $slash + 1);
        }

        return $name === '.' || $name === '..' ? '' : $name;
    }

    /**
     * Add a text field like Multer's append-field: repeated names become
     * arrays and bracket paths ("a[b]", "list[]") build nested values.
     */
    private function appendField(array &$fields, string $name, string $value)
    {
        if (! preg_match('/^([^\[\]]+)((?:\[[^\[\]]*\])+)$/', $name, $m)) {
            $this->appendValue($fields, $name, $value, false);

            return;
        }

        preg_match_all('/\[([^\[\]]*)\]/', $m[2], $segments);
        $keys = array_merge([$m[1]], $segments[1]);
        $context = &$fields;

        foreach ($keys as $index => $key) {
            $last = $index === count($keys) - 1;
            $append = isset($keys[$index + 1]) && $keys[$index + 1] === '' && $index + 1 === count($keys) - 1;

            if ($key === '') {
                continue;
            }

            if ($last || $append) {
                $this->appendValue($context, $key, $value, $append);
                break;
            }

            if (! isset($context[$key]) || ! is_array($context[$key])) {
                $context[$key] = [];
            }
            $context = &$context[$key];
        }
    }

    private function appendValue(array &$context, string $key, string $value, bool $forceArray)
    {
        if (! array_key_exists($key, $context)) {
            $context[$key] = $forceArray ? [$value] : $value;
        } elseif (is_array($context[$key])) {
            $context[$key][] = $value;
        } else {
            $context[$key] = [$context[$key], $value];
        }
    }
}
