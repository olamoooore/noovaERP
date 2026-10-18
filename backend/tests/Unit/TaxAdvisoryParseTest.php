<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TaxAdvisoryService;
use PHPUnit\Framework\TestCase;

class TaxAdvisoryParseTest extends TestCase
{
    private function parse(string $content): array
    {
        return TaxAdvisoryService::parseAdvice($content);
    }

    public function test_clean_json_object_is_structured(): void
    {
        $result = $this->parse('{"summary":"All good.","risks":["Rate may be stale"],"actions":["Verify with IRAS"]}');

        $this->assertSame('All good.', $result['summary']);
        $this->assertSame(['Rate may be stale'], $result['risks']);
        $this->assertSame(['Verify with IRAS'], $result['actions']);
    }

    public function test_prose_followed_by_fenced_json_prefers_the_json_and_drops_the_dump(): void
    {
        // The real-world shape observed 2026-09-29: markdown prose AND a JSON block.
        $content = <<<'TXT'
            Here is the review of the deterministic tax engine result:

            **Summary**
            The tax engine has calculated the sales tax (GST 9%) for SGD 10,000.

            **Risks**
            - The tax rate is assumed inclusive.

            **Actions**
            1. Verify the tax rate with IRAS.

            Here is the JSON output:
            ```
            {
              "summary": "Tax engine result for sales tax (GST 9%) in Singapore",
              "risks": [
                "Tax rate and treatment may not be up-to-date"
              ],
              "actions": [
                "Verify tax rate and treatment with IRAS"
              ]
            }
            ```
            TXT;

        $result = $this->parse($content);

        $this->assertSame('Tax engine result for sales tax (GST 9%) in Singapore', $result['summary']);
        $this->assertSame(['Tax rate and treatment may not be up-to-date'], $result['risks']);
        $this->assertSame(['Verify tax rate and treatment with IRAS'], $result['actions']);
        $this->assertStringNotContainsString('{', $result['summary'], 'raw JSON must never leak into the UI');
    }

    public function test_markdown_sections_without_json_are_parsed(): void
    {
        $content = "**Summary**\nNet 9,174.31, tax 825.69.\n\n**Risks**\n- Rate may be stale\n- Exemptions not considered\n\n**Actions**\n1. Verify with IRAS\n2. Review records";

        $result = $this->parse($content);

        $this->assertSame('Net 9,174.31, tax 825.69.', $result['summary']);
        $this->assertCount(2, $result['risks']);
        $this->assertStringNotContainsString('- ', $result['risks'][0]);
        $this->assertStringNotContainsString('1.', $result['actions'][0]);
    }

    public function test_plain_text_falls_back_to_summary(): void
    {
        $result = $this->parse('The engine applied 9% GST on the inclusive amount.');

        $this->assertSame('The engine applied 9% GST on the inclusive amount.', $result['summary']);
        $this->assertSame([], $result['risks']);
    }

    public function test_empty_response_is_handled(): void
    {
        $result = $this->parse('   ');

        $this->assertNotSame('', $result['summary']);
    }
}
