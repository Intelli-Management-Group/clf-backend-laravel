<?php

namespace App\Models;

use App\Support\Schema\Schema;

/**
 * Photo gallery (catalog) with an ordered list of photos.
 *
 * MongoDB embedded the photos array in the gallery document; here each
 * photo is a row in `gallery_photos`, ordered by `position`.
 *
 * @property string $id
 * @property string|null $title
 * @property string|null $year
 * @property string|null $sub_title
 * @property string|null $catalog_thumbnail
 */
class Gallery extends Model
{
    protected $table = 'galleries';

    protected $fillable = ['title', 'year', 'sub_title', 'catalog_thumbnail'];

    protected static $columns = [
        'subTitle' => 'sub_title',
        'catalogThumbnail' => 'catalog_thumbnail',
    ];

    public static function schema(): Schema
    {
        return new Schema('Gallery', [
            'title' => ['type' => Schema::STRING, 'required' => true],
            'year' => ['type' => Schema::STRING],
            'subTitle' => ['type' => Schema::STRING],
            'catalogThumbnail' => ['type' => Schema::STRING],
        ]);
    }

    public function photos()
    {
        return $this->hasMany(GalleryPhoto::class)->orderBy('position');
    }

    /**
     * Replace the gallery's photos with the given image URLs, in order.
     */
    public function replacePhotos(array $sources)
    {
        $this->photos()->delete();

        foreach (array_values($sources) as $position => $src) {
            $this->photos()->create(['src' => $src, 'position' => $position]);
        }

        $this->unsetRelation('photos');
    }
}
