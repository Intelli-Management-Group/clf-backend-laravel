<?php

namespace Tests\Feature;

class AboutTest extends ApiTestCase
{
    private function create(array $values)
    {
        return $this->postJson('/api/about', $values + ['title' => 'T', 'category' => 'style', 'description' => '<p>D</p>']);
    }

    public function test_create_casts_trims_and_applies_defaults()
    {
        $response = $this->create(['name' => '  Choy Lee Fat  ', 'title' => ' 蔡李佛 ', 'order' => '2', 'isActive' => false]);

        $response->assertStatus(201)->assertJson([
            'success' => true,
            'message' => 'About content created successfully',
            'data' => [
                'name' => 'Choy Lee Fat',
                'title' => '蔡李佛',
                'category' => 'style',
                'parent_id' => null,
                'order' => 2,
                'isActive' => true,
                '__v' => 0,
            ],
        ]);

        $data = $response->json('data');
        $this->assertObjectId($data['_id']);
        $this->assertIsoDate($data['createdAt']);
        $this->assertSame($data['createdAt'], $data['updatedAt']);
    }

    public function test_create_requires_fields()
    {
        $this->postJson('/api/about', ['name' => 'X', 'title' => ''])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'Please provide all required fields: name, title, category, and description',
            ]);
    }

    public function test_create_reports_mongoose_validation_errors()
    {
        $this->create(['name' => 'X', 'category' => 'other'])
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Error creating about content',
                'error' => 'About validation failed: category: `other` is not a valid enum value for path `category`.',
            ]);

        $this->create(['name' => 'X', 'order' => 'abc'])
            ->assertStatus(500)
            ->assertJson(['error' => 'About validation failed: order: Cast to Number failed for value "abc" (type string) at path "order" for model "About"']);
    }

    public function test_create_checks_the_parent()
    {
        $this->create(['name' => 'X', 'parent_id' => 'abc'])
            ->assertStatus(500)
            ->assertJson(['error' => 'Cast to ObjectId failed for value "abc" (type string) at path "_id" for model "About"']);

        $this->create(['name' => 'X', 'parent_id' => '0123456789abcdef01234567'])
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Parent content not found']);
    }

    public function test_index_nests_children_under_active_parents()
    {
        $parent = $this->create(['name' => 'Parent', 'order' => 1])->json('data._id');
        usleep(2000);
        $this->create(['name' => 'Bio', 'category' => 'biographies', 'order' => 0]);
        usleep(2000);
        $child = $this->create(['name' => 'Child', 'parent_id' => strtoupper($parent)])->json('data._id');

        $response = $this->getJson('/api/about')->assertStatus(200);
        $data = $response->json('data');

        $this->assertSame(2, $response->json('count'));
        $this->assertSame(['Bio', 'Parent'], array_column($data, 'name'));
        $this->assertSame([], $data[0]['children']);
        $this->assertSame($child, $data[1]['children'][0]['_id']);
        $this->assertSame(
            ['_id' => $parent, 'name' => 'Parent', 'title' => 'T'],
            $data[1]['children'][0]['parent_id']
        );

        $this->assertSame(['Parent'], array_column($this->getJson('/api/about?category=style')->json('data'), 'name'));

        // Children of an inactive parent disappear with it.
        $this->putJson("/api/about/{$parent}", ['isActive' => 'no'])->assertStatus(200);
        $this->assertSame(['Bio'], array_column($this->getJson('/api/about')->json('data'), 'name'));
    }

    public function test_show_populates_the_parent()
    {
        $parent = $this->create(['name' => 'Parent'])->json('data._id');
        $child = $this->create(['name' => 'Child', 'parent_id' => $parent])->json('data._id');

        $this->getJson("/api/about/{$child}")
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['parent_id' => ['_id' => $parent, 'name' => 'Parent', 'title' => 'T']]]);

        $this->getJson('/api/about/0123456789abcdef01234567')
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'About content not found']);
    }

    public function test_update_validates_like_mongoose_update_validators()
    {
        $id = $this->create(['name' => 'Item'])->json('data._id');

        $this->putJson("/api/about/{$id}", ['name' => '', 'title' => '', 'description' => ''])
            ->assertStatus(500)
            ->assertJson(['error' => 'Validation failed: description: Path `description` is required., title: Path `title` is required., name: Path `name` is required.']);

        $this->putJson("/api/about/{$id}", ['isActive' => ''])
            ->assertStatus(500)
            ->assertJson(['error' => 'Cast to Boolean failed for value "" (type string) at path "isActive" for model "About" because of "CastError"']);

        $response = $this->putJson("/api/about/{$id}", ['order' => '', 'name' => '  Renamed ']);
        $response->assertStatus(200)->assertJson(['data' => ['name' => 'Renamed', 'order' => null]]);
        $this->assertNotSame($response->json('data.createdAt'), $response->json('data.updatedAt'));
    }

    public function test_delete_is_refused_while_children_exist()
    {
        $parent = $this->create(['name' => 'Parent'])->json('data._id');
        $child = $this->create(['name' => 'Child', 'parent_id' => $parent])->json('data._id');

        $this->deleteJson("/api/about/{$parent}")
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'Cannot delete content with child items. Please delete or reassign child items first.',
            ]);

        $this->deleteJson("/api/about/{$child}")->assertStatus(200);
        $this->deleteJson("/api/about/{$parent}")
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'About content deleted successfully']);

        $this->deleteJson('/api/about/xyz')
            ->assertStatus(500)
            ->assertJson(['error' => 'Cast to ObjectId failed for value "xyz" (type string) at path "parent_id" for model "About"']);
    }
}
