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

    /**
     * #1004: dompdf only ships Helvetica/Times/Courier and DejaVu Sans/Serif/Mono,
     * so a named family keeps its place first and gains a same-class DejaVu
     * fallback (DejaVu also keeps non-Latin glyphs).
     */
    public function test_named_font_families_gain_a_same_class_pdf_fallback(): void
    {
        $cases = [
            'Arial' => "'dejavu sans', sans-serif",
            'Verdana' => "'dejavu sans', sans-serif",
            'Tahoma' => "'dejavu sans', sans-serif",
            'Trebuchet MS' => "'dejavu sans', sans-serif",
            'Helvetica' => "'dejavu sans', sans-serif",
            'Georgia' => "'dejavu serif', serif",
            'Garamond' => "'dejavu serif', serif",
            'Times New Roman' => "'dejavu serif', serif",
            'Courier New' => "'dejavu sans mono', monospace",
        ];

        foreach ($cases as $family => $fallback) {
            $clean = InvoiceRichtextSanitizer::clean('<span style="font-family:'.$family.'">x</span>');
            $this->assertNotNull($clean, $family);
            $decoded = html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->assertStringContainsString(
                'font-family:'.strtolower($family).', '.$fallback,
                $decoded,
                "{$family} should keep its name first and fall back to {$fallback}",
            );
        }
    }

    /** #1004: generic families already resolve in dompdf and stay as they are. */
    public function test_generic_font_families_are_kept_without_a_stack(): void
    {
        foreach (['serif', 'sans-serif', 'monospace'] as $family) {
            $clean = InvoiceRichtextSanitizer::clean('<span style="font-family:'.$family.'">x</span>');
            $this->assertSame('<span style="font-family:'.$family.'">x</span>', $clean);
        }
    }

    /**
     * #1004: the editor re-submits the stored stack ("times new roman, 'dejavu serif', serif")
     * on the next save; the first family decides, so the font is not lost on a re-save.
     */
    public function test_re_sanitizing_a_stored_font_stack_is_stable(): void
    {
        $first = InvoiceRichtextSanitizer::clean('<span style="font-family:Courier New">x</span>');
        $this->assertNotNull($first);
        $second = InvoiceRichtextSanitizer::clean(html_entity_decode($first, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $this->assertSame($first, $second);

        // A quoted, browser-normalised stack is accepted the same way.
        $browser = InvoiceRichtextSanitizer::clean('<span style=\'font-family: "Times New Roman", "dejavu serif", serif\'>x</span>');
        $this->assertNotNull($browser);
        $this->assertStringContainsString(
            "font-family:times new roman, 'dejavu serif', serif",
            html_entity_decode($browser, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        );

        // An unknown first family is still rejected even if a known one follows.
        $sneaky = InvoiceRichtextSanitizer::clean('<span style="font-family:Comic Sans MS, serif">x</span>');
        $this->assertStringNotContainsString('font-family', (string) $sneaky);
    }
}
