<?php

namespace App\Support;

use Illuminate\Database\QueryException;

/**
 * Formats unique-constraint violations like MongoDB's E11000 errors, which
 * the original API returned in its `error` property.
 */
final class DuplicateKeyMessage
{
    /**
     * Whether the exception is a MySQL duplicate-key error (1062).
     */
    public static function matches(\Throwable $exception): bool
    {
        return $exception instanceof QueryException
            && isset($exception->errorInfo[1])
            && (int) $exception->errorInfo[1] === 1062;
    }

    /**
     * @param  bool  $fromUpdate  findByIdAndUpdate prefixed the message with the plan executor error
     */
    public static function from(QueryException $exception, bool $fromUpdate = false): string
    {
        $detail = isset($exception->errorInfo[2]) ? $exception->errorInfo[2] : $exception->getMessage();
        $value = '';
        $table = '';
        $field = 'key';

        if (preg_match("/Duplicate entry '(.*)' for key '(?:[^.']*\.)?([^']+)'/s", $detail, $m)) {
            $value = $m[1];
            // Laravel index names look like "{table}_{column}_unique".
            if (preg_match('/^([a-z]+)_(.+)_unique$/', $m[2], $index)) {
                $table = $index[1];
                $field = $index[2];
            }
        }

        $message = sprintf(
            'E11000 duplicate key error collection: %s.%s index: %s_1 dup key: { %s: %s }',
            config('database.connections.'.config('database.default').'.database'),
            $table,
            $field,
            $field,
            json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $fromUpdate
            ? 'Plan executor error during findAndModify :: caused by :: '.$message
            : $message;
    }
}
