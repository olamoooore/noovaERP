<?php

namespace Tests\Unit;

use App\Support\InvoiceRichtextSanitizer;
use PHPUnit\Framework\TestCase;

class InvoiceRichtextSanitizerTest extends TestCase
{
    /** #1004: scripts, event handlers and unsafe tags are stripped. */
    public function test_strips_scripts_handlers_and_disallowed_tags(): void
    {
        $dirty = '<p onclick="steal()"><b>Bold</b><script>alert(1)</script>'
            .'<img src=x onerror=alert(1)><a href="javascript:alert(1)">link</a></p>';

        $clean = InvoiceRichtextSanitizer::clean($dirty);

        $this->assertNotNull($clean);
        $this->assertStringContainsString('<b>Bold</b>', $clean);
        $this->assertStringNotContainsString('<script', $clean);
        $this->assertStringNotContainsString('onclick', $clean);
        $this->assertStringNotContainsString('onerror', $clean);
        $this->assertStringNotContainsString('<img', $clean);
        // Disallowed tags are unwrapped, not dropped (text survives).
        $this->assertStringContainsString('link', $clean);
        $this->assertStringNotContainsString('href', $clean);
    }

    /** #1004: only formatting tags are kept. */
    public function test_keeps_allowed_formatting_tags_and_unwraps_html(): void
    {
        $clean = InvoiceRichtextSanitizer::clean(
            '<p><b>a</b><strong>b</strong><i>c</i><em>d</em><u>e</u><s>f</s>'
            .'<span>g</span><ul><li>h</li></ul><br></p>'
        );

        $this->assertNotNull($clean);
        foreach (['<b>', '<strong>', '<i>', '<em>', '<u>', '<s>', '<span>', '<ul>', '<li>', '<br'] as $tag) {
            $this->assertStringContainsString($tag, $clean);
        }
        $this->assertStringNotContainsString('<div>', $clean);
    }

    /** #1004: allowlisted inline styles survive, everything else is dropped. */
    public function test_filters_inline_styles_to_supported_formatting(): void
    {
        $clean = InvoiceRichtextSanitizer::clean(
            '<span style="color:#b91c1c;font-weight:700;font-family:Times New Roman;font-size:14px;'
            .'background:url(javascript:alert(1));position:absolute">x</span>'
            .'<p style="text-align:center">Centered</p>'
            .'<span style="font-family:Comic Sans MS;font-size:100px;text-align:middle">bad</span>'
        );

        $this->assertNotNull($clean);
        $this->assertStringContainsString('color:#b91c1c', $clean);
        $this->assertStringContainsString('font-weight:700', $clean);
        $this->assertStringContainsString('font-family:times new roman', $clean);
        $this->assertStringContainsString('font-size:14px', $clean);
        $this->assertStringContainsString('text-align:center', $clean);
        $this->assertStringNotContainsString('background', $clean);
        $this->assertStringNotContainsString('position', $clean);
        $this->assertStringNotContainsString('javascript', $clean);
        // Out-of-range sizes, unknown families and bad alignment values are dropped.
        $this->assertStringNotContainsString('100px', $clean);
        $this->assertStringNotContainsString('comic sans', $clean);
        $this->assertStringNotContainsString('middle', $clean);
    }

    /** #1004: plainText() unwraps formatted text for search/export columns. */
    public function test_plain_text_unwraps_formatting(): void
    {
        $text = InvoiceRichtextSanitizer::plainText(
            '<p>Hello <b>world</b></p><ul><li>One</li><li>Two</li></ul>'
        );

        $this->assertStringContainsString('Hello world', $text);
        $this->assertStringContainsString('One', $text);
        $this->assertStringContainsString('Two', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    /** #1004: null/empty input stays null. */
    public function test_empty_input_returns_null(): void
    {
        $this->assertNull(InvoiceRichtextSanitizer::clean(null));
        $this->assertNull(InvoiceRichtextSanitizer::clean(''));
        $this->assertNull(InvoiceRichtextSanitizer::clean('   '));
    }
}
