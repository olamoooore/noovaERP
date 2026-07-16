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
                            {--tenant= : Tenant ID to run under (required)}
                            {--source= : Only process URLs matching this domain (e.g. withmuu, ktown4u)}
                            {--skip-existing : Skip products that already have a local image}
                            {--netfunnel-cookie= : NetFunnel cookie value for withmuu.com (get from browser DevTools)}';

    protected $description = 'Download product images from weblink URLs stored in the image field';

    private const WITHMUU_CDN_BASE = 'https://godomall.speedycdn.net/c03670ee9f741fa0343b16321feede3e';

    private array $withmuu_cache = [];

    public function handle()
    {
        $tenantId = $this->option('tenant');

        if (! $tenantId) {
            $this->error('The --tenant flag is required for this command.');
            $this->info('Usage: php artisan products:download-images --tenant=<tenant-id>');

            return Command::FAILURE;
        }

        $tenant = \App\Models\Tenant::find($tenantId);
        if (! $tenant) {
            $this->error("Tenant not found: {$tenantId}");

            return Command::FAILURE;
        }

        tenancy()->initialize($tenantId);
        $this->info("Running under tenant: {$tenantId}");

        $query = Product::whereNotNull('image')
            ->where('image', 'like', 'http%');

        if ($source = $this->option('source')) {
            $query->where('image', 'like', "%{$source}%");
        }

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
        $skipped = 0;

        foreach ($products as $product) {
            $url = $product->image;

            if ($this->option('skip-existing') && ! str_starts_with($url, 'http')) {
                $skipped++;
                $bar->advance();

                continue;
            }

            if ($dryRun) {
                $this->line("\n  [{$product->id}] {$product->name} → {$url}");
                $bar->advance();

                continue;
            }

            try {
                $imageUrl = $this->extractImageUrl($url);
                if (! $imageUrl) {
                    $failed++;
                    $bar->advance();

                    continue;
                }

                $path = $this->downloadImage($imageUrl, $product->id);
                if ($path) {
                    $product->update(['image' => $path]);
                    $success++;
                } else {
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->error("\n  Error [{$product->id}] {$product->name}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
            usleep(200000); // 200ms throttle
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. Success: {$success}, Failed: {$failed}, Skipped: {$skipped}");

        return 0;
    }

    private function extractImageUrl(string $pageUrl): ?string
    {
        if (str_contains($pageUrl, 'withmuu.com')) {
            return $this->extractWithmuuImage($pageUrl);
        }

        if (str_contains($pageUrl, 'withdrama.') || str_contains($pageUrl, 'img.app.fans')) {
            return $this->extractDirectCdnUrl($pageUrl);
        }

        return $this->extractOgImage($pageUrl);
    }

    /**
     * withmuu.com: JS-rendered site behind NetFunnel queue — page fetch doesn't work.
     * Strategy: CDN pattern scan with smart sentinel approach.
     * Handles mismatched goodsNo (folder vs filename ID differ by ±1)
     * and both .jpg and .jpeg extensions.
     */
    private function extractWithmuuImage(string $url): ?string
    {
        if (! preg_match('/goodsNo=(\d+)/', $url, $m)) {
            return null;
        }
        $goodsNo = $m[1];

        if (isset($this->withmuu_cache[$goodsNo])) {
            return $this->withmuu_cache[$goodsNo];
        }

        $imageUrl = $this->findWithmuuCdnImage($goodsNo);

        if ($imageUrl) {
            $this->withmuu_cache[$goodsNo] = $imageUrl;
        }

        return $imageUrl;
    }

    /**
     * Smart CDN pattern scan for withmuu/Godo Mall products.
     * Phase 1: Check legacy + sentinel positions (15 URLs per extension per ID variant)
     * Phase 2: Fill remaining ranges in small batches if sentinel missed
     * Also tries goodsNo±1 for mismatched internal IDs.
     */
    private function findWithmuuCdnImage(string $goodsNo): ?string
    {
        // Try the exact goodsNo first, then ±1 for mismatched IDs
        $idsToTry = [$goodsNo];
        if (is_numeric($goodsNo)) {
            $idsToTry[] = (string) ((int) $goodsNo - 1);
            $idsToTry[] = (string) ((int) $goodsNo + 1);
        }

        foreach ($idsToTry as $fileId) {
            $result = $this->scanCdnPatterns($goodsNo, $fileId);
            if ($result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Scan CDN patterns for a given folder goodsNo and filename ID.
     */
    private function scanCdnPatterns(string $folderGoodsNo, string $fileId): ?string
    {
        $goodsBase = self::WITHMUU_CDN_BASE."/goods/{$folderGoodsNo}/image";
        $extensions = ['jpg', 'jpeg'];

        // Phase 1: sentinel positions (quick check ~30 URLs)
        $sentinels = [1, 10, 20, 25, 30, 40, 50, 60, 64, 70, 80, 89, 90, 93, 100];

        $urls = [];
        foreach ($extensions as $ext) {
            $urls["main_Main_{$ext}"] = "{$goodsBase}/main/{$fileId}_Main.{$ext}";
            $urls["Detail0_{$ext}"] = "{$goodsBase}/detail/{$fileId}_Detail0.{$ext}";
            foreach ($sentinels as $n) {
                $num = str_pad($n, 3, '0', STR_PAD_LEFT);
                $urls["d{$num}_{$ext}"] = "{$goodsBase}/detail/{$fileId}_detail_{$num}.{$ext}";
            }
        }

        $found = $this->batchHeadCheck($urls);
        if ($found) {
            // Prefer main/legacy (smaller) over detail
            foreach ($found as $label => $info) {
                if (str_contains($label, 'main_Main') || str_contains($label, 'Detail0')) {
                    return $info['url'];
                }
            }

            return reset($found)['url'];
        }

        // Phase 2: scan remaining detail numbers in batches of 15
        $checked = array_flip($sentinels);
        for ($batchStart = 1; $batchStart <= 100; $batchStart += 15) {
            $urls = [];
            for ($i = $batchStart; $i < min($batchStart + 15, 101); $i++) {
                if (isset($checked[$i])) {
                    continue;
                }
                $num = str_pad($i, 3, '0', STR_PAD_LEFT);
                foreach ($extensions as $ext) {
                    $urls["d{$num}_{$ext}"] = "{$goodsBase}/detail/{$fileId}_detail_{$num}.{$ext}";
                }
            }
            if (empty($urls)) {
                continue;
            }

            $found = $this->batchHeadCheck($urls);
            if ($found) {
                return reset($found)['url'];
            }
        }

        return null;
    }

    /**
     * Parallel HEAD check with small batch size to avoid CDN rate-limiting.
     * Returns array of found URLs with their sizes, or empty array.
     */
    private function batchHeadCheck(array $urls): array
    {
        $mh = curl_multi_init();
        curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 15);
        $handles = [];

        foreach ($urls as $label => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = ['label' => $label, 'url' => $url, 'handle' => $ch];
        }

        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh, 0.1);
            }
        } while ($active && $status === CURLM_OK);

        $found = [];
        foreach ($handles as $info) {
            $httpCode = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);
            if ($httpCode === 200) {
                $size = (int) curl_getinfo($info['handle'], CURLINFO_CONTENT_LENGTH_DOWNLOAD);
                $found[$info['label']] = ['url' => $info['url'], 'size' => $size];
            }
            curl_multi_remove_handle($mh, $info['handle']);
            curl_close($info['handle']);
        }

        curl_multi_close($mh);

        return $found;
    }

    private function extractDirectCdnUrl(string $url): ?string
    {
        if (preg_match('/\.(jpg|jpeg|png|webp|gif)(\?|$)/i', $url)) {
            return $url;
        }

        return null;
    }

    /**
     * Standard og:image extraction for server-rendered pages (ktown4u, weverse, smtown, jypshop, etc.)
     */
    private function extractOgImage(string $pageUrl): ?string
    {
        $response = Http::timeout(15)
            ->withHeaders([
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            ])
            ->get($pageUrl);

        if (! $response->successful()) {
            return null;
        }

        $html = $response->body();

        if (preg_match('/<meta\s+(?:property|name)=["\']og:image["\']\s+content=["\']([^"\']+)["\']/i', $html, $matches)) {
            return $matches[1];
        }

        if (preg_match('/<meta\s+content=["\']([^"\']+)["\']\s+(?:property|name)=["\']og:image["\']/i', $html, $matches)) {
            return $matches[1];
        }

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $imgMatches)) {
            foreach ($imgMatches[1] as $imgSrc) {
                if (preg_match('/(logo|icon|pixel|tracking|banner|badge|flag|avatar)/i', $imgSrc)) {
                    continue;
                }
                if (preg_match('/\.(jpg|jpeg|png|webp)(\?|$)/i', $imgSrc)) {
                    if (str_starts_with($imgSrc, '//')) {
                        $imgSrc = 'https:'.$imgSrc;
                    }

                    return $imgSrc;
                }
            }
        }

        return null;
    }

    /**
     * Download image with retry support and increased timeout for large CDN images.
     */
    private function downloadImage(string $imageUrl, int $productId): ?string
    {
        if (str_starts_with($imageUrl, '//')) {
            $imageUrl = 'https:'.$imageUrl;
        }

        $isCdnUrl = str_contains($imageUrl, 'godomall.speedycdn.net');
        $timeout = $isCdnUrl ? 60 : 30;
        $maxRetries = $isCdnUrl ? 2 : 1;

        $response = null;
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                    ])
                    ->get($imageUrl);

                if ($response->successful()) {
                    break;
                }
                $response = null;
            } catch (\Throwable $e) {
                if ($attempt < $maxRetries - 1) {
                    sleep(2);

                    continue;
                }
                throw $e;
            }
        }

        if (! $response || ! $response->successful()) {
            return null;
        }

        $contentType = $response->header('Content-Type') ?? '';

        if (! str_contains($contentType, 'image') && ! $isCdnUrl) {
            return null;
        }

        $extension = 'jpg';
        if (str_contains($contentType, 'image')) {
            if (str_contains($contentType, 'png')) {
                $extension = 'png';
            } elseif (str_contains($contentType, 'webp')) {
                $extension = 'webp';
            } elseif (str_contains($contentType, 'gif')) {
                $extension = 'gif';
            }
        } elseif (preg_match('/\.(png|webp|gif|jpe?g)(?:\?|$)/i', $imageUrl, $extMatch)) {
            $ext = strtolower($extMatch[1]);
            $extension = ($ext === 'jpeg') ? 'jpg' : $ext;
        }

        $filename = "products/{$productId}_".Str::random(8).".{$extension}";
        Storage::disk('public')->put($filename, $response->body());

        return $filename;
    }
}
