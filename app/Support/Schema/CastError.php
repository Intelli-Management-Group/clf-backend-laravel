<?php

namespace App\Support\Schema;

use App\Support\Js;
use RuntimeException;

/**
 * Raised when a request value cannot be cast to a field's type.
 *
 * The message format is identical to Mongoose's CastError, which the
 * original API returned to clients in its `error` property.
 */
class CastError extends RuntimeException
{
    /**
     * @var string
     */
    protected $path;

    public static function make(string $kind, $value, string $path, string $model): self
    {
        $message = sprintf(
            'Cast to %s failed for value %s (type %s) at path "%s" for model "%s"',
            $kind,
            self::stringValue($value),
            Js::typeName($value),
            $path,
            $model
        );

        // Booleans are cast through a nested CastError, which Mongoose reports as the reason.
        if ($kind === 'Boolean') {
            $message .= ' because of "CastError"';
        }

        $error = new static($message);
        $error->path = $path;

        return $error;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Mongoose wraps util.inspect() output in double quotes.
     */
    private static function stringValue($value): string
    {
        $string = preg_replace('/^\'|\'$/', '"', Js::inspect($value));

        return strpos($string, '"') === 0 ? $string : '"'.$string.'"';
    }
}
