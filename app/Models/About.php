<?php

namespace App\Models;

use App\Support\Schema\Schema;

/**
 * "About" page content: styles and biographies, nested one level deep
 * through `parent_id`.
 *
 * @property string $id
 * @property string $name
 * @property string $title
 * @property string $category
 * @property string $description
 * @property string|null $parent_id
 * @property float|null $order
 * @property bool|null $is_active
 */
class About extends Model
{
    const CATEGORIES = ['style', 'biographies'];

    protected $table = 'abouts';

    protected $fillable = ['name', 'title', 'category', 'description', 'parent_id', 'order', 'is_active'];

    protected $casts = [
        'order' => 'float',
        'is_active' => 'boolean',
    ];

    protected static $columns = [
        'isActive' => 'is_active',
    ];

    public static function schema(): Schema
    {
        return new Schema('About', [
            'name' => ['type' => Schema::STRING, 'required' => true, 'trim' => true],
            'title' => ['type' => Schema::STRING, 'required' => true, 'trim' => true],
            'category' => ['type' => Schema::STRING, 'required' => true, 'enum' => self::CATEGORIES, 'default' => 'other'],
            'description' => ['type' => Schema::STRING, 'required' => true],
            'parent_id' => ['type' => Schema::OBJECT_ID, 'default' => null],
            'order' => ['type' => Schema::NUMBER, 'default' => 0],
            'isActive' => ['type' => Schema::BOOLEAN, 'default' => true],
        ]);
    }

    /**
     * The parent item (populated as { _id, name, title } in responses).
     */
    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
