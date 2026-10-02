<?php

namespace App\Http\Resources;

use App\Support\JsDate;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base resource producing the document shape the API has always returned:
 * `_id`, camelCase fields, ISO-8601 dates with milliseconds and `__v`.
 */
abstract class DocumentResource extends JsonResource
{
    /**
     * Mongoose's version key. Documents written through this API were never
     * re-saved, so it was always 0; it is kept for response compatibility.
     */
    const VERSION = 0;

    /**
     * Format a date like JavaScript's Date#toISOString().
     *
     * @param  \DateTimeInterface|null  $date
     * @return string|null
     */
    protected function iso($date)
    {
        return JsDate::toIsoString($date);
    }

    /**
     * Resolve a list of models into plain arrays.
     *
     * @param  iterable  $models
     */
    public static function many($models): array
    {
        $items = [];
        foreach ($models as $model) {
            $items[] = (new static($model))->resolve();
        }

        return $items;
    }
}
