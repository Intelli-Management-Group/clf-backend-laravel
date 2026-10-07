<?php

namespace Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class NewsSeeder extends Seeder
{
    /**
     * Seeds old WordPress news posts from database/seeders/data/news.json.
     * Uses slug as the unique key, so running it twice won't create duplicates.
     */
    public function run(): void
    {
        $items = json_decode(
            file_get_contents(database_path('seeders/news.json')),
            true
        );

        foreach ($items as $item) {
            DB::table('news')->updateOrInsert(
                ['slug' => $item['slug']],
                [
                    'id'          => $item['id'],
                    'title'       => $item['title'],
                    'description' => $item['description'],
                    'date'        => Carbon::parse($item['date'])->format('Y-m-d H:i:s'),
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]
            );
        }

        $this->command->info(count($items) . ' news items seeded.');
    }
}
