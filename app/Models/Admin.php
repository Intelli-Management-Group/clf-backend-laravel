<?php

namespace App\Models;

use App\Support\Schema\Schema;

/**
 * Administrator account used by the admin panel login.
 *
 * Passwords are stored as bcrypt hashes (the Node.js version stored them in
 * plain text); login behaviour is otherwise unchanged.
 *
 * @property string $id
 * @property string $username
 * @property string $email
 * @property string $password
 * @property string $role
 * @property bool|null $is_active
 */
class Admin extends Model
{
    protected $table = 'admins';

    protected $fillable = ['username', 'email', 'password', 'role', 'is_active'];

    protected $hidden = ['password'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static $columns = [
        'isActive' => 'is_active',
    ];

    public static function schema(): Schema
    {
        return new Schema('Admin', [
            'username' => ['type' => Schema::STRING, 'required' => true, 'trim' => true],
            'email' => ['type' => Schema::STRING, 'required' => true, 'lowercase' => true],
            'password' => ['type' => Schema::STRING, 'required' => true],
            'role' => ['type' => Schema::STRING, 'default' => 'admin'],
            'isActive' => ['type' => Schema::BOOLEAN, 'default' => true],
        ]);
    }
}
