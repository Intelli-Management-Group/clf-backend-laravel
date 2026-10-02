<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AboutResource;
use App\Models\About;
use App\Support\Js;
use Illuminate\Http\Request;

class AboutController extends Controller
{
    /**
     * GET /api/about - active items as parents with their children.
     * Optional filter: ?category=style|biographies
     */
    public function index(Request $request)
    {
        try {
            $query = About::query()->with('parent')->where('is_active', true);

            $category = $request->query('category');
            if (is_string($category) && $category !== '') {
                $query->where('category', $category);
            }

            $items = $query->orderBy('order')->orderBy('created_at', 'desc')->get();

            // Items whose (populated) parent is missing are top-level parents.
            $parents = $items->filter(function (About $item) {
                return $item->parent === null;
            });
            $children = $items->filter(function (About $item) {
                return $item->parent !== null;
            });

            $data = [];
            foreach ($parents as $parent) {
                $ownChildren = $children->filter(function (About $child) use ($parent) {
                    return $child->parent->id === $parent->id;
                });

                $data[] = (new AboutResource($parent))->resolve() + [
                    'children' => AboutResource::many($ownChildren),
                ];
            }

            return $this->respond([
                'success' => true,
                'count' => count($data),
                'data' => $data,
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error fetching about content',
                'error' => $this->errorMessage($e),
            ], 500);
        }
    }

    /**
     * GET /api/about/{id}
     */
    public function show(string $id)
    {
        try {
            $about = About::findById($id);

            if (! $about) {
                return $this->respond([
                    'success' => false,
                    'message' => 'About content not found',
                ], 404);
            }

            $about->load('parent');

            return $this->respond([
                'success' => true,
                'data' => (new AboutResource($about))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error fetching about content',
                'error' => $this->errorMessage($e),
            ], 500);
        }
    }

    /**
     * POST /api/about
     */
    public function store(Request $request)
    {
        try {
            $body = $this->body($request, 'name');
            $name = $this->field($body, 'name');
            $title = $this->field($body, 'title');
            $category = $this->field($body, 'category');
            $description = $this->field($body, 'description');
            $parentId = $this->field($body, 'parent_id');
            $order = $this->field($body, 'order');

            if (! Js::truthy($name) || ! Js::truthy($title) || ! Js::truthy($category) || ! Js::truthy($description)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Please provide all required fields: name, title, category, and description',
                ], 400);
            }

            if (Js::truthy($parentId) && ! About::findById($parentId)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Parent content not found',
                ], 404);
            }

            $attributes = About::schema()->forCreate([
                'name' => $name,
                'title' => $title,
                'category' => $category,
                'description' => $description,
                'parent_id' => Js::truthy($parentId) ? $parentId : null,
                'order' => Js::truthy($order) ? $order : 0,
            ]);

            $about = (new About)->fillFromApi($attributes);
            $about->save();

            return $this->respond([
                'success' => true,
                'message' => 'About content created successfully',
                'data' => (new AboutResource($about))->resolve(),
            ], 201);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error creating about content',
                'error' => $this->errorMessage($e),
            ], 500);
        }
    }

    /**
     * PUT /api/about/{id}
     */
    public function update(Request $request, string $id)
    {
        try {
            $body = $this->body($request, 'name');
            $parentId = $this->field($body, 'parent_id');

            // A parent can be changed to any existing item (or to the item itself).
            if (Js::truthy($parentId) && $parentId !== $id && ! About::findById($parentId)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Parent content not found',
                ], 404);
            }

            $updates = $this->present($body, ['name', 'title', 'category', 'description']);
            if (array_key_exists('parent_id', $body)) {
                $updates['parent_id'] = Js::truthy($parentId) ? $parentId : null;
            }
            $updates += $this->present($body, ['order', 'isActive']);

            $key = About::castId($id);
            $changes = About::schema()->forUpdate($updates, true);
            $about = About::query()->find($key);

            if (! $about) {
                return $this->respond([
                    'success' => false,
                    'message' => 'About content not found',
                ], 404);
            }

            $about->fillFromApi($changes)->touch();
            $about->load('parent');

            return $this->respond([
                'success' => true,
                'message' => 'About content updated successfully',
                'data' => (new AboutResource($about))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error updating about content',
                'error' => $this->errorMessage($e, true),
            ], 500);
        }
    }

    /**
     * DELETE /api/about/{id} - refused while the item has children.
     */
    public function destroy(string $id)
    {
        try {
            $key = About::castId($id, 'parent_id');

            if (About::query()->where('parent_id', $key)->exists()) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Cannot delete content with child items. Please delete or reassign child items first.',
                ], 400);
            }

            $about = About::query()->find($key);

            if (! $about) {
                return $this->respond([
                    'success' => false,
                    'message' => 'About content not found',
                ], 404);
            }

            $about->delete();

            return $this->respond([
                'success' => true,
                'message' => 'About content deleted successfully',
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error deleting about content',
                'error' => $this->errorMessage($e),
            ], 500);
        }
    }
}
