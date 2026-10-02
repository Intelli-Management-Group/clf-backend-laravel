<?php

namespace Tests\Feature;

class ContactAndDashboardTest extends ApiTestCase
{
    public function test_contact_submission()
    {
        $response = $this->postJson('/api/contact', ['name' => 'Wong', 'email' => 'w@example.com', 'subject' => 'Classes', 'message' => '你好'])
            ->assertStatus(201)
            ->assertJson([
                'success' => true,
                'message' => 'Contact message submitted successfully',
                'data' => ['name' => 'Wong', 'message' => '你好', '__v' => 0],
            ]);

        $this->assertArrayHasKey('createdAt', $response->json('data'));
        $this->assertArrayNotHasKey('updatedAt', $response->json('data'));
    }

    public function test_contact_requires_every_field()
    {
        $this->postJson('/api/contact', ['name' => 'n', 'email' => 'e', 'subject' => 's', 'message' => 0])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'All fields are required', 'data' => null]);
    }

    public function test_dashboard_counts()
    {
        $this->postJson('/api/news', ['title' => 'A', 'description' => 'D', 'date' => '2025-01-01', 'slug' => 'a']);
        $this->postJson('/api/news', ['title' => 'B', 'description' => 'D', 'date' => '2025-01-01', 'slug' => 'b', 'isActive' => false]);
        $this->postJson('/api/gallery', ['title' => 'G']);

        $this->getJson('/api/dashboard')
            ->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'message' => 'Dashboard stats fetched successfully',
                'data' => ['activatedNewsCount' => 1, 'imagesCount' => 1, 'videosCount' => 0],
            ]);
    }
}
