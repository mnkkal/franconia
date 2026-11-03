<?php

namespace Tests\Unit;

use App\Support\ByobPricing;
use Tests\TestCase;

class ByobPricingTest extends TestCase
{
    public function test_all_reference_totals_and_repeat_bundles(): void
    {
        foreach (['20ML' => [79900, 149900, 209900, 259900, 299900], '50ML' => [159900, 299900, 419900, 519900, 599900]] as $size => $totals) {
            foreach ($totals as $i => $total) {
                $this->assertSame($total, ByobPricing::total($size, $i + 1));
            }
            $this->assertSame($totals[4] + $totals[0], ByobPricing::total($size, 6));
        }
    }

    public function test_mixed_sizes_and_split_lines_keep_exact_paise(): void
    {
        $lines = ByobPricing::apply([
            ['size' => '20ML', 'qty' => 1, 'line_total' => 79900],
            ['size' => '50ML', 'qty' => 2, 'line_total' => 319800],
            ['size' => '20ML', 'qty' => 2, 'line_total' => 159800],
        ]);
        $this->assertSame(509800, array_sum(array_column($lines, 'line_total')));
        $this->assertSame(209900, $lines[0]['line_total'] + $lines[2]['line_total']);
        $this->assertSame(299900, $lines[1]['line_total']);
    }
}
