<?php

namespace App\Services;

use App\Support\ObjectId;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WordPressUserImporter
{
    public function import(string $usersFile, ?string $usermetaFile, ?string $adminIds, bool $dryRun = false): int
    {
        if (! is_file($usersFile) || ! is_readable($usersFile)) {
            throw new RuntimeException('The WordPress users SQL file is missing or unreadable.');
        }

        $adminUserIds = $this->adminUserIds($usermetaFile, $adminIds);
        $users = $this->readInsertRows($usersFile, 'clf_users');
        $selectedUsers = [];

        foreach ($users as $user) {
            $userId = (string) $this->column($user, 'ID');
            if (isset($adminUserIds[$userId])) {
                $selectedUsers[] = $user;
            }
        }

        foreach ($adminUserIds as $userId => $_) {
            if (! $this->containsUser($users, (string) $userId)) {
                throw new RuntimeException("Administrator ID [{$userId}] was not found in clf_users.");
            }
        }

        if ($selectedUsers === []) {
            throw new RuntimeException('No WordPress administrator accounts were selected for import.');
        }

        $prepared = $this->prepareUsers($selectedUsers);
        if ($dryRun) {
            foreach ($prepared as $user) {
                $this->existingAdmin($user);
            }

            return count($prepared);
        }

        DB::transaction(function () use ($prepared) {
            foreach ($prepared as $user) {
                $existing = $this->existingAdmin($user);
                $values = [
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'password' => $user['password'],
                    'role' => 'admin',
                    'is_active' => true,
                    'created_at' => $user['created_at'],
                    'updated_at' => $user['created_at'],
                ];

                if ($existing) {
                    if (preg_match('/^\$(?:2[aby]\$\d\d\$|argon2(?:id|i)\$)/', $existing->password) === 1) {
                        $values['password'] = $existing->password;
                    }
                    DB::table('admins')->where('id', $existing->id)->update($values);
                } else {
                    DB::table('admins')->insert(['id' => ObjectId::generate()] + $values);
                }
            }
        });

        return count($prepared);
    }

    private function existingAdmin(array $user)
    {
        $matches = DB::table('admins')
            ->where('username', $user['username'])
            ->orWhere('email', $user['email'])
            ->get();

        if ($matches->count() > 1
            || ($matches->isNotEmpty()
                && ($matches->first()->username !== $user['username']
                    || strcasecmp($matches->first()->email, $user['email']) !== 0))) {
            throw new RuntimeException(
                "WordPress administrator ID [{$user['source_id']}] conflicts with an existing admin account."
            );
        }

        return $matches->first();
    }

    private function adminUserIds(?string $usermetaFile, ?string $adminIds): array
    {
        if (($usermetaFile === null) === ($adminIds === null)) {
            throw new RuntimeException('Provide exactly one of --usermeta-file or --admin-ids.');
        }

        if ($adminIds !== null) {
            $ids = array_filter(array_map('trim', explode(',', $adminIds)), 'strlen');
            if ($ids === []) {
                throw new RuntimeException('The --admin-ids option did not contain any IDs.');
            }

            return array_fill_keys($ids, true);
        }

        if (! is_file($usermetaFile) || ! is_readable($usermetaFile)) {
            throw new RuntimeException('The WordPress usermeta SQL file is missing or unreadable.');
        }

        $adminUserIds = [];
        foreach ($this->readInsertRows($usermetaFile, 'clf_usermeta') as $meta) {
            $key = (string) $this->column($meta, 'meta_key');
            if (! str_ends_with($key, '_capabilities')) {
                continue;
            }

            $capabilities = @unserialize((string) $this->column($meta, 'meta_value'), ['allowed_classes' => false]);
            if (is_array($capabilities) && ! empty($capabilities['administrator'])) {
                $adminUserIds[(string) $this->column($meta, 'user_id')] = true;
            }
        }

        if ($adminUserIds === []) {
            throw new RuntimeException('No WordPress administrator role entries were found in clf_usermeta.');
        }

        return $adminUserIds;
    }

    private function prepareUsers(array $users): array
    {
        $prepared = [];
        $seenUsernames = [];
        $seenEmails = [];

        foreach ($users as $user) {
            $sourceId = (string) $this->column($user, 'ID');
            $username = trim((string) $this->column($user, 'user_login'));
            $email = trim((string) $this->column($user, 'user_email'));
            $password = (string) $this->column($user, 'user_pass');

            if ($sourceId === '' || $username === '' || $email === '' || $password === '') {
                throw new RuntimeException(
                    "WordPress administrator ID [{$sourceId}] is missing a login, email, or password hash."
                );
            }

            $usernameKey = strtolower($username);
            $emailKey = strtolower($email);
            if (isset($seenUsernames[$usernameKey]) || isset($seenEmails[$emailKey])) {
                throw new RuntimeException('The selected WordPress administrators contain duplicate usernames or emails.');
            }
            $seenUsernames[$usernameKey] = true;
            $seenEmails[$emailKey] = true;

            $registered = (string) $this->column($user, 'user_registered');
            $createdAt = $registered !== '' && $registered !== '0000-00-00 00:00:00'
                && strtotime($registered) !== false
                ? date('Y-m-d H:i:s', strtotime($registered))
                : null;

            $prepared[] = [
                'source_id' => $sourceId,
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'created_at' => $createdAt,
            ];
        }

        return $prepared;
    }

    private function readInsertRows(string $file, string $table): array
    {
        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new RuntimeException("Could not read the SQL file for [{$table}].");
        }

        $pattern = '/INSERT\s+INTO\s+`?'.preg_quote($table, '/').'`?\s*\(([^)]*)\)\s*VALUES\s*/i';
        preg_match_all($pattern, $sql, $matches, PREG_OFFSET_CAPTURE);
        if ($matches[0] === []) {
            throw new RuntimeException("No INSERT statement for [{$table}] was found in the SQL file.");
        }

        $rows = [];
        foreach ($matches[0] as $statementIndex => $match) {
            $columns = array_map(
                static fn ($column) => trim($column, " \t\n\r\0\x0B`"),
                explode(',', $matches[1][$statementIndex][0])
            );
            $offset = $match[1] + strlen($match[0]);
            $rows = array_merge($rows, $this->parseRows($sql, $offset, $columns, $table));
        }

        return $rows;
    }

    private function parseRows(string $sql, int &$offset, array $columns, string $table): array
    {
        $length = strlen($sql);
        $rows = [];

        while ($offset < $length) {
            $this->skipWhitespace($sql, $offset);
            if ($offset >= $length || $sql[$offset] === ';') {
                break;
            }

            if ($sql[$offset] === ',') {
                $offset++;

                continue;
            }
            if ($sql[$offset] !== '(') {
                throw new RuntimeException("Could not parse an INSERT row for [{$table}].");
            }
            $offset++;

            $values = [];
            while ($offset < $length) {
                $this->skipWhitespace($sql, $offset);
                $values[] = $this->parseValue($sql, $offset, $table);
                $this->skipWhitespace($sql, $offset);

                if ($offset < $length && $sql[$offset] === ',') {
                    $offset++;

                    continue;
                }
                if ($offset < $length && $sql[$offset] === ')') {
                    $offset++;
                    break;
                }
                throw new RuntimeException("Could not parse an INSERT value for [{$table}].");
            }

            if (count($values) !== count($columns)) {
                throw new RuntimeException("The INSERT column count does not match its values for [{$table}].");
            }
            $rows[] = array_combine($columns, $values);
        }

        return $rows;
    }

    private function parseValue(string $sql, int &$offset, string $table)
    {
        $length = strlen($sql);
        if ($offset >= $length) {
            throw new RuntimeException("Unexpected end of SQL while parsing [{$table}].");
        }

        if ($sql[$offset] === "'" || $sql[$offset] === '"') {
            $quote = $sql[$offset++];
            $value = '';
            while ($offset < $length) {
                $character = $sql[$offset++];
                if ($character === '\\' && $offset < $length) {
                    $escaped = $sql[$offset++];
                    $value .= match ($escaped) {
                        '0' => "\0",
                        'b' => "\x08",
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        'Z' => "\x1a",
                        default => $escaped,
                    };

                    continue;
                }
                if ($character === $quote) {
                    if ($offset < $length && $sql[$offset] === $quote) {
                        $value .= $quote;
                        $offset++;

                        continue;
                    }

                    return $value;
                }
                $value .= $character;
            }

            throw new RuntimeException("Unterminated SQL string while parsing [{$table}].");
        }

        $start = $offset;
        while ($offset < $length && $sql[$offset] !== ',' && $sql[$offset] !== ')') {
            $offset++;
        }
        $value = trim(substr($sql, $start, $offset - $start));

        return strtoupper($value) === 'NULL' ? null : $value;
    }

    private function skipWhitespace(string $sql, int &$offset): void
    {
        $length = strlen($sql);
        while ($offset < $length && ctype_space($sql[$offset])) {
            $offset++;
        }
    }

    private function column(array $row, string $name)
    {
        foreach ($row as $column => $value) {
            if (strcasecmp($column, $name) === 0) {
                return $value;
            }
        }

        throw new RuntimeException("Required column [{$name}] was missing from the WordPress SQL dump.");
    }

    private function containsUser(array $users, string $id): bool
    {
        foreach ($users as $user) {
            if ((string) $this->column($user, 'ID') === $id) {
                return true;
            }
        }

        return false;
    }
}
