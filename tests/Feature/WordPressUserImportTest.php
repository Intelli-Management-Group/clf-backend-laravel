<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\WordPressUserImporter;
use Illuminate\Support\Facades\Hash;

class WordPressUserImportTest extends ApiTestCase
{
    private array $sqlFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->sqlFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_only_wordpress_administrators_are_imported_and_can_log_in()
    {
        $wordpressHash = '$wp'.Hash::make('$wp'.'admin-password');
        $usersFile = $this->sqlFile('users', sprintf(
            <<<'SQL'
INSERT INTO `clf_users` (`ID`, `user_login`, `user_pass`, `user_email`, `user_registered`) VALUES
(1, 'wp-admin', '%s', 'admin@example.com', '2024-01-02 03:04:05'),
(2, 'regular-user', 'not-an-admin-hash', 'user@example.com', '2024-01-02 03:04:05');
SQL,
            $wordpressHash
        ));
        $usermetaFile = $this->sqlFile('usermeta', <<<'SQL'
INSERT INTO `clf_usermeta` (`umeta_id`, `user_id`, `meta_key`, `meta_value`) VALUES
(1, 1, 'clf_capabilities', 'a:1:{s:13:"administrator";b:1;}'),
(2, 2, 'clf_capabilities', 'a:1:{s:10:"subscriber";b:1;}');
SQL);

        $importer = app(WordPressUserImporter::class);
        $this->assertSame(1, $importer->import($usersFile, $usermetaFile, null, true));
        $this->assertSame(0, Admin::query()->count());
        $this->assertSame(1, $importer->import($usersFile, $usermetaFile, null));

        $admin = Admin::query()->firstOrFail();
        $this->assertSame('wp-admin', $admin->username);
        $this->assertSame('admin@example.com', $admin->email);
        $this->assertSame($wordpressHash, $admin->password);
        $this->assertSame('admin', $admin->role);
        $this->assertTrue($admin->is_active);
        $this->assertSame(1, Admin::query()->count());

        $this->postJson('/api/admin/login', [
            'username' => 'wp-admin',
            'password' => 'admin-password',
        ])->assertStatus(200);

        $admin->refresh();
        $this->assertTrue(Hash::check('admin-password', $admin->password));
        $this->assertSame(1, $importer->import($usersFile, $usermetaFile, null));
        $this->assertTrue(Hash::check('admin-password', $admin->fresh()->password));
        $this->assertSame(1, Admin::query()->count());
    }

    private function sqlFile(string $name, string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'clf-wp-'.$name.'-');
        file_put_contents($file, $contents);
        $this->sqlFiles[] = $file;

        return $file;
    }
}
