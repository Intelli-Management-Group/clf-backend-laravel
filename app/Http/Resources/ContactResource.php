<?php

namespace App\Http\Resources;

/**
 * @mixin \App\Models\Contact
 */
class ContactResource extends DocumentResource
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
            'email' => $this->email,
            'subject' => $this->subject,
            'message' => $this->message,
            'createdAt' => $this->iso($this->created_at),
            '__v' => self::VERSION,
        ];
    }
}
