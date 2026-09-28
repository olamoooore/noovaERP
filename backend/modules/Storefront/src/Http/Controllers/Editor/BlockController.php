<?php

namespace Modules\Storefront\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Storefront\Shortcodes\StorefrontShortcodeRegistry;

/**
 * Editor blocks endpoint (FR-BLD-01/03, Appendix E §E4.3).
 *
 * Returns the Store block-group definition GENERATED FROM THE REGISTRY —
 * the editor must not keep its own copy of the function list or attribute
 * schema (single source of truth).
 */
class BlockController extends Controller
{
    public function __construct(
        private readonly StorefrontShortcodeRegistry $registry,
    ) {}

    public function index(): JsonResponse
    {
        $functions = $this->registry->schema();

        return response()->json([
            'data' => [
                'block_group' => 'Store',
                'namespace' => 'storefront',
                'functions' => $functions,
            ],
        ]);
    }
}
