<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Modules\WebsiteBuilder\Models\Template;
use Modules\WebsiteBuilder\Models\Website;
use Modules\WebsiteBuilder\Services\TemplateService;

class TemplateController extends Controller
{
    public function __construct(
        private TemplateService $templateService
    ) {}

    /**
     * List available templates.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Template::public();

        if ($request->has('category')) {
            $query->byCategory($request->category);
        }

        $templates = $query->orderBy('name')->get();

        return response()->json($templates);
    }

    /**
     * Get a single template.
     */
    public function show($template): JsonResponse
    {
        $template = Template::findOrFail($template);
        return response()->json($template);
    }

    /**
     * Clone a template to create a new website.
     */
    public function cloneToWebsite(Request $request, $template): JsonResponse
    {
        $template = Template::findOrFail($template);
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:100|unique:wb_websites,slug',
        ]);

        $website = $this->templateService->cloneToWebsite(
            $template,
            $validated['name'],
            $validated['slug'] ?? null,
            auth()->id()
        );

        return response()->json($website->load('pages'), 201);
    }
}
