<?php

namespace Tests\Feature;

class GalleryTest extends ApiTestCase
{
    public function test_multipart_create_stores_thumbnail_and_every_photo()
    {
        $response = $this->multipart('POST', '/api/gallery', [
            ['title', '2025 Lion Dance'], ['year', '2025'], ['subTitle', 'Crystal Mall'], ['photos', '[{"id":9}]'],
        ], [
            ['catalogThumbnail', 'first.png', 'ignored'],
            ['catalogThumbnail', 'my thumb.png', 'thumb'],
            ['photos', 'p1.png', 'one'],
            ['photos', 'p 2.png', 'two'],
            ['9', 'other.png', 'not a photo'],
        ]);

        $response->assertStatus(201)->assertJson([
            'success' => true,
            'message' => 'Gallery created successfully',
            'data' => ['title' => '2025 Lion Dance', 'year' => '2025', 'subTitle' => 'Crystal Mall'],
        ]);

        $data = $response->json('data');
        $folder = '/uploads/galleries/'.$data['_id'];
        $this->assertMatchesRegularExpression('#^'.$folder.'/thumbnail/\d{13}-my_thumb\.png$#', $data['catalogThumbnail']);
        $this->assertCount(2, $data['photos']);
        $this->assertMatchesRegularExpression('#^'.$folder.'/\d{13}-p1\.png$#', $data['photos'][0]['src']);
        $this->assertMatchesRegularExpression('#^'.$folder.'/\d{13}-p_2\.png$#', $data['photos'][1]['src']);
        $this->assertObjectId($data['photos'][0]['_id']);

        // Files GD cannot decode are stored untouched.
        $this->assertSame('thumb', file_get_contents($this->uploadedFile($data['catalogThumbnail'])));
        $this->assertSame('two', file_get_contents($this->uploadedFile($data['photos'][1]['src'])));
        $this->assertCount(2, glob($this->uploads.'/galleries/'.$data['_id'].'/*.png'));
        $this->assertCount(1, glob($this->uploads.'/galleries/'.$data['_id'].'/thumbnail/*'));
    }

    public function test_uploaded_images_are_scaled_down_and_compressed()
    {
        config(['clf.uploads.max_dimension' => 800]);

        $image = imagecreatetruecolor(1600, 1200);
        for ($i = 0; $i < 4000; $i++) {
            imagefilledellipse($image, mt_rand(0, 1600), mt_rand(0, 1200), mt_rand(5, 60), mt_rand(5, 60), mt_rand(0, 0xFFFFFF));
        }
        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = ob_get_clean();

        $src = $this->multipart('POST', '/api/gallery', [['title', 'G']], [['photos', 'big.jpg', $jpeg]])
            ->assertStatus(201)
            ->json('data.photos.0.src');

        $file = $this->uploadedFile($src);
        $this->assertLessThan(strlen($jpeg), filesize($file));
        $this->assertSame([800, 600], array_slice(getimagesize($file), 0, 2));
    }

    public function test_more_photos_than_phps_max_file_uploads()
    {
        $files = [];
        for ($i = 0; $i < 25; $i++) {
            $files[] = ['photos', "img{$i}.jpg", "image {$i}"];
        }

        $response = $this->multipart('POST', '/api/gallery', [['title', 'Big']], $files)->assertStatus(201);

        $this->assertCount(25, $response->json('data.photos'));
    }

