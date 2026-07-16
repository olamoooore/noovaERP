<?php

declare(strict_types=1);

namespace Tests\Feature\TemplateBuilder;

use Modules\TemplateBuilder\Services\TemplateContentSanitiser;
use Tests\TestCase;

class TemplateContentSanitiserTest extends TestCase
{
    private TemplateContentSanitiser $sanitiser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitiser = new TemplateContentSanitiser;
    }

    public function test_strips_script_tags(): void
    {
        $out = $this->sanitiser->sanitiseHtml('<p>hi</p><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringNotContainsString('alert', $out);
        $this->assertStringContainsString('hi', $out);
    }

    public function test_strips_event_handlers(): void
    {
        $out = $this->sanitiser->sanitiseHtml('<p onclick="evil()">hi</p>');

        $this->assertStringNotContainsString('onclick', $out);
        $this->assertStringContainsString('hi', $out);
    }

    public function test_strips_javascript_url_scheme(): void
    {
        $out = $this->sanitiser->sanitiseHtml('<a href="javascript:evil">x</a>');

        $this->assertStringNotContainsString('javascript:', $out);
    }

    public function test_keeps_safe_links(): void
    {
        $out = $this->sanitiser->sanitiseHtml('<a href="https://example.com">link</a>');

        $this->assertStringContainsString('https://example.com', $out);
    }

    public function test_css_blocks_import_and_expression(): void
    {
        $css = "@import url('https://evil/x.css'); body { color: red; } .x { width: expression(alert(1)); behavior: url(evil.htc); }";
        $out = $this->sanitiser->sanitiseCss($css);

        $this->assertStringNotContainsString('@import', $out);
        $this->assertStringNotContainsString('expression(', $out);
        $this->assertStringNotContainsString('behavior:', $out);
        $this->assertStringContainsString('color: red', $out);
    }

    public function test_empty_payload_returns_empty(): void
    {
        $this->assertSame('', $this->sanitiser->sanitiseHtml(''));
        $this->assertSame('', $this->sanitiser->sanitiseCss(''));
    }
}
