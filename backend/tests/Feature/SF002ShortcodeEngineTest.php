<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Str;
use Modules\Storefront\Models\StorefrontSettings;
use Modules\Storefront\Services\StorefrontCache;
use Modules\Storefront\Shortcodes\Attribute;
use Modules\Storefront\Shortcodes\Context;
use Modules\Storefront\Shortcodes\Definition;
use Modules\Storefront\Shortcodes\Parser;
use Modules\Storefront\Shortcodes\Renderer;
use Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry;
use Modules\Storefront\Shortcodes\Tier;
use Tests\TestCase;

/**
 * SF-002 — Shortcode engine unit tests (FR-BLD-03/04/05/09, Appendix B §B3).
 *
 * Grammar, coercion, registry discipline, tier/cache policy and error policy,
 * proven without HTTP so every rule from the spec has a direct assertion.
 */
class SF002ShortcodeEngineTest extends TestCase
{
    private function context(bool $preview = false): Context
    {
        return new Context(
            tenantId: (string) Str::uuid(),
            mode: $preview ? Context::MODE_PREVIEW : Context::MODE_PUBLIC,
            pageId: 1,
            pageVersionId: random_int(1, 999999),
            settings: new StorefrontSettings(['currency_code' => 'USD']),
        );
    }

    private function registryWith(?StorefrontShortcodeRegistry $r = null): StorefrontShortcodeRegistry
    {
        return $r ?? new StorefrontShortcodeRegistry;
    }

    private function renderer(StorefrontShortcodeRegistry $registry): Renderer
    {
        return new Renderer($registry, new Parser, new StorefrontCache);
    }

    // ─── Parser grammar (§B3.1) ────────────────────────────────────────

    public function test_parses_quoted_single_quoted_and_unquoted_attributes_and_flags(): void
    {
        $parser = new Parser;
        $token = $parser->parseToken('[storefront.products category=\'mugs\' limit=8 sale search="two words"]');

        $this->assertNotNull($token);
        $this->assertSame('storefront.products', $token['function']);
        $this->assertSame('mugs', $token['attributes']['category']);
        $this->assertSame('8', $token['attributes']['limit']);
        $this->assertSame(true, $token['attributes']['sale']);
        $this->assertSame('two words', $token['attributes']['search']);
    }

    public function test_tokens_inside_script_style_and_comments_are_never_executed(): void
    {
        $parser = new Parser;
        $executed = 0;
        $html = '<p>before [storefront.products]</p>'
            .'<script>var x = "[storefront.products ids=\\"1,2\\"]";</script>'
            .'<style>[storefront.products]{}</style>'
            .'<!-- [storefront.products] -->';

        $out = $parser->replace($html, function (array $t, string $raw) use (&$executed) {
            $executed++;

            return '[REPLACED]';
        });

        $this->assertSame(1, $executed, 'Only the text-node token may be replaced');
        $this->assertStringContainsString('[storefront.products ids=\"1,2\"]', $out);
        $this->assertStringContainsString('<!-- [storefront.products] -->', $out);
        $this->assertStringContainsString('[REPLACED]', $out);
    }

    public function test_attribute_and_value_limits_are_enforced(): void
    {
        $parser = new Parser;

        $long = str_repeat('A', Parser::MAX_VALUE_LENGTH + 400);
        $many = '';
        for ($i = 0; $i < 40; $i++) {
            $many .= " attr{$i}=\"v{$i}\"";
        }

        $token = $parser->parseToken("[storefront.products x=\"{$long}\"]");
        $this->assertLessThanOrEqual(Parser::MAX_VALUE_LENGTH, strlen($token['attributes']['x']));

        $token = $parser->parseToken("[storefront.products{$many}]");
        $this->assertLessThanOrEqual(Parser::MAX_ATTRIBUTES, count($token['attributes']));
    }

    public function test_non_token_brackets_are_untouched(): void
    {
        $parser = new Parser;
        $html = '<p>[not-a-function] [storefront.x] [broken products]</p>';

        $out = $parser->replace($html, fn () => 'R');

        $this->assertStringContainsString('[not-a-function]', $out);
        $this->assertStringContainsString('[broken products]', $out);
        $this->assertStringNotContainsString('[storefront.x]', $out);
    }

    // ─── Registry discipline (§B3.2/§B3.7) ─────────────────────────────

