<?php

namespace App\Models;

use App\Support\Schema\Schema;

/**
 * News article.
 *
 * @property string $id
 * @property string $title
 * @property string $description
 * @property \Carbon\Carbon $date
 * @property string $slug
 * @property bool|null $is_active
 */
class News extends Model
{
    protected $table = 'news';

    protected $fillable = ['title', 'description', 'date', 'slug', 'is_active'];

    protected $dates = ['date'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static $columns = [
        'isActive' => 'is_active',
    ];

    public static function schema(): Schema
    {
        return new Schema('News', [
            'title' => ['type' => Schema::STRING, 'required' => true],
            'description' => ['type' => Schema::STRING, 'required' => true],
            'date' => ['type' => Schema::DATE, 'required' => true],
            'slug' => ['type' => Schema::STRING, 'required' => true],
            'isActive' => ['type' => Schema::BOOLEAN, 'default' => true],
        ]);
    }
}
