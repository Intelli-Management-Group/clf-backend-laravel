<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A request body could not be read (malformed JSON or multipart data, or a
 * body over the size limit). Rendered as a 500 by the exception handler,
 * like the original Express error middleware did.
 */
class RequestBodyException extends RuntimeException
{
    public static function tooLarge(): self
    {
        return new static('request entity too large');
    }
}
