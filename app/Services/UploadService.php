<?php

namespace App\Services;

use App\Support\Js;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Stores uploaded images in public/uploads, named
 * "<epoch milliseconds>-<original name, whitespace replaced by _>"
 * and returns the public path ("/uploads/..."), exactly as before.
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

    public function __construct(string $directory, string $urlPrefix)
    {
        $this->directory = rtrim($directory, '/\\');
        $this->urlPrefix = rtrim($urlPrefix, '/');
    }

    /**
     * Save the file and return its public URL path.
     */
    public function store(UploadedFile $file): string
    {
        if (! is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }

        $name = $this->milliseconds().'-'.Js::replaceWhitespace($file->getClientOriginalName(), '_');
        $file->move($this->directory, $name);

        $url = $this->urlPrefix.'/'.$name;
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
