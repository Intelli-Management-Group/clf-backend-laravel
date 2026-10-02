<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a controller needs the request body but none was sent.
 *
 * The Node.js controllers destructured `req.body`, which threw a TypeError
 * when no body parser had run; their catch blocks returned that message.
 * The text is kept identical so clients see the same response.
 */
class MissingRequestBodyException extends RuntimeException
{
    public function __construct(string $field)
    {
        parent::__construct("Cannot destructure property '{$field}' of 'req.body' as it is undefined.");
    }
}
