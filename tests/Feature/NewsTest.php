<?php

namespace Tests\Feature;

class NewsTest extends ApiTestCase
{
    private function create(array $values)
    {
        return $this->postJson('/api/news', $values + ['title' => 'Title', 'description' => '<p>D</p>', 'date' => '2025-12-17']);
    }

    public function test_create_returns_the_document()
    {
        $response = $this->create(['slug' => 'sample-news'])->assertStatus(201);

        $response->assertJson([
            'success' => true,
            'message' => 'News added successfully',
            'data' => [
                'title' => 'Title',
                'date' => '2025-12-17T00:00:00.000Z',
                'slug' => 'sample-news',
                'isActive' => true,
                '__v' => 0,
            ],
        ]);
        $this->assertObjectId($response->json('data._id'));
    }

    public function test_create_rejects_duplicate_slugs()
    {
        $this->create(['slug' => 's1'])->assertStatus(201);

        $this->create(['slug' => 's1'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'News with this slug already exists']);

        // Slugs are compared exactly, like MongoDB.
        $this->create(['slug' => 'S1'])->assertStatus(201);
    }

    public function test_create_parses_dates_like_javascript()
    {
        $this->create(['slug' => 'a', 'date' => null])->assertJson(['data' => ['date' => '1970-01-01T00:00:00.000Z']]);
        $this->create(['slug' => 'b', 'date' => 1700000000123])->assertJson(['data' => ['date' => '2023-11-14T22:13:20.123Z']]);
        $this->create(['slug' => 'c', 'date' => '2025-03-04T05:06:07.123+02:00'])->assertJson(['data' => ['date' => '2025-03-04T03:06:07.123Z']]);

        foreach (['now', ''] as $index => $date) {
            $this->create(['slug' => "invalid{$index}", 'date' => $date])
                ->assertStatus(500)
                ->assertExactJson(['success' => false, 'message' => 'Error creating News content', 'error' => 'Invalid time value']);
        }

        $this->postJson('/api/news', ['title' => 'T', 'description' => 'D', 'slug' => 'nodate'])
            ->assertStatus(500)
            ->assertJson(['error' => 'Invalid time value']);
    }

    public function test_create_reports_missing_fields()
    {
        $this->postJson('/api/news', ['slug' => 's4', 'date' => '2025-01-01'])
            ->assertStatus(500)
            ->assertJson(['error' => 'News validation failed: title: Path `title` is required., description: Path `description` is required.']);
    }

    public function test_index_paginates_newest_first()
    {
        foreach (['2025-01-01', '2025-03-01', '2025-02-01'] as $index => $date) {
            $this->create(['slug' => "n{$index}", 'date' => $date]);
        }

        $response = $this->getJson('/api/news?page=2&limit=2')->assertStatus(200);
        $this->assertSame(3, $response->json('length'));
        $this->assertSame(2, $response->json('page'));
        $this->assertSame(2, $response->json('totalpages'));
        $this->assertSame(['2025-01-01T00:00:00.000Z'], array_column($response->json('data'), 'date'));

        $all = $this->getJson('/api/news?limit=0');
        $this->assertNull($all->json('totalpages'));
        $this->assertCount(3, $all->json('data'));

        $this->getJson('/api/news?page=0&limit=2')
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'error' => "BSON field 'skip' value must be >= 0, actual value '-2'"]);
    }

    public function test_update()
    {
        $id = $this->create(['slug' => 's1'])->json('data._id');
        $this->create(['slug' => 's2']);

        $this->putJson("/api/news/{$id}", ['title' => 'Updated', 'date' => '2025-12-18', 'isActive' => 'false'])
            ->assertStatus(200)
            ->assertJson(['message' => 'News updated successfully', 'data' => [
                'title' => 'Updated', 'date' => '2025-12-18T00:00:00.000Z', 'isActive' => false,
            ]]);

        $this->putJson('/api/news/1', ['title' => 'x'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Invalid News ID']);

        $this->putJson('/api/news/0123456789abcdef01234567', ['title' => 'x'])
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'News not found']);

        $this->putJson("/api/news/{$id}", ['date' => 'garbage'])
            ->assertStatus(500)
            ->assertJson(['error' => 'Cast to date failed for value "garbage" (type string) at path "date" for model "News"']);

        $this->putJson("/api/news/{$id}", ['slug' => 's2'])
            ->assertStatus(500)
            ->assertJson(['error' => 'Plan executor error during findAndModify :: caused by :: E11000 duplicate key error collection: clf_backend_testing.news index: slug_1 dup key: { slug: "s2" }']);
    }

    public function test_show_and_delete()
    {
        $id = $this->create(['slug' => 's1'])->json('data._id');

        $this->getJson("/api/news/{$id}")->assertStatus(200)->assertJson(['success' => true, 'data' => ['_id' => $id]]);
        $this->getJson('/api/news/1')
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'error' => 'Cast to ObjectId failed for value "1" (type string) at path "_id" for model "News"']);

        $this->deleteJson("/api/news/{$id}")->assertStatus(200)->assertExactJson(['success' => true, 'message' => 'News deleted successfully']);
        $this->deleteJson("/api/news/{$id}")->assertStatus(404)->assertExactJson(['success' => false, 'message' => 'News not found']);
    }
}
