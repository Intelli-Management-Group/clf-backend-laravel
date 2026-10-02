<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Uploads
    |--------------------------------------------------------------------------
    |
    | Gallery images are stored in public/uploads and referenced by the
    | relative URL "/uploads/<file>", exactly like the Node.js backend.
    |
    */

    'uploads' => [
        'directory' => public_path('uploads'),
        'url' => '/uploads',
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Body Limits
    |--------------------------------------------------------------------------
    |
    | JSON and urlencoded bodies are limited to 10mb (the express.json and
    | express.urlencoded "limit"). Multipart text fields are limited to 1mb
    | each (Multer's default fieldSize); uploaded files are not limited.
    |
    */

    'body_limit' => 10 * 1024 * 1024,

    'multipart' => [
        'field_size_limit' => 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Server Timezone
    |--------------------------------------------------------------------------
    |
    | Dates are stored and returned in UTC. Date strings without an explicit
    | offset that are not plain "YYYY-MM-DD" dates (e.g. "2025-12-17T10:00" or
    | "12/17/2025") are read in this timezone, mirroring how the Node.js
    | server interpreted them in its own local timezone.
    |
    */

    'server_timezone' => env('SERVER_TIMEZONE', 'UTC'),

];
