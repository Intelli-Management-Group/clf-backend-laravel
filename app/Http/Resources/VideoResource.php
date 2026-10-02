<?php

namespace App\Http\Resources;

/**
 * @mixin \App\Models\Video
 */
class VideoResource extends DocumentResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return [
            '_id' => $this->id,
            'title' => $this->title,
            'videoUrl' => $this->video_url,
            'catalogThumbnail' => $this->catalog_thumbnail,
            'createdAt' => $this->iso($this->created_at),
            'updatedAt' => $this->iso($this->updated_at),
            '__v' => self::VERSION,
        ];
    }
}
