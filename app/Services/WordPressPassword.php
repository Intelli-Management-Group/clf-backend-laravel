<?php

namespace App\Services;

use App\Models\Admin;
use Illuminate\Support\Facades\Hash;

class WordPressPassword
{
    public function checkAndUpgrade(Admin $admin, string $password): bool
    {
        $stored = (string) $admin->password;

        if (preg_match('/^\$wp\$2[aby]\$/', $stored) === 1) {
            $laravelHash = substr($stored, 3);
            $valid = password_verify('$wp'.$password, $laravelHash);
        } elseif (preg_match('/^\$2[aby]\$\d\d\$/', $stored) === 1) {
            $valid = Hash::check($password, $stored);
        } elseif (preg_match('/^\$[PH]\$/', $stored) === 1) {
            $valid = $this->checkPortableHash($password, $stored);
        } else {
            $valid = false;
        }

        if (! $valid) {
            return false;
        }

        if (preg_match('/^\$wp\$2[aby]\$/', $stored) === 1
            || preg_match('/^\$[PH]\$/', $stored) === 1
            || Hash::needsRehash($stored)) {
            $admin->password = Hash::make($password);
            $admin->save();
        }

        return true;
    }

    private function checkPortableHash(string $password, string $stored): bool
    {
        if (strlen($stored) !== 34) {
            return false;
        }

        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $countLog2 = strpos($alphabet, $stored[3]);

        if ($countLog2 === false || $countLog2 < 7 || $countLog2 > 30) {
            return false;
        }

        $salt = substr($stored, 4, 8);
        if (strlen($salt) !== 8) {
            return false;
        }

        $hash = md5($salt.$password, true);
        for ($count = 1 << $countLog2; $count > 0; $count--) {
            $hash = md5($hash.$password, true);
        }

        $calculated = substr($stored, 0, 12).$this->encode64($hash, 16);

        return hash_equals($stored, $calculated);
    }

    private function encode64(string $input, int $count): string
    {
        $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
        $output = '';
        $index = 0;

        do {
            $value = ord($input[$index++]);
            $output .= $alphabet[$value & 0x3F];

            if ($index < $count) {
                $value |= ord($input[$index]) << 8;
            }
            $output .= $alphabet[($value >> 6) & 0x3F];

            if ($index++ >= $count) {
                break;
            }

            if ($index < $count) {
                $value |= ord($input[$index]) << 16;
            }
            $output .= $alphabet[($value >> 12) & 0x3F];

            if ($index++ >= $count) {
                break;
            }

            $output .= $alphabet[($value >> 18) & 0x3F];
        } while ($index < $count);

        return $output;
    }
}
