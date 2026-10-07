<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Base class for API feature tests (MySQL database "clf_backend_testing").
 */
abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    /**
     * @var string
     */
    protected $uploads;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploads = storage_path('framework/testing/uploads');
        config(['clf.uploads.directory' => $this->uploads]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->uploads);

        parent::tearDown();
    }

    /**
     * Send a raw multipart/form-data request, the way browsers and Multer
     * clients do (repeated field names, no "[]").
     *
     * @param  array  $fields  list of [name, value]
     * @param  array  $files  list of [field name, file name, contents]
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    protected function multipart(string $method, string $uri, array $fields = [], array $files = [])
    {
        $boundary = '----clf'.bin2hex(random_bytes(8));
        $body = '';

        foreach ($fields as list($name, $value)) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
        }

        foreach ($files as list($name, $filename, $contents)) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                ."Content-Type: application/octet-stream\r\n\r\n{$contents}\r\n";
        }

        $body .= "--{$boundary}--\r\n";

        return $this->call($method, $uri, [], [], [], [
            'CONTENT_TYPE' => "multipart/form-data; boundary={$boundary}",
            'CONTENT_LENGTH' => strlen($body),
        ], $body);
    }

    /**
     * Send a request without any body.
     *
     * @return \Illuminate\Foundation\Testing\TestResponse
     */
    protected function withoutBody(string $method, string $uri)
    {
        return $this->call($method, $uri);
    }

    /**
     * Path on disk of an uploaded file's "/uploads/..." URL.
     */
    protected function uploadedFile(string $url): string
    {
        return $this->uploads.substr($url, strlen('/uploads'));
    }

    protected function assertIsoDate($value)
    {
        $this->assertRegExp('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $value);
    }

    protected function assertObjectId($value)
    {
        $this->assertRegExp('/^[0-9a-f]{24}$/', $value);
    }
}
