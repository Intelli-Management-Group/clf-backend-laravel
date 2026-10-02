<?php

namespace Tests\Feature;

class VideoTest extends ApiTestCase
{
    private $video = ['title' => 'Lion Dance', 'videoUrl' => 'https://youtu.be/x', 'catalogThumbnail' => '/uploads/t.png'];

    public function test_crud()
    {
        $id = $this->postJson('/api/videos', $this->video)
            ->assertStatus(201)
            ->assertJson(['success' => true, 'message' => 'Video created successfully', 'data' => $this->video])
            ->json('data._id');

        $this->getJson('/api/videos')
            ->assertStatus(200)
            ->assertJson(['message' => 'Videos fetched successfully', 'data' => [['_id' => $id]]]);

        $this->getJson("/api/videos/{$id}")->assertStatus(200)->assertJson(['message' => 'Video fetched successfully']);

        // Updates are not validated, exactly as before.
        $this->putJson("/api/videos/{$id}", ['title' => '', 'videoUrl' => null])
            ->assertStatus(200)
            ->assertJson(['message' => 'Video updated successfully', 'data' => ['title' => '', 'videoUrl' => null]]);

        $this->deleteJson("/api/videos/{$id}")
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Video deleted successfully', 'data' => null]);

        $this->getJson("/api/videos/{$id}")
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Video not found', 'data' => null]);
    }

    public function test_create_errors_are_400()
    {
        $this->postJson('/api/videos', [])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'Failed to create video',
                'error' => 'Video validation failed: title: Path `title` is required., videoUrl: Path `videoUrl` is required., catalogThumbnail: Path `catalogThumbnail` is required.',
                'data' => null,
            ]);
    }

    public function test_values_are_cast_to_strings()
    {
        $this->postJson('/api/videos', ['title' => 5, 'videoUrl' => true, 'catalogThumbnail' => 'c'])
            ->assertStatus(201)
            ->assertJson(['data' => ['title' => '5', 'videoUrl' => 'true']]);

        $this->putJson('/api/videos/xyz', ['title' => 'a'])
            ->assertStatus(400)
            ->assertJson(['message' => 'Failed to update video', 'error' => 'Cast to ObjectId failed for value "xyz" (type string) at path "_id" for model "Video"']);
    }
}
