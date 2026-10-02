<?php

namespace App\Support;

/**
 * JavaScript value semantics used by the original Node.js backend.
 *
 * The Express controllers relied on JavaScript rules for truthiness checks,
 * `parseInt()`, `String(value)` and Node's `util.inspect()` (inside Mongoose
 * error messages). Reproducing those rules here keeps every response of the
 * Laravel port identical to the original API.
 */
final class Js
{
    /**
     * Characters JavaScript treats as white space (`\s`, String#trim, parseInt).
     */
    const WHITESPACE = '\x{0009}-\x{000D}\x{0020}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}';

    /**
     * JavaScript truthiness: `if (value)`.
     */
    public static function truthy($value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return false;
        }

        if (is_int($value)) {
            return $value !== 0;
        }

        if (is_float($value)) {
            return $value != 0.0 && ! is_nan($value);
        }

        // Non-empty strings, arrays and objects are always truthy.
        return true;
    }

    /**
     * String#trim() using JavaScript's definition of white space.
     */
    public static function trim(string $value): string
    {
        $trimmed = preg_replace('/^['.self::WHITESPACE.']+|['.self::WHITESPACE.']+$/u', '', $value);

        return $trimmed === null ? trim($value) : $trimmed;
    }

    /**
     * Replace every run of JavaScript white space (`/\s+/g`).
     */
    public static function replaceWhitespace(string $value, string $replacement): string
    {
        $replaced = preg_replace('/['.self::WHITESPACE.']+/u', $replacement, $value);

        return $replaced === null ? preg_replace('/\s+/', $replacement, $value) : $replaced;
    }

    /**
     * `parseInt(value)`: returns an int, or NAN when no digits could be read.
     *
     * @return int|float
     */
    public static function parseInt($value)
    {
        $string = preg_replace('/^['.self::WHITESPACE.']+/u', '', self::toString($value));
        $string = $string === null ? ltrim(self::toString($value)) : $string;

        $sign = 1;
        if ($string !== '' && ($string[0] === '-' || $string[0] === '+')) {
            $sign = $string[0] === '-' ? -1 : 1;
            $string = substr($string, 1);
        }

        if (preg_match('/^0[xX]([0-9a-fA-F]+)/', $string, $matches)) {
            $number = hexdec($matches[1]);
        } elseif (preg_match('/^[0-9]+/', $string, $matches)) {
            $number = strlen($matches[0]) < 19 ? (int) $matches[0] : (float) $matches[0];
        } else {
            return NAN;
        }

        return $number === 0 ? 0 : $sign * $number;
    }

    /**
     * `Number(string)`: returns a float, or NAN when the string is not numeric.
     */
    public static function stringToNumber(string $value): float
    {
        $string = self::trim($value);

        if ($string === '') {
            return 0.0;
        }

        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $string)) {
            return (float) $string;
        }

        if (preg_match('/^0x([0-9a-f]+)$/i', $string, $m)) {
            return (float) hexdec($m[1]);
        }

        if (preg_match('/^0b([01]+)$/i', $string, $m)) {
            return (float) bindec($m[1]);
        }

        if (preg_match('/^0o([0-7]+)$/i', $string, $m)) {
            return (float) octdec($m[1]);
        }

        if (preg_match('/^([+-]?)Infinity$/', $string, $m)) {
            return $m[1] === '-' ? -INF : INF;
        }

        return NAN;
    }

    /**
     * `String(value)`.
     */
    public static function toString($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return self::numberToString($value);
        }

        if (is_array($value)) {
            if (! self::isList($value)) {
                return '[object Object]';
            }

            return implode(',', array_map(function ($item) {
                return $item === null ? '' : self::toString($item);
            }, $value));
        }

        return (string) $value;
    }

    /**
     * Number#toString() - the shortest representation that round-trips.
     *
     * @param  int|float  $number
     */
    public static function numberToString($number): string
    {
        if (is_int($number)) {
            return (string) $number;
        }

        if (is_nan($number)) {
            return 'NaN';
        }

        if (is_infinite($number)) {
            return $number > 0 ? 'Infinity' : '-Infinity';
        }

        if ($number == 0.0) {
            return '0';
        }

        for ($precision = 0; $precision < 17; $precision++) {
            $repr = sprintf('%.'.$precision.'e', $number);
            if ((float) $repr === $number) {
                break;
            }
        }

        preg_match('/^(-?)(\d)(?:\.(\d+))?e([+-]\d+)$/', $repr, $m);
        $sign = $m[1];
        $digits = rtrim($m[2].(isset($m[3]) ? $m[3] : ''), '0');
        $digits = $digits === '' ? '0' : $digits;
        $k = strlen($digits);
        $n = (int) $m[4] + 1;

        if ($k <= $n && $n <= 21) {
            return $sign.$digits.str_repeat('0', $n - $k);
        }

        if (0 < $n && $n <= 21) {
            return $sign.substr($digits, 0, $n).'.'.substr($digits, $n);
        }

        if (-6 < $n && $n <= 0) {
            return $sign.'0.'.str_repeat('0', -$n).$digits;
        }

        $exponent = ($n - 1 >= 0 ? '+' : '-').abs($n - 1);

        return $k === 1
            ? $sign.$digits.'e'.$exponent
            : $sign.$digits[0].'.'.substr($digits, 1).'e'.$exponent;
    }

    /**
     * Normalize a number for JSON output the way JSON.stringify prints it
     * (integral doubles become integers, NaN/Infinity become null).
     *
     * @param  mixed  $value
     * @return mixed
     */
    public static function jsonNumber($value)
    {
        if (! is_float($value)) {
            return $value;
        }

        if (is_nan($value) || is_infinite($value)) {
            return null;
        }

        if (floor($value) === $value && abs($value) < 9007199254740992) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Node's `util.inspect()` output for the primitive / plain values a JSON
     * or form request body can contain.
     */
    public static function inspect($value, int $depth = 0): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_float($value) && $value == 0.0 && 1 / $value < 0) {
            return '-0';
        }

        if (is_int($value) || is_float($value)) {
            return self::numberToString($value);
        }

        if (is_string($value)) {
            return self::quote($value);
        }

        if (is_array($value)) {
            $isList = self::isList($value);

            if ($depth > 2) {
                return $isList ? '[Array]' : '[Object]';
            }

            if ($value === []) {
                return '[]';
            }

            $parts = [];
            foreach ($value as $key => $item) {
                $rendered = self::inspect($item, $depth + 1);

                if ($isList) {
                    $parts[] = $rendered;
                } else {
                    $key = (string) $key;
                    $label = preg_match('/^[A-Za-z_$][A-Za-z0-9_$]*$/', $key) ? $key : self::quote($key);
                    $parts[] = $label.': '.$rendered;
                }
            }

            return $isList ? '[ '.implode(', ', $parts).' ]' : '{ '.implode(', ', $parts).' }';
        }

        return (string) $value;
    }

    /**
     * The constructor name Mongoose reports for a value ("type string" etc).
     */
    public static function typeName($value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return 'boolean';
        }

        if (is_int($value) || is_float($value)) {
            return 'number';
        }

        if (is_string($value)) {
            return 'string';
        }

        if (is_array($value)) {
            return self::isList($value) ? 'Array' : 'Object';
        }

        return 'Object';
    }

    /**
     * Whether a PHP array represents a JavaScript array (vs. a plain object).
     */
    public static function isList(array $value): bool
    {
        return $value === [] || array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * Quote a string the way util.inspect does.
     */
    private static function quote(string $value): string
    {
        $quote = "'";
        if (strpos($value, "'") !== false) {
            if (strpos($value, '"') === false) {
                $quote = '"';
            } elseif (strpos($value, '`') === false && strpos($value, '${') === false) {
                $quote = '`';
            }
        }

        $escaped = '';
        $length = strlen($value);
        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];
            $code = ord($char);

            if ($char === '\\') {
                $escaped .= '\\\\';
            } elseif ($char === $quote) {
                $escaped .= '\\'.$quote;
            } elseif ($char === "\n") {
                $escaped .= '\\n';
            } elseif ($char === "\t") {
                $escaped .= '\\t';
            } elseif ($char === "\r") {
                $escaped .= '\\r';
            } elseif ($char === "\x08") {
                $escaped .= '\\b';
            } elseif ($char === "\x0C") {
                $escaped .= '\\f';
            } elseif ($char === "\x0B") {
                $escaped .= '\\v';
            } elseif ($code < 0x20 || $code === 0x7F) {
                $escaped .= sprintf('\\x%02X', $code);
            } else {
                $escaped .= $char;
            }
        }

        return $quote.$escaped.$quote;
    }
}
