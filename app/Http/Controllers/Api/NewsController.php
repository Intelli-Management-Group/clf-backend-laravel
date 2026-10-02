<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NewsResource;
use App\Models\News;
use App\Support\Js;
use App\Support\JsDate;
use App\Support\ObjectId;
use Illuminate\Http\Request;
use RuntimeException;

class NewsController extends Controller
{
    /**
     * GET /api/news?page=1&limit=10 - all news (newest first), paginated.
     */
    public function index(Request $request)
    {
        try {
            $page = Js::parseInt($this->queryValue($request, 'page', 1));
            $limit = Js::parseInt($this->queryValue($request, 'limit', 10));

            $length = News::query()->count();
            $totalPages = $this->divideAndCeil($length, $limit);
            $skip = ($page - 1) * $limit;

            // MongoDB ignores NaN for skip/limit, treats limit 0 as "no limit"
            // and rejects a negative skip.
            if (! is_nan($skip) && $skip < 0) {
                throw new RuntimeException("BSON field 'skip' value must be >= 0, actual value '".Js::numberToString($skip)."'");
            }

            $query = News::query()->orderBy('date', 'desc')->orderBy('created_at')->orderBy('id');

            if (! is_nan($limit) && $limit != 0) {
                $query->take((int) abs($limit));
            }

            if (! is_nan($skip) && $skip > 0) {
                $query->skip((int) $skip);
                if (is_nan($limit) || $limit == 0) {
                    $query->take(PHP_INT_MAX);
                }
            }

            return $this->respond([
                'success' => true,
                'length' => $length,
                'page' => Js::jsonNumber($page),
                'totalpages' => $totalPages,
                'data' => NewsResource::many($query->get()),
            ]);
        } catch (\Throwable $e) {
            return $this->respond(['success' => false, 'error' => $this->errorMessage($e)], 500);
        }
    }

    /**
     * GET /api/news/{id}
     */
    public function show(string $id)
    {
        try {
            $news = News::findById($id);

            if (! $news) {
                return $this->respond(['success' => false, 'message' => 'News not found'], 404);
            }

            return $this->respond([
                'success' => true,
                'data' => (new NewsResource($news))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond(['success' => false, 'error' => $this->errorMessage($e)], 500);
        }
    }

    /**
     * POST /api/news
     */
    public function store(Request $request)
    {
        try {
            $body = $this->body($request, 'title');

            if ($this->slugExists($this->field($body, 'slug'))) {
                return $this->respond([
                    'success' => false,
                    'message' => 'News with this slug already exists',
                ], 400);
            }

            // new Date(date).toISOString(): a missing or unparsable date is a RangeError.
            $date = array_key_exists('date', $body) ? JsDate::from($body['date']) : null;
            if ($date === null) {
                throw new RuntimeException('Invalid time value');
            }

            $attributes = News::schema()->forCreate(
                $this->present($body, ['title', 'description', 'slug', 'isActive']) + ['date' => $date]
            );

            $news = (new News)->fillFromApi($attributes);
            $news->save();

            return $this->respond([
                'success' => true,
                'message' => 'News added successfully',
                'data' => (new NewsResource($news))->resolve(),
            ], 201);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error creating News content',
                'error' => $this->errorMessage($e),
            ], 500);
        }
    }

    /**
     * PUT /api/news/{id}
     */
    public function update(Request $request, string $id)
    {
        try {
            $body = $this->body($request, 'title');

            if (! ObjectId::isValid($id)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Invalid News ID',
                ], 400);
            }

            $changes = News::schema()->forUpdate(
                $this->present($body, ['title', 'description', 'date', 'slug', 'isActive']),
                true
            );

            $news = News::query()->find(strtolower($id));

            if (! $news) {
                return $this->respond([
                    'success' => false,
                    'message' => 'News not found',
                ], 404);
            }

            $news->fillFromApi($changes)->touch();

            return $this->respond([
                'success' => true,
                'message' => 'News updated successfully',
                'data' => (new NewsResource($news))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error updating News content',
                'error' => $this->errorMessage($e, true),
            ], 500);
        }
    }

    /**
     * DELETE /api/news/{id}
     */
    public function destroy(string $id)
    {
        try {
            $news = News::findById($id);

            if (! $news) {
                return $this->respond(['success' => false, 'message' => 'News not found'], 404);
            }

            $news->delete();

            return $this->respond(['success' => true, 'message' => 'News deleted successfully']);
        } catch (\Throwable $e) {
            return $this->respond(['success' => false, 'error' => $this->errorMessage($e)], 500);
        }
    }

    /**
     * A query-string value, or the default when it is absent.
     */
    private function queryValue(Request $request, string $key, $default)
    {
        $value = $request->query($key);

        return is_string($value) ? $value : $default;
    }

    /**
     * Math.ceil(length / limit) serialized like JSON.stringify (NaN/Infinity => null).
     *
     * @param  int|float  $limit
     * @return int|null
     */
    private function divideAndCeil(int $length, $limit)
    {
        if (is_nan($limit) || $limit == 0) {
            return null;
        }

        return (int) ceil($length / $limit);
    }

    /**
     * News.findOne({ slug }) for the duplicate check.
     */
    private function slugExists($slug): bool
    {
        if ($slug === null || is_array($slug)) {
            return false;
        }

        return News::query()->where('slug', Js::toString($slug))->exists();
    }
}
