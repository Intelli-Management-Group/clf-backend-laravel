<?php

namespace App\Support;

use Carbon\Carbon;
use DateTime;
use DateTimeZone;

/**
 * Port of JavaScript's `new Date(value)` parsing.
 *
 * The Node.js backend converted request values with `new Date(...)`, which
 * follows V8's rules: ISO date-only strings ("2025-12-17") are UTC, while
 * date-times without an offset and the legacy formats ("12/17/2025",
 * "Dec 17, 2025") use the server's local timezone. That timezone is
 * configured with SERVER_TIMEZONE (see config/clf.php).
 */
final class JsDate
{
    /**
     * Largest absolute time value a JavaScript Date can hold (ms).
     */
    const MAX_TIME = 8.64e15;

    const ISO_PATTERN = '/^([+-]\d{6}|\d{4})(?:-(\d{2})(?:-(\d{2}))?)?(?:[Tt](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?)?([Zz]|[+-]\d{2}:?\d{2})?$/';

    const MONTHS = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * `new Date(value)`; returns null for an "Invalid Date".
     *
     * @param  mixed  $value
     */
    public static function from($value, string $localTimezone = null)
    {
        $localTimezone = $localTimezone ?: config('clf.server_timezone', 'UTC');

        if ($value === null || $value === false) {
            return self::fromMilliseconds(0);
        }

        if ($value === true) {
            return self::fromMilliseconds(1);
        }

        if (is_int($value) || is_float($value)) {
            return self::fromMilliseconds($value);
        }

        if (is_array($value)) {
            // ToPrimitive(array) joins the elements; objects become "[object Object]".
            $value = Js::toString($value);
        }

        return self::parse((string) $value, $localTimezone);
    }

