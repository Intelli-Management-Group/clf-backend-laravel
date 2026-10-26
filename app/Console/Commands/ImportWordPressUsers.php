<?php

namespace App\Console\Commands;

use App\Services\WordPressUserImporter;
use Illuminate\Console\Command;
use RuntimeException;

class ImportWordPressUsers extends Command
{
    protected $signature = 'clf:import-wordpress-users
                            {usersFile : Path to the clf_users.sql dump}
                            {--usermeta-file= : Path to clf_usermeta.sql, used to select administrator accounts}
                            {--admin-ids= : Comma-separated WordPress user IDs to import instead of usermeta}
                            {--dry-run : Validate and count selected accounts without writing}';

    protected $description = 'Import only WordPress administrators into Laravel admins';

    public function handle(WordPressUserImporter $importer): int
    {
        try {
            $count = $importer->import(
                $this->argument('usersFile'),
                $this->option('usermeta-file'),
                $this->option('admin-ids'),
                (bool) $this->option('dry-run')
            );
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%s %d WordPress administrator account(s).',
            $this->option('dry-run') ? 'Validated' : 'Imported',
            $count
        ));

        return self::SUCCESS;
    }
}
