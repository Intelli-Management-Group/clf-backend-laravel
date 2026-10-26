<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class AdminTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\AdminSeeder::class);
    }

    public function test_seeded_admin_can_log_in()
    {
        $admin = Admin::query()->firstOrFail();

        $this->postJson('/api/admin/login', ['username' => 'admin', 'password' => 'admin123'])
            ->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'id' => $admin->id,
                    'username' => 'admin',
                    'email' => 'admin@clf.com',
                    'role' => 'admin',
                ],
            ]);
    }

    public function test_username_is_trimmed_but_case_sensitive()
    {
        $this->postJson('/api/admin/login', ['username' => ' admin ', 'password' => 'admin123'])->assertStatus(200);
        $this->postJson('/api/admin/login', ['username' => 'Admin', 'password' => 'admin123'])->assertStatus(401);
    }

    public function test_wordpress_bcrypt_password_can_log_in_and_is_upgraded()
    {
        $admin = Admin::query()->firstOrFail();
        $admin->password = '$wp'.Hash::make('$wp'.'admin123');
        $admin->save();

        $this->postJson('/api/admin/login', ['username' => 'admin', 'password' => 'admin123'])
            ->assertStatus(200);

        $admin->refresh();
        $this->assertStringStartsWith('$2y$', $admin->password);
        $this->assertTrue(Hash::check('admin123', $admin->password));
    }

    public function test_wordpress_portable_password_can_log_in_and_is_upgraded()
    {
        $admin = Admin::query()->firstOrFail();
        $admin->password = $this->portableHash('admin123', '$P$B12345678');
        $admin->save();

        $this->postJson('/api/admin/login', ['username' => 'admin', 'password' => 'admin123'])
            ->assertStatus(200);

        $admin->refresh();
        $this->assertStringStartsWith('$2y$', $admin->password);
        $this->assertTrue(Hash::check('admin123', $admin->password));
    }

    public function test_invalid_credentials()
    {
        foreach ([['admin', 'wrong'], ['nobody', 'admin123']] as list($username, $password)) {
            $this->postJson('/api/admin/login', compact('username', 'password'))
                ->assertStatus(401)
                ->assertExactJson(['success' => false, 'message' => 'Invalid credentials']);
        }
    }

    public function test_missing_credentials()
    {
        $this->postJson('/api/admin/login', ['username' => 'admin'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Please provide username and password']);
    }

    public function test_deactivated_admin_is_rejected_before_the_password_check()
    {
        Admin::query()->update(['is_active' => false]);

        $this->postJson('/api/admin/login', ['username' => 'admin', 'password' => 'anything'])
            ->assertStatus(401)
            ->assertExactJson(['success' => false, 'message' => 'Admin account is deactivated']);
    }

    public function test_request_without_body()
    {
        $this->withoutBody('POST', '/api/admin/login')
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Error during login',
                'error' => "Cannot destructure property 'username' of 'req.body' as it is undefined.",
            ]);
    }

    public function test_logout()
    {
        $this->withoutBody('POST', '/api/admin/logout')
            ->assertStatus(200)
            ->assertExactJson(['success' => true, 'message' => 'Logout successful']);
    }

    private function portableHash(string $password, string $setting): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $count = 1 << strpos($alphabet, $setting[3]);
        $hash = md5(substr($setting, 4, 8).$password, true);

        for (; $count > 0; $count--) {
            $hash = md5($hash.$password, true);
        }

        $output = substr($setting, 0, 12);
        $index = 0;
        do {
            $value = ord($hash[$index++]);
            $output .= $alphabet[$value & 0x3F];
            if ($index < 16) {
                $value |= ord($hash[$index]) << 8;
            }
            $output .= $alphabet[($value >> 6) & 0x3F];
            if ($index++ >= 16) {
                break;
            }
            if ($index < 16) {
                $value |= ord($hash[$index]) << 16;
            }
            $output .= $alphabet[($value >> 12) & 0x3F];
            if ($index++ >= 16) {
                break;
            }
            $output .= $alphabet[($value >> 18) & 0x3F];
        } while ($index < 16);

        return $output;
    }
}
