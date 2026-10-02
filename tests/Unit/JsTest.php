<?php

namespace Tests\Unit;

use App\Support\Js;
use App\Support\ObjectId;
use PHPUnit\Framework\TestCase;

class JsTest extends TestCase
{
    public function test_truthiness()
    {
        foreach ([null, false, '', 0, 0.0, NAN] as $falsy) {
            $this->assertFalse(Js::truthy($falsy));
        }

        foreach (['0', ' ', 1, -1, 0.5, [], ['a' => 1], true] as $truthy) {
            $this->assertTrue(Js::truthy($truthy));
        }
    }

    public function test_parse_int()
    {
        $this->assertSame(12, Js::parseInt('12abc'));
        $this->assertSame(1, Js::parseInt('1.9'));
        $this->assertSame(-7, Js::parseInt('  -7'));
        $this->assertSame(16, Js::parseInt('0x10'));
        $this->assertNan(Js::parseInt('abc'));
        $this->assertNan(Js::parseInt(''));
    }

    public function test_number_to_string()
    {
        $this->assertSame('1.5', Js::numberToString(1.5));
        $this->assertSame('123', Js::numberToString(123.0));
        $this->assertSame('0.1', Js::numberToString(0.1));
        $this->assertSame('0.000001', Js::numberToString(0.000001));
        $this->assertSame('1e-7', Js::numberToString(1e-7));
        $this->assertSame('1e+21', Js::numberToString(1e21));
        $this->assertSame('123456789012345680000', Js::numberToString(123456789012345678901.0));
    }

    public function test_string_to_number()
    {
        $this->assertSame(5.0, Js::stringToNumber(' 5 '));
        $this->assertSame(0.0, Js::stringToNumber('   '));
        $this->assertSame(26.0, Js::stringToNumber('0x1A'));
        $this->assertSame(1000.0, Js::stringToNumber('1e3'));
        $this->assertNan(Js::stringToNumber('12px'));
    }

    public function test_inspect_matches_node_util_inspect()
    {
        $this->assertSame("'abc'", Js::inspect('abc'));
        $this->assertSame('"it\'s"', Js::inspect("it's"));
        $this->assertSame('{ a: 1 }', Js::inspect(['a' => 1]));
        $this->assertSame("[ 'a', 'b' ]", Js::inspect(['a', 'b']));
        $this->assertSame("{ 'my-key': true, n: null }", Js::inspect(['my-key' => true, 'n' => null]));
        $this->assertSame('Object', Js::typeName(['a' => 1]));
        $this->assertSame('Array', Js::typeName([1]));
    }

    public function test_object_ids()
    {
        $id = ObjectId::generate();

        $this->assertRegExp('/^[0-9a-f]{24}$/', $id);
        $this->assertNotSame($id, ObjectId::generate());
        $this->assertSame(time(), hexdec(substr($id, 0, 8)), '', 2);
        $this->assertTrue(ObjectId::isValid('6ABE8416FF74716FDF7A0BB9'));
        $this->assertFalse(ObjectId::isValid('aaaaaaaaaaaa'));
        $this->assertFalse(ObjectId::isValid(123));
    }
}
