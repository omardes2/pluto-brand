<?php

namespace Tests\Feature\Pos;

use App\Modules\Pos\Support\Code128;
use PHPUnit\Framework\TestCase;

class Code128Test extends TestCase
{
    public function test_encode_b_produces_start_checksum_and_stop(): void
    {
        // 'A' → القيمة 33؛ التحقّق = (104 + 1×33) % 103 = 34.
        $this->assertSame([104, 33, 34, 106], Code128::encodeB('A'));
    }

    public function test_module_count_matches_symbol_patterns(): void
    {
        // بداية(11) + محرف(11) + تحقّق(11) + إيقاف(13) = 46 وحدة.
        $this->assertSame(46, strlen(Code128::modules('A')));

        // كل محرف إضافي يضيف 11 وحدة.
        $this->assertSame(46 + 11 * 4, strlen(Code128::modules('12345')));
    }

    public function test_empty_input_encodes_a_single_space(): void
    {
        // فراغ واحد: قيمته 0، التحقّق = 104 % 103 = 1.
        $this->assertSame([104, 0, 1, 106], Code128::encodeB(''));
    }

    public function test_svg_is_well_formed_black_on_white(): void
    {
        $svg = Code128::svg('98055', 40, 1.4);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringContainsString('</svg>', $svg);
        $this->assertStringContainsString('fill="#fff"', $svg); // خلفية بيضاء
        $this->assertStringContainsString('fill="#000"', $svg); // أشرطة سوداء
        $this->assertStringContainsString('height="40"', $svg);
    }
}
