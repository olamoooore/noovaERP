<?php

namespace Modules\Storefront\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Storefront\Shortcodes\Context;
use Modules\Storefront\Shortcodes\Parser;
use Modules\Storefront\Shortcodes\Renderer;
use Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry;

/**
 * Editor live preview endpoint (FR-BLD-06, Appendix B §B4.3).
 *
 * Renders one storefront function with REAL tenant data for the authenticated
 * editor: PREVIEW mode → shared cache bypassed, errors visible in the result.
 * Unknown functions are rejected with 422 (the editor surfaces the notice);
 * invalid attributes still render through the definition's coercion so
 * merchants see the actual degraded output, not a server error.
 */
class PreviewController extends Controller
{
    public function __construct(
        private readonly StorefrontShortcodeRegistry $registry,
        private readonly Renderer $renderer,
        private readonly Parser $parser,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'function' => ['required', 'string', 'max:100'],
            'attributes' => ['nullable', 'array', 'max:'.Parser::MAX_ATTRIBUTES],
            // Attribute values are DATA — every value is coerced/bounded by
            // the Definition's Attribute types before use (there is no
            // built-in 'scalar' validation rule, and none is needed:
            // coercion is total).
            'attributes.*' => ['present'],
            'website_slug' => ['nullable', 'string', 'max:190'],
        ]);

        $function = $validated['function'];

        if (! $this->registry->has($function)) {
            return response()->json([
                'message' => "Unknown storefront function '{$function}'.",
                'code' => 'unknown_function',
            ], 422);
        }

        $tenantId = function_exists('tenant') && tenant() ? (string) tenant('id') : '';

        if ($tenantId === '') {
            return response()->json(['message' => 'No tenant context for preview.'], 403);
        }

        $html = $this->renderer->renderToken(
            $function,
            (array) ($validated['attributes'] ?? []),
            new Context(
                tenantId: $tenantId,
                mode: Context::MODE_PREVIEW,
                websiteSlug: (string) ($validated['website_slug'] ?? ''),
                locale: (string) app()->getLocale(),
            ),
            '[ '.$function.' ]',
        );

        return response()->json(['data' => ['html' => $html]])
            ->header('X-Storefront-Preview', '1');
    }
}
