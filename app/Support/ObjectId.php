<?php

namespace App\Support;

use App\Support\Schema\CastError;

/**
 * MongoDB-compatible ObjectId helper.
 *
 * Primary keys are 24 character hex strings laid out exactly like MongoDB
 * ObjectIds (4-byte timestamp, 5-byte per-process random value, 3-byte
 * counter). This keeps every `_id` the API returns in the same format as
 * before the migration and lets existing MongoDB documents be imported
 * with their original ids.
 */
final class ObjectId
{
    /**
     * @var string|null
     */
    private static $processUnique;

    /**
     * @var int
     */
    private static $counter = 0;

    /**
     * Generate a new ObjectId string.
     */
    public static function generate(): string
    {
        if (self::$processUnique === null) {
            self::$processUnique = random_bytes(5);
            self::$counter = random_int(0, 0xFFFFFF);
        }

        self::$counter = (self::$counter + 1) % 0x1000000;

        return bin2hex(
            pack('N', time()).self::$processUnique.substr(pack('N', self::$counter), 1)
        );
    }

    /**
     * Equivalent of `mongoose.Types.ObjectId.isValid()` for request values.
     */
    public static function isValid($value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-fA-F]{24}$/', $value) === 1;
    }

    /**
     * Cast a value to a normalized (lower-case) ObjectId string the way
     * Mongoose casts query values, throwing the same CastError otherwise.
     *
     * @throws CastError
     */
    public static function cast($value, string $path, string $model): string
    {
        if (! self::isValid($value)) {
            throw CastError::make('ObjectId', $value, $path, $model);
        }

        return strtolower($value);
    }
}
