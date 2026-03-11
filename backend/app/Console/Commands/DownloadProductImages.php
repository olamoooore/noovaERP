<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DownloadProductImages extends Command
{
    protected $signature = 'products:download-images
                            {--limit=0 : Max products to process (0 = all)}
                            {--dry-run : Show URLs without downloading}
                            {--tenant= : Tenant ID to run under}';

    protected $description = 'Download product images from weblink URLs stored in the image field';

    public function handle()
    {
        $query = Product::whereNotNull('image')
            ->where('image', 'like', 'http%');

        $limit = (int) $this->option('limit');
        if ($limit > 0) {
            $query->limit($limit);
        }

        $products = $query->get();
        $this->info("Found {$products->count()} products with web URLs to process.");

        if ($products->isEmpty()) {
            return 0;
        }

        $dryRun = $this->option('dry-run');
        $bar = $this->output->createProgressBar($products->count());
        $bar->start();

        $success = 0;
        $failed = 0;

        foreach ($products as $product) {
            $url = $product->image;

            if ($dryRun) {
                $this->line("\n  [{$product->id}] {$product->name} → {$url}");
                $bar->advance();
                continue;
            }

            try {
                $imageUrl = $this->extractImageUrl($url);
                if (!$imageUrl) {
                    $this->warn("\n  No og:image found for: {$product->name} ({$url})");
                    $failed++;
                    $bar->advance();
                    continue;
                }

                $path = $this->downloadImage($imageUrl, $product->id);
                if ($path) {
                    $product->update(['image' => $path]);
                    $success++;
                } else {
                    $this->warn("\n  Download failed for: {$product->name}");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error("\n  Error for {$product->name}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
            usleep(300000); // 300ms throttle to avoid getting blocked
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Success: {$success}, Failed: {$failed}");

        return 0;
    }

    /**
     * Fetch a product page and extract the og:image URL.
     */
    private function extractImageUrl(string $pageUrl): ?string
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ])
            ->get($pageUrl);

        if (!$response->successful()) {
            return null;
        }

        $html = $response->body();

        // Try og:image (property before content)
        if (preg_match('/<meta\s+(?:property|name)=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            return $matches[1];
        }

        // Try og:image (content before property)
        if (preg_match('/<meta\s+content=["\']([^"\']+)["\']\s+(?:property|name)=["\']og:image["\']/i', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Download an image from URL and store it locally.
     */
    private function downloadImage(string $imageUrl, int $productId): ?string
    {
        // Ensure absolute URL
        if (str_starts_with($imageUrl, '//')) {
            $imageUrl = 'https:' . $imageUrl;
        }

        $response = Http::timeout(30)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ])
            ->get($imageUrl);

        if (!$response->successful()) {
            return null;
        }

        $contentType = $response->header('Content-Type') ?? '';
        $extension = 'jpg';
        if (str_contains($contentType, 'png')) {
            $extension = 'png';
        } elseif (str_contains($contentType, 'webp')) {
            $extension = 'webp';
        } elseif (str_contains($contentType, 'gif')) {
            $extension = 'gif';
        }

        $filename = "products/{$productId}_" . Str::random(8) . ".{$extension}";
        Storage::disk('public')->put($filename, $response->body());

        return $filename;
    }
}
