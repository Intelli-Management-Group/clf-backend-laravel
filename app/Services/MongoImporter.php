<?php

namespace App\Services;

use App\Models\About;
use App\Models\Admin;
use App\Models\Model;
use App\Support\Js;
use App\Support\JsDate;
use App\Support\ObjectId;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Imports MongoDB exports (mongoexport / Compass JSON, relaxed or canonical
 * Extended JSON) into the MySQL tables, keeping every document's `_id`.
 *
 * Existing rows with the same id are updated, so the import can be re-run.
 */
class MongoImporter
{
    /**
     * Collections in import order => model class.
     */
    const COLLECTIONS = [
        'admins' => \App\Models\Admin::class,
        'abouts' => \App\Models\About::class,
        'news' => \App\Models\News::class,
        'galleries' => \App\Models\Gallery::class,
        'videos' => \App\Models\Video::class,
        'contacts' => \App\Models\Contact::class,
    ];

    /**
     * @var array
     */
    private $warnings = [];

    /**
     * Remove all rows from the CLF tables.
     */
    public function truncate()
    {
        DB::transaction(function () {
            DB::table('gallery_photos')->delete();
            DB::table('galleries')->delete();
            DB::table('abouts')->update(['parent_id' => null]);
            foreach (['abouts', 'news', 'videos', 'contacts', 'admins'] as $table) {
                DB::table($table)->delete();
            }
        });
    }

    /**
     * Import one collection export file; returns the number of documents.
     */
    public function import(string $collection, string $file): int
    {
        if (! isset(self::COLLECTIONS[$collection])) {
            throw new RuntimeException("Unknown collection [{$collection}].");
        }

        $documents = array_map([$this, 'decode'], $this->read($file));
        $method = 'import'.ucfirst($collection);

        DB::transaction(function () use ($method, $documents) {
            $this->{$method}($documents);
        });

        return count($documents);
    }

    private function importAdmins(array $documents)
    {
        foreach ($documents as $doc) {
            $doc = $this->withDefaults(Admin::class, $doc);
            $password = (string) $this->value($doc, 'password', '');

            $this->upsert('admins', $doc, [
                'username' => $this->string($doc, 'username'),
                'email' => $this->string($doc, 'email'),
                // Plain-text passwords from the Node.js version are hashed.
                'password' => preg_match('/^\$2[aby]\$\d\d\$/', $password) ? $password : Hash::make($password),
                'role' => $this->string($doc, 'role'),
                'is_active' => $this->boolean($doc, 'isActive'),
            ]);
        }
    }

    private function importAbouts(array $documents)
    {
        $parents = [];

        foreach ($documents as $doc) {
            $doc = $this->withDefaults(About::class, $doc);
            $parents[$this->id($doc)] = $this->value($doc, 'parent_id');

            $this->upsert('abouts', $doc, [
                'name' => $this->string($doc, 'name'),
                'title' => $this->string($doc, 'title'),
                'category' => $this->string($doc, 'category'),
                'description' => $this->string($doc, 'description'),
                'parent_id' => null,
                'order' => $this->value($doc, 'order'),
                'is_active' => $this->boolean($doc, 'isActive'),
            ]);
        }

        // Link parents once every row exists. References to missing documents
        // become null, which is what `populate()` returned for them.
        foreach ($parents as $id => $parentId) {
            if ($parentId !== null && DB::table('abouts')->where('id', $parentId)->exists()) {
                DB::table('abouts')->where('id', $id)->update(['parent_id' => $parentId]);
            }
        }
    }

    private function importNews(array $documents)
    {
        foreach ($documents as $doc) {
            $doc = $this->withDefaults(\App\Models\News::class, $doc);

            $this->upsert('news', $doc, [
                'title' => $this->string($doc, 'title'),
                'description' => $this->string($doc, 'description'),
                'date' => $this->date($this->value($doc, 'date')),
                'slug' => $this->string($doc, 'slug'),
                'is_active' => $this->boolean($doc, 'isActive'),
            ]);
        }
    }

    private function importGalleries(array $documents)
    {
        foreach ($documents as $doc) {
            $id = $this->upsert('galleries', $doc, [
                'title' => $this->string($doc, 'title'),
                'year' => $this->string($doc, 'year'),
                'sub_title' => $this->string($doc, 'subTitle'),
                'catalog_thumbnail' => $this->string($doc, 'catalogThumbnail'),
            ]);

            DB::table('gallery_photos')->where('gallery_id', $id)->delete();

            foreach (array_values((array) $this->value($doc, 'photos', [])) as $position => $photo) {
                DB::table('gallery_photos')->insert([
                    'id' => isset($photo['_id']) ? strtolower($photo['_id']) : ObjectId::generate(),
                    'gallery_id' => $id,
                    'src' => $this->string($photo, 'src'),
                    'position' => $position,
                ]);
            }
        }
    }

