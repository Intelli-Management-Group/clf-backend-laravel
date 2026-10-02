<?php

namespace App\Http\Resources;

/**
 * @mixin \App\Models\News
 */
class NewsResource extends DocumentResource
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
            'description' => $this->description,
            'date' => $this->iso($this->date),
            'slug' => $this->slug,
            'isActive' => $this->is_active,
            'createdAt' => $this->iso($this->created_at),
            'updatedAt' => $this->iso($this->updated_at),
            '__v' => self::VERSION,
        ];
    }
}
