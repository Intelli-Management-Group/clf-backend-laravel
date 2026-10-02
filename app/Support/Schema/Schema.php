<?php

namespace App\Support\Schema;

use App\Support\Js;
use App\Support\JsDate;
use App\Support\ObjectId;
use Carbon\Carbon;

/**
 * Field definitions with the casting and validation rules of the original
 * Mongoose schemas.
 *
 * The API exposed Mongoose's behaviour directly (type coercion such as
 * "5" => 5 or "no" => false, trimming, defaults, and the exact validation
 * error messages), so models describe their fields here and controllers run
 * request values through forCreate()/forUpdate() before persisting them.
 *
 * Field options: type, required, trim, lowercase, enum, default.
 */
final class Schema
{
    const STRING = 'string';
    const NUMBER = 'number';
    const BOOLEAN = 'boolean';
    const DATE = 'date';
    const OBJECT_ID = 'objectId';

    /**
     * @var string
     */
    private $model;

    /**
     * @var array
     */
    private $fields;

    public function __construct(string $model, array $fields)
    {
        $this->model = $model;
        $this->fields = $fields;
    }

    /**
     * Cast, default and validate the values of a new document (Model#save()).
     *
     * Keys absent from $values are treated as `undefined`; null is kept as null.
     *
     * @throws ValidationError
     */
    public function forCreate(array $values): array
    {
        $document = [];
        $castErrors = [];

        foreach ($this->fields as $path => $field) {
            if (array_key_exists($path, $values)) {
                try {
                    $document[$path] = $this->cast($path, $values[$path]);
                } catch (CastError $error) {
                    $castErrors[$path] = $error->getMessage();
                }
            } elseif (array_key_exists('default', $field)) {
                $document[$path] = $field['default'];
            }
        }

        $validationErrors = [];
        foreach ($this->fields as $path => $field) {
            if (isset($castErrors[$path])) {
                continue;
            }

            $message = $this->validate($path, array_key_exists($path, $document) ? $document[$path] : null);
            if ($message !== null) {
                $validationErrors[$path] = $message;
            }
        }

        $errors = $castErrors + $validationErrors;
        if ($errors) {
            throw new ValidationError($this->model.' validation failed', $errors);
        }

        return $document;
    }

    /**
     * Cast (and optionally validate) the values of an update
     * (findByIdAndUpdate with or without `runValidators`).
     *
     * Values are processed in the given key order: the first cast failure is
     * thrown directly, and validation errors are reported in reverse order,
     * exactly as Mongoose's update validators do.
     *
     * @throws CastError
     * @throws ValidationError
     */
    public function forUpdate(array $values, bool $runValidators): array
    {
        $changes = [];
        foreach ($values as $path => $value) {
            if (isset($this->fields[$path])) {
                $changes[$path] = $this->cast($path, $value);
            }
        }

        if ($runValidators) {
            $errors = [];
            foreach ($changes as $path => $value) {
                $message = $this->validate($path, $value);
                if ($message !== null) {
                    $errors[$path] = $message;
                }
            }

            if ($errors) {
                throw new ValidationError('Validation failed', array_reverse($errors, true));
            }
        }

        return $changes;
    }

    /**
     * Default values by field (applied by Mongoose to documents missing them).
     */
    public function defaults(): array
    {
        $defaults = [];
        foreach ($this->fields as $path => $field) {
            if (array_key_exists('default', $field)) {
                $defaults[$path] = $field['default'];
            }
        }

        return $defaults;
    }

    /**
     * Cast a single value to the field's type.
     *
     * @throws CastError
     */
    public function cast(string $path, $value)
    {
        $field = $this->fields[$path];

        switch ($field['type']) {
            case self::STRING:
                $value = $this->castString($path, $value);
                if (is_string($value) && ! empty($field['trim'])) {
                    $value = Js::trim($value);
                }
                if (is_string($value) && ! empty($field['lowercase'])) {
                    $value = mb_strtolower($value);
                }

                return $value;

            case self::NUMBER:
                return $this->castNumber($path, $value);

            case self::BOOLEAN:
                return $this->castBoolean($path, $value);

            case self::DATE:
                return $this->castDate($path, $value);

            case self::OBJECT_ID:
                return $value === null ? null : ObjectId::cast($value, $path, $this->model);
        }

        return $value;
    }

    /**
     * Run the field's validators; returns the error message or null.
     */
    private function validate(string $path, $value)
    {
        $field = $this->fields[$path];

        if (! empty($field['required']) && ! $this->isPresent($field['type'], $value)) {
            return 'Path `'.$path.'` is required.';
        }

        if (isset($field['enum']) && $value !== null && ! in_array($value, $field['enum'], true)) {
            return '`'.Js::toString($value).'` is not a valid enum value for path `'.$path.'`.';
        }

        return null;
    }

    /**
     * Mongoose's per-type `checkRequired()`.
     */
    private function isPresent(string $type, $value): bool
    {
        switch ($type) {
            case self::STRING:
                return is_string($value) && $value !== '';
            case self::NUMBER:
                return is_int($value) || is_float($value);
            case self::BOOLEAN:
                return is_bool($value);
            case self::DATE:
                return $value instanceof Carbon;
            default:
                return $value !== null;
        }
    }

    private function castString(string $path, $value)
    {
        if ($value === null || is_string($value)) {
            return $value;
        }

        if (is_array($value)) {
            // Objects carrying a string `_id` cast to that id, everything else fails.
            if (! Js::isList($value) && isset($value['_id']) && is_string($value['_id'])) {
                return $value['_id'];
            }

            throw CastError::make('string', $value, $path, $this->model);
        }

        return Js::toString($value);
    }

    private function castNumber(string $path, $value)
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return null;
        }

        if (is_string($value)) {
            $number = Js::stringToNumber($value);
        } elseif (is_bool($value)) {
            $number = $value ? 1 : 0;
        } elseif (is_int($value) || is_float($value)) {
            $number = $value;
        } else {
            $number = NAN;
        }

        if (is_float($number) && (is_nan($number) || is_infinite($number))) {
            throw CastError::make('Number', $value, $path, $this->model);
        }

        return Js::jsonNumber($number);
    }

    private function castBoolean(string $path, $value)
    {
        if (in_array($value, [true, 'true', 1, 1.0, '1', 'yes'], true)) {
            return true;
        }

        if (in_array($value, [false, 'false', 0, 0.0, '0', 'no'], true)) {
            return false;
        }

        if ($value === null) {
            return null;
        }

        throw CastError::make('Boolean', $value, $path, $this->model);
    }

    private function castDate(string $path, $value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_bool($value)) {
            throw CastError::make('date', $value, $path, $this->model);
        }

        if (is_string($value)) {
            $number = Js::stringToNumber($value);
            if (! is_nan($number) && ($number >= 275761 || $number < -271820)) {
                // Strings holding epoch milliseconds.
                $date = JsDate::fromMilliseconds($number);
            } else {
                $date = JsDate::from($value);
            }
        } else {
            $date = JsDate::from($value);
        }

        if ($date === null) {
            throw CastError::make('date', $value, $path, $this->model);
        }

        return $date;
    }
}