    /**
     * Date#toISOString(), e.g. "2025-12-17T00:00:00.000Z".
     *
     * @param  \DateTimeInterface|null  $date
     */
    public static function toIsoString($date)
    {
        if ($date === null) {
            return null;
        }

        return Carbon::instance($date)->setTimezone('UTC')->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Build a UTC Carbon instance from an epoch-milliseconds time value.
     *
     * @param  int|float  $milliseconds
     */
    public static function fromMilliseconds($milliseconds)
    {
        if (is_float($milliseconds) && (is_nan($milliseconds) || is_infinite($milliseconds))) {
            return null;
        }

        // TimeClip(): truncate toward zero and enforce the valid range.
        $milliseconds = $milliseconds < 0 ? ceil($milliseconds) : floor($milliseconds);
        if (abs($milliseconds) > self::MAX_TIME) {
            return null;
        }

        $seconds = (int) floor($milliseconds / 1000);
        $remainder = (int) ($milliseconds - $seconds * 1000);

        return Carbon::createFromTimestampUTC($seconds)->setTimezone('UTC')
            ->addMicroseconds($remainder * 1000);
    }

    /**
     * Parse a date string with V8 semantics.
     */
    public static function parse(string $string, string $localTimezone)
    {
        if (preg_match(self::ISO_PATTERN, $string, $m)) {
            return self::fromIso($m, $localTimezone);
        }

        return self::fromLegacy($string, $localTimezone);
    }

    /**
     * ECMAScript date-time string format (with V8's tolerated extensions).
     */
    private static function fromIso(array $m, string $localTimezone)
    {
        $year = (int) $m[1];
        $month = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 1;
        $day = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 1;
        $hasTime = isset($m[4]) && $m[4] !== '';
        $hour = $hasTime ? (int) $m[4] : 0;
        $minute = $hasTime ? (int) $m[5] : 0;
        $second = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : 0;
        $millisecond = isset($m[7]) && $m[7] !== '' ? (int) substr(str_pad($m[7], 3, '0'), 0, 3) : 0;
        $offset = isset($m[8]) ? $m[8] : '';

        if ($m[1] === '-000000' || $month < 1 || $month > 12 || $day < 1 || $day > 31
            || $minute > 59 || $second > 59
            || $hour > 24 || ($hour === 24 && ($minute + $second + $millisecond) > 0)) {
            return null;
        }

        // Date-only forms are UTC; date-time forms without an offset are local time.
        if ($offset !== '') {
            $timezone = strtoupper($offset) === 'Z' ? 'UTC' : self::normalizeOffset($offset);
        } else {
            $timezone = $hasTime ? $localTimezone : 'UTC';
        }

        return self::build($year, $month, $day, $hour, $minute, $second, $millisecond, $timezone);
    }

    /**
     * V8's legacy (non-ISO) date parser for the formats people actually send.
     */
    private static function fromLegacy(string $string, string $localTimezone)
    {
        // Parenthesised comments such as "(India Standard Time)" are ignored.
        $text = trim(preg_replace('/\([^)]*\)/', ' ', $string));
        if ($text === '' || preg_match('/\b(now|today|tomorrow|yesterday|midnight|noon|next|last|ago|this)\b/i', $text)) {
            return null;
        }

        // Pull out the time of day together with an optional zone that follows it.
        $timezone = $localTimezone;
        $hour = $minute = $second = $millisecond = 0;
        $timePattern = '/\b(\d{1,2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?(?:\s*(am|pm)\b)?'
            .'(?:\s*(?:(?:GMT|UTC|UT)\s*)?([+-]\d{2}:?\d{2})\b|\s*\b(GMT|UTC|UT|Z)\b)?/i';

        if (preg_match($timePattern, $text, $t)) {
            $hour = (int) $t[1];
            $minute = (int) $t[2];
            $second = isset($t[3]) && $t[3] !== '' ? (int) $t[3] : 0;
            $millisecond = isset($t[4]) && $t[4] !== '' ? (int) substr(str_pad($t[4], 3, '0'), 0, 3) : 0;
            if (! empty($t[5])) {
                if ($hour > 12) {
                    return null;
                }
                $hour = $hour % 12 + (strtolower($t[5]) === 'pm' ? 12 : 0);
            }
            if ($hour > 24 || $minute > 59 || $second > 59) {
                return null;
            }
            if (! empty($t[6])) {
                $timezone = self::normalizeOffset($t[6]);
            } elseif (! empty($t[7])) {
                $timezone = 'UTC';
            }
            $text = str_replace($t[0], ' ', $text);
        }

        if (preg_match('/\b(GMT|UTC|UT)\b/i', $text, $tz)) {
            $timezone = 'UTC';
            $text = str_replace($tz[0], ' ', $text);
        }

        // Drop weekday names, then read the calendar date.
        $text = trim(preg_replace('/\b(mon|tue|wed|thu|fri|sat|sun)[a-z]*\.?,?/i', ' ', $text));
        $text = trim(preg_replace('/\s+/', ' ', $text), " ,");

        if (preg_match('/^(\d{4})[\/.-](\d{1,2})[\/.-](\d{1,2})$/', $text, $d)) {
            list($year, $month, $day) = [(int) $d[1], (int) $d[2], (int) $d[3]];
        } elseif (preg_match('/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{1,4})$/', $text, $d)) {
            // Numeric legacy dates are always month first in V8 (05-01-2025 is May 1).
            list($month, $day, $year) = [(int) $d[1], (int) $d[2], self::expandYear($d[3])];
        } elseif (preg_match('/^([a-z]{3})[a-z]*\.?\s+(\d{1,2}),?\s+(\d{1,4})$/i', $text, $d)
            && isset(self::MONTHS[strtolower($d[1])])) {
            list($month, $day, $year) = [self::MONTHS[strtolower($d[1])], (int) $d[2], self::expandYear($d[3])];
        } elseif (preg_match('/^(\d{1,2})\s+([a-z]{3})[a-z]*\.?,?\s+(\d{1,4})$/i', $text, $d)
            && isset(self::MONTHS[strtolower($d[2])])) {
            list($day, $month, $year) = [(int) $d[1], self::MONTHS[strtolower($d[2])], self::expandYear($d[3])];
        } elseif (preg_match('/^(\d{4})\s+([a-z]{3})[a-z]*\.?\s+(\d{1,2})$/i', $text, $d)
            && isset(self::MONTHS[strtolower($d[2])])) {
            list($year, $month, $day) = [(int) $d[1], self::MONTHS[strtolower($d[2])], (int) $d[3]];
        } else {
            return null;
        }

        if ($month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return self::build($year, $month, $day, $hour, $minute, $second, $millisecond, $timezone);
    }

    private static function build($year, $month, $day, $hour, $minute, $second, $millisecond, string $timezone)
    {
        $date = new DateTime('now', new DateTimeZone($timezone));
        $date->setDate($year, $month, $day);
        $date->setTime($hour, $minute, $second, $millisecond * 1000);

        $result = Carbon::instance($date)->setTimezone('UTC');

        $epochMs = $result->getTimestamp() * 1000 + $millisecond;

        return abs($epochMs) > self::MAX_TIME ? null : $result;
    }

    private static function normalizeOffset(string $offset): string
    {
        $offset = str_replace(':', '', $offset);

        return substr($offset, 0, 3).':'.substr($offset, 3, 2);
    }

    private static function expandYear(string $year): int
    {
        $value = (int) $year;

        // Two-digit years follow V8: 0-49 => 20xx, 50-99 => 19xx.
        if (strlen($year) <= 2) {
            return $value < 50 ? 2000 + $value : 1900 + $value;
        }

        return $value;
    }
}
