<?php

namespace App\Models;

use App\Models\Concerns\HasObjectId;
use App\Support\ObjectId;
use App\Support\Schema\Schema;
use Illuminate\Database\Eloquent\Model as Eloquent;

/**
 * Base model for every CLF document.
 *
 * - ObjectId primary keys, exposed as `_id` by the API resources.
 * - Millisecond precision timestamps (DATETIME(3)) like MongoDB dates.
 * - A field map translating the API's camelCase names to snake_case columns.
 */
abstract class Model extends Eloquent
{
    use HasObjectId;

    /**
     * Store dates with milliseconds (".v"); Laravel reads them back with ".u".
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    /**
     * API field name => database column, for names that differ.
     *
     * @var array
     */
    protected static $columns = [];

    /**
     * The Mongoose-compatible schema describing this model's API fields.
     */
    abstract public static function schema(): Schema;

    /**
     * Name used in error messages ("... for model "News"").
     */
    public static function documentName(): string
    {
        return class_basename(static::class);
    }

    /**
     * Fill the model from API field names (as returned by the schema).
     *
     * @return $this
     */
    public function fillFromApi(array $values)
    {
        foreach ($values as $field => $value) {
            $this->setAttribute(static::column($field), $value);
        }

        return $this;
    }

    /**
     * Translate an API field name to its column.
     */
    public static function column(string $field): string
    {
        return isset(static::$columns[$field]) ? static::$columns[$field] : $field;
    }

    /**
     * Mongoose's `Model.findById()`: casts the id (throwing the same
     * CastError for malformed ids) and returns the model or null.
     *
     * @return static|null
     */
    public static function findById($id, string $path = '_id')
    {
        return static::query()->find(ObjectId::cast($id, $path, static::documentName()));
    }

    /**
     * Cast a value to an ObjectId for this model (path defaults to `_id`).
     */
    public static function castId($id, string $path = '_id'): string
    {
        return ObjectId::cast($id, $path, static::documentName());
    }
}
