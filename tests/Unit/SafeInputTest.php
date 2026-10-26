<?php

namespace Tests\Unit;

use App\Support\SafeInput;
use Tests\TestCase;

class SafeInputTest extends TestCase
{
    public function test_plain_text_strips_controls_and_keeps_unicode_and_punctuation(): void
    {
        $this->assertSame("Café naïve 漢字 — “quoted”", SafeInput::plainText("Café naïve 漢字 — “quoted”"));
        $this->assertSame("ACME Corp", SafeInput::plainText("ACME\0\x07 Corp"));
        $this->assertSame("Line1\nLine2\tTab", SafeInput::plainText("Line1\nLine2\tTab"));
    }

    public function test_spreadsheet_cells_prefix_formula_triggers(): void
    {
        $this->assertSame("'=1+1", SafeInput::spreadsheetCell('=1+1'));
        $this->assertSame("'+CMD", SafeInput::spreadsheetCell('+CMD'));
        $this->assertSame("'-CMD", SafeInput::spreadsheetCell('-CMD'));
        $this->assertSame('-100.50', SafeInput::spreadsheetCell('-100.50'));
        $this->assertSame("'@SUM(A1)", SafeInput::spreadsheetCell('@SUM(A1)'));
        $this->assertSame('Invoice #12', SafeInput::spreadsheetCell('Invoice #12'));
    }

    public function test_email_normalization_is_stable(): void
    {
        $this->assertSame('user@example.com', SafeInput::normalizeEmail('  User@Example.COM  '));
    }
}
