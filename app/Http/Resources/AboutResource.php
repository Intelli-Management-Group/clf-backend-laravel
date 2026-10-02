<?php

namespace App\Http\Resources;

use App\Support\Js;

/**
 * @mixin \App\Models\About
 */
class AboutResource extends DocumentResource
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
            'name' => $this->name,
            'title' => $this->title,
            'category' => $this->category,
            'description' => $this->description,
            'parent_id' => $this->parentValue(),
            'order' => Js::jsonNumber($this->order),
            'isActive' => $this->is_active,
            'createdAt' => $this->iso($this->created_at),
            'updatedAt' => $this->iso($this->updated_at),
            '__v' => self::VERSION,
        ];
    }

    /**
     * The populated parent ({ _id, name, title }) when the relation is
     * loaded, otherwise the raw parent id.
     */
    private function parentValue()
    {
        if (! $this->resource->relationLoaded('parent')) {
            return $this->parent_id;
        }

        $parent = $this->resource->parent;

        return $parent ? [
            '_id' => $parent->id,
            'name' => $parent->name,
            'title' => $parent->title,
        ] : null;
    }
}
