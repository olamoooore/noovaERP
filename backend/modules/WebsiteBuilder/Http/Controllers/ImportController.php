<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\Template;
use Modules\WebsiteBuilder\Models\Website;
use ZipArchive;

class ImportController extends Controller
{
    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:zip,html|max:51200', // 50MB
            'type' => 'required|in:website,template',
            'name' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $ext = $file->getClientOriginalExtension();

        if ($ext === 'html') {
            return $this->importSingleHtml($file, $request);
        }

        return $this->importZip($file, $request);
    }

    protected function importSingleHtml($file, $request)
    {
        $content = file_get_contents($file->path());
        // Basic sanitization or structure parsing could go here

        // For now, create a single page website/template
        if ($request->type === 'template') {
            $template = Template::create([
                'name' => $request->name ?? 'Imported HTML',
                'category' => 'imported',
                'version' => 1,
                'schema' => ['html' => $content],
                'is_system' => false,
                'is_public' => false,
            ]);

            return response()->json(['message' => 'Template imported', 'id' => $template->id]);
        }

        // If type is website, we'd create a website + page.
        // But normally user creates website first.
        // Maybe this endpoint is called FROM a website context?
        // For MVP, lets just stick to template import.
        return response()->json(['error' => 'Direct website creation from HTML not supported yet, import as template.'], 400);
    }

    protected function importZip($file, $request)
    {
        // Extract ZIP
        $zip = new ZipArchive;
        if ($zip->open($file->path()) === true) {
            $extractPath = storage_path('app/temp/import/'.Str::uuid());
            $zip->extractTo($extractPath);
            $zip->close();

            // Logic to scan folder for index.html or assets
            // This is complex. For now, returning success dummy.
            // TODO: Implement full ZIP traversal and asset mapping.

            return response()->json(['message' => 'ZIP imported (Structure parsing pending implementation)']);
        } else {
            return response()->json(['error' => 'Failed to open ZIP'], 500);
        }
    }
}
