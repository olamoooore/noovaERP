<?php

namespace Modules\WebsiteBuilder\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Modules\WebsiteBuilder\Models\Website;
use ZipArchive;

class ProcessUploadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $filePath,
        public string $websiteName,
        public int $userId,
        public string $extension
    ) {}

    public function handle(): void
    {
        $website = Website::create([
            'name' => $this->websiteName,
            'created_by' => $this->userId,
            'status' => 'draft',
        ]);

        if ($this->extension === 'zip') {
            $this->processZip($website);
        } else {
            $this->processHtml($website);
        }

        // Cleanup temp file
        Storage::delete($this->filePath);
    }

    private function processZip(Website $website): void
    {
        $zip = new ZipArchive;
        $tempDir = storage_path('app/'.$this->filePath);

        if ($zip->open($tempDir) !== true) {
            throw new \Exception('Failed to open ZIP file');
        }

        $extractPath = storage_path("app/website-builder/temp/{$website->uuid}");
        $zip->extractTo($extractPath);
        $zip->close();

        // Find HTML files and create pages
        $htmlFiles = glob("{$extractPath}/*.html") ?: [];
        $htmlFiles = array_merge($htmlFiles, glob("{$extractPath}/**/*.html") ?: []);

        foreach ($htmlFiles as $index => $htmlFile) {
            $content = file_get_contents($htmlFile);
            $sanitized = $this->sanitizeHtml($content);
            $filename = basename($htmlFile, '.html');

            $page = $website->pages()->create([
                'title' => ucfirst($filename),
                'slug' => $filename === 'index' ? '' : $filename,
                'is_home' => $filename === 'index',
                'status' => 'draft',
                'sort_order' => $index,
            ]);

            $page->createVersion([
                'version' => '1.0',
                'root' => [
                    'type' => 'page',
                    'children' => [
                        [
                            'type' => 'html',
                            'props' => ['content' => $sanitized],
                        ],
                    ],
                ],
            ], $this->userId);
        }

        // Cleanup extracted files
        $this->deleteDirectory($extractPath);
    }

    private function processHtml(Website $website): void
    {
        $content = Storage::get($this->filePath);
        $sanitized = $this->sanitizeHtml($content);

        $page = $website->pages()->create([
            'title' => 'Home',
            'slug' => '',
            'is_home' => true,
            'status' => 'draft',
            'sort_order' => 0,
        ]);

        $page->createVersion([
            'version' => '1.0',
            'root' => [
                'type' => 'page',
                'children' => [
                    [
                        'type' => 'html',
                        'props' => ['content' => $sanitized],
                    ],
                ],
            ],
        ], $this->userId);
    }

    private function sanitizeHtml(string $html): string
    {
        // Remove dangerous tags
        $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $html);
        $html = preg_replace('/<iframe\b[^>]*>(.*?)<\/iframe>/is', '', $html);
        $html = preg_replace('/<object\b[^>]*>(.*?)<\/object>/is', '', $html);
        $html = preg_replace('/<embed\b[^>]*>/is', '', $html);

        // Remove event handlers
        $html = preg_replace('/\s+on\w+="[^"]*"/i', '', $html);
        $html = preg_replace("/\s+on\w+='[^']*'/i", '', $html);

        // Remove javascript: links
        $html = preg_replace('/href\s*=\s*["\']javascript:[^"\']*["\']/i', 'href="#"', $html);

        return $html;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = "{$dir}/{$item}";
            is_dir($path) ? $this->deleteDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