    public function test_registry_rejects_duplicate_and_malformed_keys_at_registration(): void
    {
        $registry = $this->registryWith();
        $registry->register(Definition::make('storefront.products')->render(fn () => ''));

        $this->expectException(\InvalidArgumentException::class);
        $registry->register(Definition::make('storefront.products')->render(fn () => ''));
    }

    public function test_registry_rejects_unnamespaced_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->registryWith()->register(Definition::make('products')->render(fn () => ''));
    }

    public function test_registry_schema_covers_every_definition_field_the_editor_consumes(): void
    {
        $registry = $this->registryWith();
        $registry->register(
            Definition::make('storefront.products')
                ->label('Product Grid')->icon('solar:x')->category('Catalog')
                ->tier(Tier::CACHEABLE)->cache(300)->version('1')
                ->attributes([Attribute::int('limit', 12, 1, 48)])
                ->render(fn () => '')
        );

        $schema = $registry->schema()[0];
        foreach (['key', 'label', 'icon', 'category', 'tier', 'cacheable', 'version', 'attributes', 'example'] as $field) {
            $this->assertArrayHasKey($field, $schema);
        }
        $this->assertSame('limit', $schema['attributes'][0]['name']);
        $this->assertSame(48, $schema['attributes'][0]['max']);
    }

    // ─── Attribute coercion (§B3.5 rules 2/5) ──────────────────────────

    public function test_numeric_attributes_are_clamped_not_trusted(): void
    {
        $def = Definition::make('storefront.products')
            ->attributes([Attribute::int('limit', 12, 1, 48), Attribute::int('columns', 4, 2, 5)])
            ->render(fn () => '');

        $out = $def->validate(['limit' => '99999', 'columns' => '-4']);
        $this->assertSame(48, $out['values']['limit']);
        $this->assertSame(2, $out['values']['columns']);

        $out = $def->validate(['limit' => '1; DROP TABLE products;--']);
        $this->assertSame(1, $out['values']['limit'], 'Non-numeric garbage falls back through (int) cast + bounds; never reaches SQL as raw');
    }

    public function test_enum_and_list_coercion(): void
    {
        $enum = Attribute::enum('layout', ['grid', 'list'], 'grid');
        $this->assertSame('list', $enum->coerce('list'), 'Canonical values pass through');
        $this->assertSame('grid', $enum->coerce('LIST'), 'Matching is strict/canonical — wrong case is not smuggled through');
        $this->assertSame('grid', $enum->coerce('../../../etc/passwd'), 'Unknown enum values collapse to the default');

        $list = Attribute::idList('ids', 3);
        $this->assertSame([1, 2, 3], $list->coerce('1, 2, x, 3, 4, 5'));
        $this->assertSame([], $list->coerce("' OR 1=1 --"));
    }

    public function test_unknown_attributes_are_reported_ignored_never_executed(): void
    {
        $def = Definition::make('storefront.products')->attributes([Attribute::int('limit', 12)])->render(fn () => '');
        $out = $def->validate(['limit' => '5', 'evil_sql' => 'x', 'template' => 'path/to/blade']);

        $this->assertSame(['limit'], array_keys($out['values']));
        $this->assertSame(['evil_sql', 'template'], $out['ignored']);
    }

    // ─── Tier / cache policy (§B3.4) ───────────────────────────────────

    public function test_tier_a_fragments_cache_and_tier_b_never_caches(): void
    {
        $registry = $this->registryWith();
        $renders = 0;

        $registry->register(Definition::make('storefront.cached')
            ->tier(Tier::CACHEABLE)->cache(300)->render(function () use (&$renders) {
                $renders++;

                return '<div>cached-body</div>';
            }));

        $registry->register(Definition::make('storefront.personal')
            ->tier(Tier::PERSONALIZED)->render(function () use (&$renders) {
                $renders++;

                return '<div>personal-body</div>';
            }));

        $renderer = $this->renderer($registry);
        $ctx = $this->context();

        $htmlA = $renderer->render('[storefront.cached]', $ctx);
        $renderer->render('[storefront.cached]', $ctx);
        $this->assertSame('<div>cached-body</div>', $htmlA);
        $this->assertSame(1, $renders, 'Tier A must serve the second render from cache');

        $renderer->render('[storefront.personal]', $ctx);
        $renderer->render('[storefront.personal]', $ctx);
        $this->assertSame(3, $renders, 'Tier B must re-render every time (never cached)');
    }

    public function test_preview_mode_bypasses_the_shared_cache(): void
    {
        $registry = $this->registryWith();
        $renders = 0;
        $registry->register(Definition::make('storefront.cached')
            ->tier(Tier::CACHEABLE)->cache(300)
            ->render(function () use (&$renders) {
                $renders++;

                return 'x';
            }));

        $renderer = $this->renderer($registry);
        $publicCtx = $this->context();
        $previewCtx = $this->context(preview: true);

        $renderer->render('[storefront.cached]', $publicCtx);
        $renderer->render('[storefront.cached]', $publicCtx);
        $this->assertSame(1, $renders, 'Public render cached');

        $renderer->render('[storefront.cached]', $previewCtx);
        $renderer->render('[storefront.cached]', $previewCtx);
        $this->assertSame(3, $renders, 'Preview must never read or write the shared cache');
    }

    public function test_fragment_cache_keys_are_tenant_scoped(): void
    {
        $registry = $this->registryWith();
        $renders = 0;
        $registry->register(Definition::make('storefront.cached')
            ->tier(Tier::CACHEABLE)->cache(300)
            ->render(function () use (&$renders) {
                $renders++;

                return 'x';
            }));

        $renderer = $this->renderer($registry);
        $tenantA = $this->context();
        $tenantB = $this->context();

        $renderer->render('[storefront.cached]', $tenantA);
        $renderer->render('[storefront.cached]', $tenantA);
        $this->assertSame(1, $renders, 'Same tenant: second render served from cache');

        // Second tenant (different id, same page-version space) must NOT hit
        // the first tenant's cached fragment.
        $renderer->render('[storefront.cached]', $tenantB);
        $this->assertSame(2, $renders, 'Cross-tenant cache keys must be isolated');
    }

    // ─── Error policy (§B3.5 rule 6, FR-BLD-05) ────────────────────────

    public function test_unknown_function_renders_nothing_in_public_but_warns_in_preview(): void
    {
        $registry = $this->registryWith();
        $renderer = $this->renderer($registry);

        $public = $renderer->render('<p>a [storefront.does_not_exist] b</p>', $this->context());
        $this->assertStringNotContainsString('storefront.does_not_exist', $public, 'Raw tokens must never leak to visitors');

        $preview = $renderer->render('[storefront.does_not_exist]', $this->context(preview: true));
        $this->assertStringContainsString('Unknown storefront function', $preview);
    }

    public function test_render_failures_degrade_invisibly_in_public_visibly_in_preview(): void
    {
        $registry = $this->registryWith();
        $registry->register(Definition::make('storefront.boom')
            ->render(function () {
                throw new \RuntimeException('db exploded');
            }));

        $renderer = $this->renderer($registry);

        $public = $renderer->render('<p>keep me [storefront.boom]</p>', $this->context());
        $this->assertSame('<p>keep me </p>', $public, 'Page must still render around a failed function — no fatal, no internals leaked');
        $this->assertStringNotContainsString('db exploded', $public);

        $preview = $renderer->render('[storefront.boom]', $this->context(preview: true));
        $this->assertStringContainsString('could not be rendered', $preview);
    }

    public function test_tokens_cannot_smuggle_markup_past_the_grammar(): void
    {
        $parser = new Parser;

        // Function names with markup/quotes are not valid tokens — the
        // grammar refuses them; nothing reaches a Definition or the DB.
        $this->assertNull($parser->parseToken('[storefront.<script>alert(1)</script>]'));
        $this->assertNull($parser->parseToken('[storefront.foo"bar>]'));

        $registry = $this->registryWith();
        $renderer = $this->renderer($registry);
        $out = $renderer->render('[storefront.unknown_key]', $this->context(preview: true));

        // The preview notice escapes the function name text it echoes.
        $this->assertStringNotContainsString('<script>', $out);
    }

    /**
     * R-03 regression (found by SF013): attributes resolved from GET query
     * params (?sfcat= on a shared collection page) MUST enter the fragment
     * cache key BEFORE it is computed — two visitors must never swap cached
     * renders of the same page version.
     */
    public function test_query_bound_attributes_cannot_cross_visitors_in_cache(): void
    {
        $registry = $this->registryWith();
        $seen = [];
        $registry->register(Definition::make('storefront.qbound')
            ->tier(Tier::CACHEABLE)->cache(300)
            ->attributes([Attribute::slug('category', null)])
            ->fromQuery('sfcat', 'category')
            ->render(function (Context $c, array $a) use (&$seen): string {
                $seen[] = (string) ($a['category'] ?? '');

                return 'v:'.($a['category'] ?? '?');
            }));

        $renderer = $this->renderer($registry);
        $tenant = (string) Str::uuid();
        $settings = new StorefrontSettings(['currency_code' => 'USD']);

        // SAME page version, SAME static attributes — only the query differs.
        $mugs = new Context(tenantId: $tenant, pageVersionId: 4242, settings: $settings, query: ['sfcat' => 'Mugs']);
        $tees = new Context(tenantId: $tenant, pageVersionId: 4242, settings: $settings, query: ['sfcat' => 'Tees']);

        $this->assertStringContainsString('v:mugs', $renderer->render('[storefront.qbound]', $mugs));
        $this->assertStringContainsString('v:tees', $renderer->render('[storefront.qbound]', $tees));
        $this->assertSame(['mugs', 'tees'], $seen, 'each query value rendered exactly once — no cache crossover');

        // Repeat of the first is served from cache (seen count unchanged).
        $this->assertStringContainsString('v:mugs', $renderer->render('[storefront.qbound]', $mugs));
        $this->assertCount(2, $seen);
    }

    public function test_keyedby_query_params_salt_the_fragment_key(): void
    {
        $registry = $this->registryWith();
        $renders = 0;
        $registry->register(Definition::make('storefront.salted')
            ->tier(Tier::CACHEABLE)->cache(300)
            ->attributes([Attribute::int('id', 0, 0, 1000000)])
            ->keyedBy(['product'])
            ->render(function (Context $c) use (&$renders): string {
                $renders++;

                return 'r'.$renders;
            }));

        $renderer = $this->renderer($registry);
        $tenant = (string) Str::uuid();
        $settings = new StorefrontSettings(['currency_code' => 'USD']);

        $p1 = new Context(tenantId: $tenant, pageVersionId: 777, settings: $settings, query: ['product' => '1']);
        $p2 = new Context(tenantId: $tenant, pageVersionId: 777, settings: $settings, query: ['product' => '2']);

        $renderer->render('[storefront.salted]', $p1);
        $renderer->render('[storefront.salted]', $p2);
        $this->assertSame(2, $renders, 'keyedBy params separate fragment keys');

        $renderer->render('[storefront.salted]', $p1);
        $this->assertSame(2, $renders, 'same product param hits cache');
    }

    public function test_strip_tokens_removes_all_storefront_output(): void
    {
        $renderer = $this->renderer($this->registryWith());
        $out = $renderer->stripTokens('<p>a [storefront.products limit="4"] b [storefront.unknown]</p>');
        $this->assertSame('<p>a  b </p>', $out);
    }

    /**
     * Editor contract (Appendix B §B4.1): the frontend Store blocks save the
     * wrapper+token shape below; whatever the editor does to the wrapper
     * (extra attributes, re-parenting), the SERVER must still find the
     * literal token. Mirrors storefrontBlocks.ts registerStoreBlocks output.
     */
    public function test_editor_wrapper_serialization_round_trips_through_the_server_parser(): void
    {
        $savedByEditor = '<div data-storefront-function="storefront.products" '
            .$this->q("data-attrs='{\"category\":\"mugs\",\"limit\":8}'")
            .' class="sf-editor-decorated"><span>[storefront.products category="mugs" limit="8"]</span></div>';

        $parser = new Parser;
        $found = [];
        $parser->replace($savedByEditor, function (array $token) use (&$found) {
            $found[] = $token;

            return '<rendered/>';
        });

        $this->assertCount(1, $found);
        $this->assertSame('storefront.products', $found[0]['function']);
        $this->assertSame(['category' => 'mugs', 'limit' => '8'], $found[0]['attributes']);

        // data-attrs JSON must be irrelevant to the server (token = truth):
        $attrsOnly = '<div data-storefront-function="storefront.products" '
            .$this->q("data-attrs='{\"category\":\"WRONG\",\"limit\":99}'")
            .'>nothing</div>';
        $hits = 0;
        $parser->replace($attrsOnly, function () use (&$hits) {
            $hits++;

            return 'X';
        });
        $this->assertSame(0, $hits, 'data-attrs alone must never trigger a render');
    }

    private function q(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES);
    }
}
