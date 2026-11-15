<?php

namespace Tests\Unit;

use App\Support\Utf8;
use PHPUnit\Framework\TestCase;

/** DC-046 (2026-11-15): a terminal name in a firmware code page stopped a whole roster read. */
class Utf8NameTest extends TestCase
{
    public function test_a_valid_name_is_returned_unchanged(): void
    {
        foreach (['ALI MOHAMED', 'RAGESH THATHAMPULLY', 'محمد', 'Renée', '  two  spaces  '] as $name) {
            $this->assertSame($name, Utf8::name($name));
        }
    }

    public function test_a_windows_1252_name_becomes_storable_text(): void
    {
        $this->assertSame('DIRISAM PRADEEP', Utf8::name("DIRISAM\xA0PRADEEP")); // gems workshop, user 2355
        $this->assertSame('Renée', Utf8::name("Ren\xE9e"));
        $this->assertTrue(mb_check_encoding(Utf8::name("\x81\x8D\x8F"), 'UTF-8'));
    }
}
