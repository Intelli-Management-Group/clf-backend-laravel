<?php

namespace Tests\Feature;

class GeneralTest extends ApiTestCase
{
    public function test_root_returns_welcome_message()
    {
        $this->get('/')
            ->assertStatus(200)
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertExactJson(['message' => 'Welcome to CLF Backend API']);
    }

    public function test_unknown_routes_return_the_express_404_page()
    {
        $response = $this->get('/api/nope?x=1');

        $response->assertStatus(404);
        $this->assertSame(
            "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>Error</title>\n</head>\n"
            ."<body>\n<pre>Cannot GET /api/nope</pre>\n</body>\n</html>\n",
            $response->getContent()
        );
    }

    public function test_unsupported_methods_are_unknown_routes_too()
    {
        $this->json('PATCH', '/api/news/1', [])
            ->assertStatus(404)
            ->assertSee('Cannot PATCH /api/news/1');
    }

    public function test_preflight_requests_are_answered_like_the_cors_package()
    {
        $this->call('OPTIONS', '/api/news', [], [], [], ['HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-foo'])
            ->assertStatus(204)
            ->assertHeader('Access-Control-Allow-Origin', '*')
            ->assertHeader('Access-Control-Allow-Methods', 'GET,HEAD,PUT,PATCH,POST,DELETE')
            ->assertHeader('Access-Control-Allow-Headers', 'content-type,x-foo')
            ->assertHeader('Vary', 'Access-Control-Request-Headers');
    }

    public function test_malformed_json_is_handled_by_the_error_middleware()
    {
        $this->call('POST', '/api/news', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => 10,
        ], '{"bad json')
            ->assertStatus(500)
            ->assertJson(['message' => 'Something went wrong!']);
    }

    public function test_json_bodies_over_10mb_are_rejected()
    {
        $this->call('POST', '/api/news', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'CONTENT_LENGTH' => 11 * 1024 * 1024,
        ], '{}')
            ->assertStatus(500)
            ->assertExactJson(['message' => 'Something went wrong!', 'error' => 'request entity too large']);
    }

    public function test_trailing_slashes_reach_the_same_route()
    {
        $this->get('/api/news/')->assertStatus(200)->assertJson(['success' => true]);
    }
}
