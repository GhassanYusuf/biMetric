<?php

namespace Tests\Unit;

use App\Support\VerifyMethod;
use PHPUnit\Framework\TestCase;

/**
 * The PDF exports (VerifyMethod.php) and the screens (attendance/parts.jsx) label
 * verify methods from two copies of one vendor dictionary. They must not drift, or a
 * punch reads "Card" on screen and something else on paper.
 */
class VerifyMethodLabelsTest extends TestCase
{
    public function test_the_screen_and_the_pdf_use_the_same_labels(): void
    {
        $js = file_get_contents(__DIR__.'/../../resources/js/spa/pages/attendance/parts.jsx');
        $block = substr($js, strpos($js, 'const VERIFY_METHODS = {'));
        $block = substr($block, 0, strpos($block, '};'));
        preg_match_all("/^\s*(\d+): '([^']+)',/m", $block, $m, PREG_SET_ORDER);

        $this->assertNotEmpty($m);
        foreach ($m as [, $code, $label]) {
            $this->assertSame($label, VerifyMethod::label((int) $code), "code {$code}");
        }

        $php = (new \ReflectionClass(VerifyMethod::class))->getConstant('LABELS');
        $this->assertCount(count($php), $m, 'parts.jsx and VerifyMethod.php list different codes');
    }

    public function test_an_unlisted_code_is_not_guessed(): void
    {
        $this->assertSame('Unknown (48)', VerifyMethod::label(48));
        $this->assertSame('—', VerifyMethod::label(null));
    }
}
