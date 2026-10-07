<?php

namespace App\Services;

use App\Support\Js;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores uploaded images under public/uploads/<folder>, named
 * "<epoch milliseconds>-<original name, whitespace replaced by _>",
 * compresses them and returns the public path ("/uploads/<folder>/...").
 */
class UploadService
{
    /**
     * @var string
     */
    private $directory;

    /**
     * @var string
     */
    private $urlPrefix;

    /**
     * @var ImageOptimizer|null
     */
    private $optimizer;

    public function __construct(string $directory, string $urlPrefix, ?ImageOptimizer $optimizer = null)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->urlPrefix = rtrim($urlPrefix, '/');
        $this->optimizer = $optimizer;
    }

    /**
     * Save the file in the given sub folder (e.g. "galleries/<id>") and
     * return its public URL path.
     */
    public function store(UploadedFile $file, string $folder = ''): string
    {
        $folder = trim(str_replace('\\', '/', $folder), '/');
        $directory = $folder === '' ? $this->directory : $this->directory.'/'.$folder;

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $name = $this->milliseconds().'-'.Js::replaceWhitespace($file->getClientOriginalName(), '_');
        $file->move($directory, $name);

        if ($this->optimizer) {
            list($before, $after) = $this->optimizer->optimize($directory.'/'.$name);
            if ($after < $before) {
                Log::info(sprintf('Image compressed: %s (%d KB -> %d KB)', $name, $before / 1024, $after / 1024));
            }
        }

        $url = $this->urlPrefix.'/'.($folder === '' ? '' : $folder.'/').$name;
        Log::info('Image uploaded successfully: '.$url);

        return $url;
    }

    /**
     * Equivalent of JavaScript's Date.now().
     */
    private function milliseconds(): string
    {
        return sprintf('%.0f', floor(microtime(true) * 1000));
    }
}
