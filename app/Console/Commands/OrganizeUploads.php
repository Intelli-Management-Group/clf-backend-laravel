<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\GalleryController;
use App\Models\Gallery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Moves every gallery's images into its own folder
 * (public/uploads/galleries/<id>, thumbnail in ".../thumbnail") and
 * updates the stored URLs. Optionally moves the files nothing refers to
 * into public/uploads/_unused so they can be reviewed and deleted.
 *
 * Every move is written to storage/app/uploads-moves-<time>.json.
 */
class OrganizeUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clf:organize-uploads
                            {--move-unused : Also move files no record refers to into uploads/_unused}
                            {--dry-run : Only report what would be moved}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Move gallery images into one folder per gallery and update their URLs';

    /**
     * @var string
     */
    private $root;

    /**
     * @var string
     */
    private $url;

    /**
     * Old URL => new URL of every file moved.
     *
     * @var array
     */
    private $moved = [];

    public function handle()
    {
        $this->root = str_replace('\\', '/', rtrim(config('clf.uploads.directory'), '/\\'));
        $this->url = rtrim(config('clf.uploads.url'), '/');
        $dryRun = (bool) $this->option('dry-run');
        $missing = [];

        try {
            $galleries = Gallery::query()->with('photos')->orderBy('created_at')->get();
            $bar = $this->output->createProgressBar($galleries->count());

            foreach ($galleries as $gallery) {
                $folder = GalleryController::folder($gallery->id);
                $movedHere = [];

                foreach ($gallery->photos as $photo) {
                    $src = $this->relocate($photo->src, $folder, $dryRun, $missing, $movedHere);
                    if ($src !== $photo->src && ! $dryRun) {
                        $photo->src = $src;
                    }
                }

                $thumbnail = $this->relocate((string) $gallery->catalog_thumbnail, $folder.'/thumbnail', $dryRun, $missing, $movedHere);

                if (! $dryRun && $movedHere) {
                    $this->saveGallery($gallery, $thumbnail, $movedHere);
                }

                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);

            $this->info(($dryRun ? 'Would move ' : 'Moved ').count($this->moved).' gallery images into uploads/galleries/<id>.');
            if ($missing) {
                $this->warn(count($missing).' referenced images do not exist on disk (left unchanged), e.g. '.implode(', ', array_slice($missing, 0, 3)));
            }

            if ($this->option('move-unused')) {
                $this->moveUnused($dryRun);
            }
        } finally {
            if (! $dryRun && $this->moved) {
                $log = storage_path('app/uploads-moves-'.date('Ymd-His').'.json');
                file_put_contents($log, json_encode($this->moved, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->line("Moves recorded in {$log}");
            }
        }

        return 0;
    }

    /**
     * Move the file behind $url into $folder; returns its (new) URL.
     */
    private function relocate(string $url, string $folder, bool $dryRun, array &$missing, array &$movedHere): string
    {
        if (isset($this->moved[$url])) {
            return $this->moved[$url];
        }

        $prefix = $this->url.'/';
        if (strpos($url, $prefix) !== 0 || strpos($url, $prefix.$folder.'/') === 0) {
            return $url; // external URL, or already in place
        }

        $source = $this->root.'/'.substr($url, strlen($prefix));
        if (! is_file($source)) {
            $missing[] = $url;

            return $url;
        }

        $relative = $this->freeName($folder, basename($source));
        $this->moved[$url] = $prefix.$relative;

        if (! $dryRun) {
            $target = $this->root.'/'.$relative;
            if (! is_dir(dirname($target))) {
                mkdir(dirname($target), 0755, true);
            }
            if (! rename($source, $target)) {
                unset($this->moved[$url]);
                throw new \RuntimeException("Could not move {$source} to {$target}");
            }
            $movedHere[$url] = $prefix.$relative;
        }

        return $prefix.$relative;
    }

    /**
     * "<folder>/<name>", suffixed with -1, -2, ... if that file already exists
     * (or was already planned in this run).
     */
    private function freeName(string $folder, string $name): string
    {
        $planned = array_flip($this->moved);
        $info = pathinfo($name);
        $extension = isset($info['extension']) ? '.'.$info['extension'] : '';

        for ($i = 0; ; $i++) {
            $relative = $folder.'/'.$info['filename'].($i ? "-{$i}" : '').$extension;
            if (! file_exists($this->root.'/'.$relative) && ! isset($planned[$this->url.'/'.$relative])) {
                return $relative;
            }
        }
    }

    /**
     * Store the new URLs; moves the files back if the database update fails.
     */
    private function saveGallery(Gallery $gallery, string $thumbnail, array $movedHere)
    {
        try {
            DB::transaction(function () use ($gallery, $thumbnail) {
                foreach ($gallery->photos as $photo) {
                    if ($photo->isDirty('src')) {
                        $photo->save();
                    }
                }

                if ($thumbnail !== (string) $gallery->catalog_thumbnail) {
                    // Keep updated_at as it was: this is not an edit of the gallery.
                    $gallery->timestamps = false;
                    $gallery->catalog_thumbnail = $thumbnail;
                    $gallery->save();
                    $gallery->timestamps = true;
                }
            });
        } catch (\Throwable $e) {
            foreach ($movedHere as $old => $new) {
                rename($this->root.substr($new, strlen($this->url)), $this->root.substr($old, strlen($this->url)));
                unset($this->moved[$old]);
            }

            throw $e;
        }
    }

    /**
     * Move files that no record refers to into uploads/_unused, keeping their paths.
     */
    private function moveUnused(bool $dryRun)
    {
        $referenced = $this->referencedFiles();
        $keep = ['.gitignore', '.htaccess'];
        $count = 0;
        $bytes = 0;

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS));
        $files = [];
        foreach ($iterator as $file) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }

        foreach ($files as $path) {
            $relative = substr($path, strlen($this->root) + 1);

            if (isset($referenced[$relative])
                || in_array($relative, $keep, true)
                || strpos($relative, '_unused/') === 0
                || strpos($relative, 'galleries/') === 0) {
                continue;
            }

            $count++;
            $bytes += filesize($path);

            if (! $dryRun) {
                $target = $this->root.'/_unused/'.$relative;
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0755, true);
                }
                rename($path, $target);
            }
        }

        if (! $dryRun) {
            $this->removeEmptyDirectories($this->root);
        }

        $this->info(sprintf(
            '%s %d unreferenced files (%.2f GB) into uploads/_unused.',
            $dryRun ? 'Would move' : 'Moved',
            $count,
            $bytes / 1073741824
        ));
    }

    /**
     * Paths (relative to the uploads directory) of every file referenced
     * by a gallery, video, news item or about page.
     */
    private function referencedFiles(): array
    {
        $prefix = $this->url.'/';
        $urls = DB::table('gallery_photos')->pluck('src')
            ->merge(DB::table('galleries')->pluck('catalog_thumbnail'))
            ->merge(DB::table('videos')->pluck('catalog_thumbnail'));

        foreach (['news', 'abouts'] as $table) {
            foreach (DB::table($table)->where('description', 'like', '%'.$prefix.'%')->pluck('description') as $html) {
                preg_match_all('#'.preg_quote($prefix, '#').'[^"\'\s)<>?]+#', $html, $matches);
                $urls = $urls->merge($matches[0]);
            }
        }

        $referenced = [];
        foreach ($urls as $url) {
            $path = (string) parse_url((string) $url, PHP_URL_PATH);
            if (strpos($path, $prefix) === 0) {
                $referenced[rawurldecode(substr($path, strlen($prefix)))] = true;
            }
        }

        return $referenced;
    }

    private function removeEmptyDirectories(string $directory)
    {
        foreach (glob($directory.'/*', GLOB_ONLYDIR) as $child) {
            if (basename($child) === '_unused') {
                continue;
            }
            $this->removeEmptyDirectories($child);
            if (count(scandir($child)) === 2) {
                rmdir($child);
            }
        }
    }
}
