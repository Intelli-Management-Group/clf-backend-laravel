<?php

namespace App\Console\Commands;

use App\Services\MongoImporter;
use Illuminate\Console\Command;

/**
 * Moves the data of the former MongoDB database into MySQL.
 *
 * Export each collection first (one file per collection), e.g.
 *   mongoexport --uri="<MONGODB_URI>" --collection=news --jsonArray --out=news.json
 */
class ImportMongoData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clf:import-mongo
                            {directory : Folder containing admins.json, abouts.json, news.json, galleries.json, videos.json, contacts.json}
                            {--fresh : Delete all existing rows before importing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Import MongoDB collection exports into MySQL, keeping every document id';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(MongoImporter $importer)
    {
        $directory = rtrim($this->argument('directory'), '/\\');

        if (! is_dir($directory)) {
            $this->error("Directory not found: {$directory}");

            return 1;
        }

        if ($this->option('fresh')) {
            $importer->truncate();
            $this->warn('Existing rows deleted.');
        }

        foreach (array_keys(MongoImporter::COLLECTIONS) as $collection) {
            $file = $directory.DIRECTORY_SEPARATOR.$collection.'.json';

            if (! is_file($file)) {
                $this->line("<comment>Skipped</comment> {$collection} (no {$collection}.json)");
                continue;
            }

            $count = $importer->import($collection, $file);
            $this->info("Imported {$count} {$collection}");
        }

        foreach ($importer->warnings() as $warning) {
            $this->warn($warning);
        }

        $this->line('Done. Copy the old "uploads" folder into public/uploads so image URLs keep working.');

        return 0;
    }
}