    private function importVideos(array $documents)
    {
        foreach ($documents as $doc) {
            $this->upsert('videos', $doc, [
                'title' => $this->string($doc, 'title'),
                'video_url' => $this->string($doc, 'videoUrl'),
                'catalog_thumbnail' => $this->string($doc, 'catalogThumbnail'),
            ]);
        }
    }

    private function importContacts(array $documents)
    {
        foreach ($documents as $doc) {
            $this->upsert('contacts', $doc, [
                'name' => $this->string($doc, 'name'),
                'email' => $this->string($doc, 'email'),
                'subject' => $this->string($doc, 'subject'),
                'message' => $this->string($doc, 'message'),
            ], false);
        }
    }

    /**
     * Insert or update a row keyed by the document's `_id`; returns the id.
     */
    private function upsert(string $table, array $doc, array $columns, bool $hasUpdatedAt = true): string
    {
        $id = $this->id($doc);
        $columns['created_at'] = $this->date($this->value($doc, 'createdAt'));
        if ($hasUpdatedAt) {
            $columns['updated_at'] = $this->date($this->value($doc, 'updatedAt'));
        }

        DB::table($table)->updateOrInsert(['id' => $id], $columns);

        return $id;
    }

    private function id(array $doc): string
    {
        if (! isset($doc['_id']) || ! ObjectId::isValid($doc['_id'])) {
            throw new RuntimeException('Document without a valid ObjectId _id: '.json_encode($doc));
        }

        return strtolower($doc['_id']);
    }

    /**
     * Apply the schema defaults Mongoose would fill in for missing fields.
     */
    private function withDefaults(string $model, array $doc): array
    {
        // MongoDB's {isActive: true} filters skipped documents without the
        // field even though Mongoose displayed them as active; flag them.
        if ($model !== Admin::class && ! array_key_exists('isActive', $doc) && isset($doc['_id'])) {
            $this->warnings[] = sprintf(
                '%s %s had no isActive field (hidden from active lists before); imported as active.',
                $model::documentName(),
                $doc['_id']
            );
        }

        /** @var Model $model */
        return $doc + $model::schema()->defaults();
    }

    /**
     * Notes collected during the last import.
     */
    public function warnings(): array
    {
        return $this->warnings;
    }

    private function value(array $doc, string $key, $default = null)
    {
        return array_key_exists($key, $doc) ? $doc[$key] : $default;
    }

    /**
     * Booleans as 1/0 for TINYINT columns (null stays null).
     */
    private function boolean(array $doc, string $key)
    {
        $value = $this->value($doc, $key);

        return $value === null ? null : (int) (bool) $value;
    }

    private function string(array $doc, string $key)
    {
        $value = $this->value($doc, $key);

        return $value === null || is_array($value) ? $value : Js::toString($value);
    }

    /**
     * Format a date for a DATETIME(3) column (UTC).
     */
    private function date($value)
    {
        if ($value === null) {
            return null;
        }

        $date = $value instanceof Carbon ? $value : JsDate::from($value, 'UTC');

        return $date ? $date->copy()->setTimezone('UTC')->format('Y-m-d H:i:s.v') : null;
    }

    /**
     * Read a JSON array export or a newline-delimited export.
     */
    private function read(string $file): array
    {
        $content = trim((string) file_get_contents($file));

        if ($content === '') {
            return [];
        }

        if ($content[0] === '[') {
            $documents = json_decode($content, true);
            if (! is_array($documents)) {
                throw new RuntimeException("Invalid JSON in {$file}: ".json_last_error_msg());
            }

            return $documents;
        }

        $documents = [];
        foreach (preg_split('/\r?\n/', $content) as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            $document = json_decode($line, true);
            if (! is_array($document)) {
                throw new RuntimeException("Invalid JSON on line ".($number + 1)." of {$file}.");
            }
            $documents[] = $document;
        }

        return $documents;
    }

    /**
     * Convert MongoDB Extended JSON ($oid, $date, $numberInt, ...) to plain values.
     */
    private function decode($value)
    {
        if (! is_array($value)) {
            return $value;
        }

        $type = count($value) === 1 ? key($value) : null;
        $inner = current($value);

        // Strict comparisons: a list's key 0 must not loosely match '$oid'.
        if ($type === '$oid' && is_string($inner)) {
            return strtolower($inner);
        }

        if ($type === '$date') {
            if (is_array($inner) && isset($inner['$numberLong'])) {
                return JsDate::fromMilliseconds((float) $inner['$numberLong']);
            }

            return is_numeric($inner) ? JsDate::fromMilliseconds((float) $inner) : JsDate::from($inner, 'UTC');
        }

        if ($type === '$numberInt' || $type === '$numberLong') {
            return (int) $inner;
        }

        if ($type === '$numberDouble' || $type === '$numberDecimal') {
            return Js::jsonNumber((float) $inner);
        }

        return array_map([$this, 'decode'], $value);
    }
}
