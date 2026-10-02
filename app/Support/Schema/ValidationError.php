<?php

namespace App\Support\Schema;

use RuntimeException;

/**
 * Raised when one or more fields fail validation.
 *
 * Messages follow Mongoose's format, e.g.
 * "News validation failed: title: Path `title` is required."
 */
class ValidationError extends RuntimeException
{
    /**
     * @var array path => message
     */
    protected $errors = [];

    public function __construct(string $prefix, array $errors)
    {
        $this->errors = $errors;

        $details = [];
        foreach ($errors as $path => $message) {
            $details[] = $path.': '.$message;
        }

        parent::__construct($prefix.': '.implode(', ', $details));
    }

    public function errors(): array
    {
        return $this->errors;
    }
}
