<?php

namespace App\Models;

use App\Support\Schema\Schema;

/**
 * Message submitted through the public contact form.
 *
 * Like the original schema it only records `createdAt`.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $subject
 * @property string $message
 */
class Contact extends Model
{
    const UPDATED_AT = null;

    protected $table = 'contacts';

    protected $fillable = ['name', 'email', 'subject', 'message'];

    public static function schema(): Schema
    {
        return new Schema('Contact', [
            'name' => ['type' => Schema::STRING, 'required' => true],
            'email' => ['type' => Schema::STRING, 'required' => true],
            'subject' => ['type' => Schema::STRING, 'required' => true],
            'message' => ['type' => Schema::STRING, 'required' => true],
        ]);
    }
}
