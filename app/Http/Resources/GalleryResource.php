<?php

namespace App\Http\Resources;

/**
 * Full gallery document.
 *
 * `year`, `subTitle` and `catalogThumbnail` are optional in the schema;
 * like MongoDB, documents without them simply omit the keys.
 *
 * @mixin \App\Models\Gallery
 */
class GalleryResource extends DocumentResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return $this->content() + [
            'createdAt' => $this->iso($this->created_at),
            'updatedAt' => $this->iso($this->updated_at),
            '__v' => self::VERSION,
        ];
    }

    /**
     * The gallery fields shared by the full and the grouped-by-year output.
     */
    protected function content(): array
    {
        $data = [
            '_id' => $this->id,
            'title' => $this->title,
        ];

        if ($this->year !== null) {
            $data['year'] = $this->year;
        }

        if ($this->sub_title !== null) {
            $data['subTitle'] = $this->sub_title;
        }

        if ($this->catalog_thumbnail !== null) {
            $data['catalogThumbnail'] = $this->catalog_thumbnail;
        }

        $data['photos'] = $this->resource->photos->map(function ($photo) {
            return ['src' => $photo->src, '_id' => $photo->id];
        })->all();

        return $data;
    }
}
