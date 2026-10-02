<?php

namespace Tests\Unit;

use App\Exceptions\RequestBodyException;
use App\Http\Multipart\MultipartParser;
use PHPUnit\Framework\TestCase;

class MultipartParserTest extends TestCase
{
    const BOUNDARY = 'XyZ123';

    private function parse(string $body, int $fieldLimit = 1048576): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $body);
        rewind($stream);

        return (new MultipartParser($fieldLimit))->parse($stream, self::BOUNDARY);
    }

    private function part(string $disposition, string $content, string $type = null): string
    {
        return '--'.self::BOUNDARY."\r\nContent-Disposition: form-data; {$disposition}\r\n"
            .($type ? "Content-Type: {$type}\r\n" : '')."\r\n{$content}\r\n";
    }

    public function test_fields_and_repeated_files()
    {
        $binary = random_bytes(200000)."\r\n--".'XyZ12'; // contains a near-boundary
        $body = 'preamble ignored'."\r\n"
            .$this->part('name="title"', 'Lion Dance')
            .$this->part('name="tags"', 'a')
            .$this->part('name="tags"', 'b')
            .$this->part('name="meta[city]"', 'Vancouver')
            .$this->part('name="photos"; filename="C:\\Users\\me\\p1.png"', $binary, 'image/png')
            .$this->part('name="photos"; filename="中文 圖片.png"', 'two')
            .$this->part("name=\"photos\"; filename*=UTF-8''caf%C3%A9.png", 'three')
            .'--'.self::BOUNDARY."--\r\nepilogue";

        list($fields, $files) = $this->parse($body);

        $this->assertSame(['title' => 'Lion Dance', 'tags' => ['a', 'b'], 'meta' => ['city' => 'Vancouver']], $fields);
        $this->assertCount(3, $files['photos']);
        $this->assertSame('p1.png', $files['photos'][0]->getClientOriginalName());
        $this->assertSame('image/png', $files['photos'][0]->getClientMimeType());
        $this->assertSame($binary, file_get_contents($files['photos'][0]->getPathname()));
        $this->assertSame('中文 圖片.png', $files['photos'][1]->getClientOriginalName());
        $this->assertSame('café.png', $files['photos'][2]->getClientOriginalName());
    }

    public function test_truncated_body()
    {
        $this->expectException(RequestBodyException::class);
        $this->expectExceptionMessage('Unexpected end of form');

        $this->parse($this->part('name="title"', 'no closing boundary'));
    }

    public function test_field_size_limit()
    {
        $this->expectException(RequestBodyException::class);
        $this->expectExceptionMessage('Field value too long');

        $this->parse($this->part('name="title"', str_repeat('x', 50)).'--'.self::BOUNDARY."--\r\n", 10);
    }

    public function test_boundary_extraction()
    {
        $this->assertSame('abc', MultipartParser::boundary('multipart/form-data; boundary=abc'));
        $this->assertSame('a b', MultipartParser::boundary('multipart/form-data; boundary="a b"'));
        $this->assertNull(MultipartParser::boundary('multipart/form-data'));
    }
}
