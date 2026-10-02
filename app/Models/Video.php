<?php

namespace App\Models;

use App\Support\Schema\Schema;

/**
 * Gallery video.
 *
 * @property string $id
 * @property string|null $title
 * @property string|null $video_url
 * @property string|null $catalog_thumbnail
 */
class Video extends Model
{
    protected $table = 'videos';

    protected $fillable = ['title', 'video_url', 'catalog_thumbnail'];

    protected static $columns = [
        'videoUrl' => 'video_url',
        'catalogThumbnail' => 'catalog_thumbnail',
    ];

    public static function schema(): Schema
    {
        return new Schema('Video', [
            'title' => ['type' => Schema::STRING, 'required' => true],
            'videoUrl' => ['type' => Schema::STRING, 'required' => true],
            'catalogThumbnail' => ['type' => Schema::STRING, 'required' => true],
        ]);
    }
}
