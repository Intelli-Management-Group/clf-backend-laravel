<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;

/**
 * `php artisan serve`, started with enable_post_data_reading=0.
 *
 * The gallery endpoints parse multipart bodies themselves (to support
 * repeated `photos` fields, PUT uploads and unlimited file counts), which
 * requires PHP to leave the raw request body unread. Apache/FPM get the
 * same setting from public/.htaccess and public/.user.ini.
 */
class ServeCommand extends BaseServeCommand
{
    /**
     * Get the full server command.
     *
     * @return array
     */
    protected function serverCommand()
    {
        $command = parent::serverCommand();
        array_splice($command, 1, 0, ['-d', 'enable_post_data_reading=0']);

        return $command;
    }
}
