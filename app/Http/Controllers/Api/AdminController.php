<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AdminResource;
use App\Models\Admin;
use App\Support\Js;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AdminController extends Controller
{
    /**
     * POST /api/admin/login
     */
    public function login(Request $request)
    {
        try {
            $body = $this->body($request, 'username');
            $username = $this->field($body, 'username');
            $password = $this->field($body, 'password');

            if (! Js::truthy($username) || ! Js::truthy($password)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Please provide username and password',
                ], 400);
            }

            // Query values go through the schema setters (trim), as in Mongoose.
            $admin = is_array($username)
                ? null
                : Admin::query()->where('username', Admin::schema()->cast('username', $username))->first();

            if (! $admin) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            if (! Js::truthy($admin->is_active)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Admin account is deactivated',
                ], 401);
            }

            if (! is_string($password) || ! Hash::check($password, $admin->password)) {
                return $this->respond([
                    'success' => false,
                    'message' => 'Invalid credentials',
                ], 401);
            }

            return $this->respond([
                'success' => true,
                'message' => 'Login successful',
                'data' => (new AdminResource($admin))->resolve(),
            ]);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Error during login',
                'error' => $this->errorMessage($e),
            ], 500);
        }
    }

    /**
     * POST /api/admin/logout - stateless; the client discards its session.
     */
    public function logout()
    {
        return $this->respond([
            'success' => true,
            'message' => 'Logout successful',
        ]);
    }
}
