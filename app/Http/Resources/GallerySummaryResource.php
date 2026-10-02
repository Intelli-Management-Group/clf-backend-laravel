<?php

namespace App\Http\Resources;

/**
 * Gallery entry of the "list-by-year" response (no timestamps).
 *
 * @mixin \App\Models\Gallery
 */
class GallerySummaryResource extends GalleryResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array
     */
    public function toArray($request)
    {
        return $this->content();
    }
}
