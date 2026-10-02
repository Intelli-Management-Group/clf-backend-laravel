<?php

namespace Tests\Unit;

use App\Support\JsDate;
use PHPUnit\Framework\TestCase;

/**
 * Expected values were produced by Node.js (`new Date(value).toISOString()`)
 * running in the Asia/Kolkata timezone.
 */
class JsDateTest extends TestCase
{
    const LOCAL = 'Asia/Kolkata';

    /**
     * @dataProvider validDates
     */
    public function test_parses_like_v8($value, string $expected)
    {
        $this->assertSame($expected, JsDate::toIsoString(JsDate::from($value, self::LOCAL)));
    }

    public function validDates(): array
    {
        return [
            ['2025-12-17', '2025-12-17T00:00:00.000Z'],
            ['2025', '2025-01-01T00:00:00.000Z'],
            ['2025-03', '2025-03-01T00:00:00.000Z'],
            ['2025-02-30', '2025-03-02T00:00:00.000Z'],
            ['2025-01-01Z', '2025-01-01T00:00:00.000Z'],
            ['2025-01-01T10:20:30', '2025-01-01T04:50:30.000Z'],
            ['2025-01-01t10:00', '2025-01-01T04:30:00.000Z'],
            ['2025-01-01T24:00', '2025-01-01T18:30:00.000Z'],
            ['2025-01-01T10:00:00.5', '2025-01-01T04:30:00.500Z'],
            ['2025-01-01T10:00:00.1234567Z', '2025-01-01T10:00:00.123Z'],
            ['2025-01-01T10:00:00z', '2025-01-01T10:00:00.000Z'],
            ['2025-03-04T05:06:07.123+02:00', '2025-03-04T03:06:07.123Z'],
            ['2025-01-01T10:00+0530', '2025-01-01T04:30:00.000Z'],
            ['+002025-01-01', '2025-01-01T00:00:00.000Z'],
            ['2025-03-04 05:06:07', '2025-03-03T23:36:07.000Z'],
            [' 2025-01-01 ', '2024-12-31T18:30:00.000Z'],
            ['2025/02/03', '2025-02-02T18:30:00.000Z'],
            ['2025.01.05', '2025-01-04T18:30:00.000Z'],
            ['1/5/2025', '2025-01-04T18:30:00.000Z'],
            ['05-01-2025', '2025-04-30T18:30:00.000Z'],
            ['March 5, 2025', '2025-03-04T18:30:00.000Z'],
            ['Jan 5 2025 10:30 PM', '2025-01-05T17:00:00.000Z'],
            ['Mon, 05 Jan 2025 10:00:00 GMT', '2025-01-05T10:00:00.000Z'],
            ['2025-01-01 10:00 GMT+0200', '2025-01-01T08:00:00.000Z'],
            ['Tue Oct 01 2026 21:00:00 GMT+0530 (India Standard Time)', '2026-10-01T15:30:00.000Z'],
            [null, '1970-01-01T00:00:00.000Z'],
            [false, '1970-01-01T00:00:00.000Z'],
            [true, '1970-01-01T00:00:00.001Z'],
            [0, '1970-01-01T00:00:00.000Z'],
            [1700000000123, '2023-11-14T22:13:20.123Z'],
        ];
    }

    /**
     * @dataProvider invalidDates
     */
    public function test_invalid_dates($value)
    {
        $this->assertNull(JsDate::from($value, self::LOCAL));
    }

    public function invalidDates(): array
    {
        return [['now'], ['tomorrow'], [''], ['garbage'], ['2025-13-01'], ['2025-01-01T25:00'],
            ['2025-01-01T24:00:01'], ['2025-01-01T10'], ['20250101']];
    }
}
