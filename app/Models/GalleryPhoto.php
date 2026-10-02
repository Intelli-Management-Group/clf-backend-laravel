<?php

namespace App\Models;

use App\Support\Schema\Schema;

/**
 * A photo of a gallery (formerly an embedded `photos` sub-document,
 * which also had its own `_id`).
 *
 * @property string $id
 * @property string $gallery_id
 * @property string $src
 * @property int $position
 */
class GalleryPhoto extends Model
{
    public $timestamps = false;

    protected $table = 'gallery_photos';

    protected $fillable = ['gallery_id', 'src', 'position'];

    protected $casts = [
        'position' => 'integer',
    ];

    public static function schema(): Schema
    {
        return new Schema('Gallery', [
            'src' => ['type' => Schema::STRING, 'required' => true],
        ]);
    }

    public function gallery()
    {
        return $this->belongsTo(Gallery::class);
    }
}
