<?php

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_item_amount_is_quantity_times_rate_rounded_to_paise(): void
    {
        $this->assertSame('35750.00', Money::itemAmount('130', '275'));
        $this->assertSame('107.63', Money::itemAmount('10.5', '10.25'));
        $this->assertNull(Money::itemAmount(null, '10'));
        $this->assertNull(Money::itemAmount('10', null));
    }

    public function test_gst_percentage_rounds_half_up(): void
    {
        $this->assertSame('6651.23', Money::percentage('36951.25', '18'));
        $this->assertSame('0.00', Money::percentage('100.00', '0'));
    }
}
