<?php

namespace App\Console\Commands;

use App\Services\ImageOptimizer;
use Illuminate\Console\Command;

/**
 * Compresses the images already in public/uploads, in place (file names
 * and URLs do not change).
 */
class OptimizeUploads extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'clf:optimize-uploads
                            {folder? : Only this sub folder of the uploads directory, e.g. "galleries" or "wp/2014"}
                            {--min-kb=100 : Skip images smaller than this many kilobytes}
                            {--dry-run : Only report how much would be saved}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Compress the JPEG, PNG and WebP images in public/uploads in place';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle(ImageOptimizer $optimizer)
    {
        if (! ImageOptimizer::available()) {
            $this->error('The PHP "gd" extension is required (enable extension=gd in php.ini).');

            return 1;
        }

        $root = rtrim(config('clf.uploads.directory'), '/\\');
        $directory = $root.($this->argument('folder') ? '/'.trim($this->argument('folder'), '/\\') : '');

        if (! is_dir($directory)) {
            $this->error("Directory not found: {$directory}");

            return 1;
        }

        $minBytes = max(0, (int) $this->option('min-kb')) * 1024;
        $dryRun = (bool) $this->option('dry-run');

        $this->line("Scanning {$directory} ...");
        $files = $this->images($directory, $root.'/_unused', $minBytes);
        $this->line(count($files).' images to process'.($dryRun ? ' (dry run)' : '').'.');

        $before = 0;
        $after = 0;
        $changed = 0;

        $bar = $this->output->createProgressBar(count($files));
        foreach ($files as $file) {
            list($old, $new) = $optimizer->optimize($file, $dryRun);
            $before += $old;
            $after += $new;
            $changed += $new < $old ? 1 : 0;
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $this->info(sprintf(
            '%s %d of %d images: %s -> %s (saved %s).',
            $dryRun ? 'Would compress' : 'Compressed',
            $changed,
            count($files),
            $this->size($before),
            $this->size($after),
            $this->size($before - $after)
        ));

        return 0;
    }

    /**
     * Image files under $directory of at least $minBytes, skipping $exclude.
     */
    private function images(string $directory, string $exclude, int $minBytes): array
    {
        $exclude = str_replace('\\', '/', $exclude).'/';
        $files = [];

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $path = str_replace('\\', '/', $file->getPathname());

            if ($file->isFile()
                && preg_match('/\.(jpe?g|png|webp)$/i', $path)
                && $file->getSize() >= $minBytes
                && strpos($path, $exclude) !== 0) {
                $files[] = $path;
            }
        }

        sort($files);

        return $files;
    }

    private function size(int $bytes): string
    {
        return $bytes >= 1073741824
            ? sprintf('%.2f GB', $bytes / 1073741824)
            : sprintf('%.1f MB', $bytes / 1048576);
    }
}