    public function test_create_requires_a_title()
    {
        $this->multipart('POST', '/api/gallery', [['year', '2024']])
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Failed to create gallery',
                'error' => 'Gallery validation failed: title: Path `title` is required.',
                'data' => null,
            ]);
    }

    public function test_multipart_put_replaces_photos()
    {
        $id = $this->multipart('POST', '/api/gallery', [['title', 'G']], [['photos', 'a.png', 'a'], ['photos', 'b.png', 'b']])
            ->json('data._id');

        $response = $this->multipart('PUT', "/api/gallery/{$id}", [['subTitle', 'New']], [['photos', 'c.png', 'c']]);

        $response->assertStatus(200)->assertJson(['message' => 'Gallery updated successfully', 'data' => ['title' => 'G', 'subTitle' => 'New']]);
        $this->assertCount(1, $response->json('data.photos'));
        $this->assertMatchesRegularExpression('#^/uploads/galleries/'.$id.'/\d{13}-c\.png$#', $response->json('data.photos.0.src'));

        $this->putJson('/api/gallery/0123456789abcdef01234567', ['title' => 'x'])
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Gallery not found', 'data' => null]);
    }

    public function test_multipart_put_photo_urls_removes_omitted_photos_but_keeps_files()
    {
        $created = $this->multipart('POST', '/api/gallery', [['title', 'G']], [
            ['photos', 'keep-one.png', 'one'],
            ['photos', 'remove.png', 'remove'],
            ['photos', 'keep-two.png', 'two'],
        ])->assertStatus(201)->json('data');
        $galleryId = $created['_id'];
        $keptPhotos = [$created['photos'][0], $created['photos'][2]];
        $removedFile = $this->uploadedFile($created['photos'][1]['src']);

        $updated = $this->multipart('PUT', "/api/gallery/{$galleryId}", [
            ['title', 'G'],
            ['photoUrls', $keptPhotos[0]['src']],
            ['photoUrls', $keptPhotos[1]['src']],
        ])->assertStatus(200);

        $this->assertSame(
            [$keptPhotos[0]['_id'], $keptPhotos[1]['_id']],
            array_column($updated->json('data.photos'), '_id')
        );
        $this->assertFileExists($removedFile);
        $this->assertSame('remove', file_get_contents($removedFile));

        $this->multipart('PUT', "/api/gallery/{$galleryId}", [
            ['title', 'G'],
            ['photoUrls', $keptPhotos[0]['src']],
        ])->assertStatus(200)->assertJsonCount(1, 'data.photos');
    }

    public function test_admin_edit_keeps_existing_photos_and_adds_uploads_in_order()
    {
        $created = $this->multipart('POST', '/api/gallery', [['title', 'G']], [
            ['photos', 'a.png', 'a'],
            ['photos', 'b.png', 'b'],
        ])->assertStatus(201)->json('data');
        $id = $created['_id'];
        list($a, $b) = $created['photos'];

        // What the admin panel's EditGallery form sends after photos were added.
        $photos = $this->multipartParts('PUT', "/api/gallery/{$id}", [
            ['id', $id], ['title', 'G'], ['year', '2026'], ['subTitle', 'Richmond'],
            ['catalogThumbnail', ''],
            ['photoUrls', $a['src']],
            ['photos', 'c.png', 'c'],
            ['photoUrls', $b['src']],
            ['photoUrls', 'https://example.com/d.jpg'],
            ['photos', 'e.png', 'e'],
        ])->assertStatus(200)->json('data.photos');

        $this->assertCount(5, $photos);
        $this->assertSame([$a['_id'], $b['_id']], [$photos[0]['_id'], $photos[2]['_id']]);
        $this->assertSame([$a['src'], $b['src'], 'https://example.com/d.jpg'], [$photos[0]['src'], $photos[2]['src'], $photos[3]['src']]);
        $this->assertMatchesRegularExpression('#^/uploads/galleries/'.$id.'/\d{13}-c\.png$#', $photos[1]['src']);
        $this->assertMatchesRegularExpression('#^/uploads/galleries/'.$id.'/\d{13}-e\.png$#', $photos[4]['src']);

        $this->assertSame(array_column($photos, 'src'), array_column($this->getJson("/api/gallery/{$id}")->json('data.photos'), 'src'));
    }

    public function test_create_accepts_thumbnail_and_photo_urls_alongside_uploads()
    {
        $data = $this->multipartParts('POST', '/api/gallery', [
            ['id', ''], ['title', 'New'], ['year', '2026'], ['subTitle', 'Richmond'],
            ['catalogThumbnail', ' https://example.com/thumb.jpg '],
            ['photos', 'p1.png', 'one'],
            ['photoUrls', 'https://example.com/p2.jpg'],
            ['photos', 'p3.png', 'three'],
        ])->assertStatus(201)->json('data');

        $this->assertSame('https://example.com/thumb.jpg', $data['catalogThumbnail']);
        $this->assertCount(3, $data['photos']);
        $this->assertMatchesRegularExpression('#/\d{13}-p1\.png$#', $data['photos'][0]['src']);
        $this->assertSame('https://example.com/p2.jpg', $data['photos'][1]['src']);
        $this->assertMatchesRegularExpression('#/\d{13}-p3\.png$#', $data['photos'][2]['src']);
    }

    public function test_update_sets_a_thumbnail_url_and_keeps_it_when_sent_back()
    {
        $id = $this->multipart('POST', '/api/gallery', [['title', 'G']], [['catalogThumbnail', 't.png', 't']])->json('data._id');
        $current = $this->getJson("/api/gallery/{$id}")->json('data.catalogThumbnail');

        // The admin panel sends the current thumbnail path back on every save.
        $this->multipartParts('PUT', "/api/gallery/{$id}", [['title', 'G'], ['catalogThumbnail', $current]])
            ->assertStatus(200)->assertJson(['data' => ['catalogThumbnail' => $current]]);

        $this->multipartParts('PUT', "/api/gallery/{$id}", [['title', 'G'], ['catalogThumbnail', 'https://example.com/new.jpg']])
            ->assertStatus(200)->assertJson(['data' => ['catalogThumbnail' => 'https://example.com/new.jpg']]);
    }

    public function test_a_rejected_create_stores_no_files()
    {
        $this->multipart('POST', '/api/gallery', [['year', '2024']], [['catalogThumbnail', 't.png', 't'], ['photos', 'p.png', 'p']])
            ->assertStatus(500)
            ->assertJson(['error' => 'Gallery validation failed: title: Path `title` is required.']);

        $this->assertDirectoryDoesNotExist($this->uploads.'/galleries');
    }

    public function test_list_by_year_orders_groups_like_a_javascript_object()
    {
        foreach (['abc', '2025', '', '0', '2023'] as $year) {
            $this->postJson('/api/gallery', ['title' => "G{$year}", 'year' => $year])->assertStatus(201);
        }

        $response = $this->getJson('/api/gallery/list-by-year')->assertStatus(200);
        $data = json_decode($response->getContent(), false)->data;

        // (array) turns numeric keys into ints, so compare them as the strings JSON sent.
        $this->assertSame(['0', '2023', '2025', 'abc', 'unknown'], array_map('strval', array_keys((array) $data)));
        $this->assertSame(['_id', 'title', 'year', 'catalogThumbnail', 'photos'], array_keys((array) $data->{'2025'}[0]));
    }

    public function test_show_and_delete()
    {
        $id = $this->postJson('/api/gallery', ['title' => 'G'])->json('data._id');

        $this->getJson("/api/gallery/{$id}")
            ->assertStatus(200)
            ->assertJson(['message' => 'Gallery fetched successfully', 'data' => ['_id' => $id, 'catalogThumbnail' => '', 'photos' => []]]);

        $this->deleteJson("/api/gallery/{$id}")
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Gallery deleted successfully', 'data' => null]);

        $this->getJson('/api/gallery/xyz')
            ->assertStatus(500)
            ->assertJson(['error' => 'Cast to ObjectId failed for value "xyz" (type string) at path "_id" for model "Gallery"']);
    }
}
