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
                            {--tenant= : Tenant ID to run under}
                            {--source= : Only process URLs matching this domain (e.g. withmuu, ktown4u)}
                            {--skip-existing : Skip products that already have a local image}
                            {--netfunnel-cookie= : NetFunnel cookie value for withmuu.com (get from browser DevTools)}';

    protected $description = 'Download product images from weblink URLs stored in the image field';

    private const WITHMUU_CDN_BASE = 'https://godomall.speedycdn.net/c03670ee9f741fa0343b16321feede3e';

    // Cache: goodsNo => CDN image URL (avoids re-scanning for products sharing same withmuu page)
    private array $withmuu_cache = [];

    private ?string $netfunnelCookie = null;

    public function handle()
    {
        $tenantId = $this->option('tenant');
        if ($tenantId) {
            tenancy()->initialize($tenantId);
            $this->info("Running under tenant: {$tenantId}");
        }

        // Acquire NetFunnel cookie for withmuu.com
        $this->netfunnelCookie = $this->option('netfunnel-cookie') ?: $this->acquireNetfunnelCookie();
        if ($this->netfunnelCookie) {
            $this->info('NetFunnel cookie acquired for withmuu.com');
        }

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

            if ($dryRun) {
                $this->line("\n  [{$product->id}] {$product->name} → {$url}");
                $bar->advance();
                continue;
            }

            try {
                $imageUrl = $this->extractImageUrl($url);
                if (!$imageUrl) {
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
                $this->error("\n  Error for {$product->name}: {$e->getMessage()}");
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
        // Route to site-specific handlers
        if (str_contains($pageUrl, 'withmuu.com')) {
            return $this->extractWithmuuImage($pageUrl);
        }

        if (str_contains($pageUrl, 'withdrama.')) {
            return $this->extractDirectCdnUrl($pageUrl, 'withdrama');
        }

        // For all other sites (ktown4u, weverse, smtown, jypshop, etc.) use og:image
        return $this->extractOgImage($pageUrl);
    }

    /**
     * withmuu.com: JS-rendered site behind NetFunnel queue.
     * Strategy: Use a NetFunnel cookie to fetch the full page HTML,
     * then extract the CDN image URL from the inline detailKeyID JS array.
     * Falls back to CDN pattern scanning if page fetch fails.
     */
    private function extractWithmuuImage(string $url): ?string
    {
        if (!preg_match('/goodsNo=(\d+)/', $url, $m)) {
            return null;
        }
        $goodsNo = $m[1];

        // Check cache first (many products share same withmuu page)
        if (isset($this->withmuu_cache[$goodsNo])) {
            return $this->withmuu_cache[$goodsNo];
        }

        // Primary: fetch page with NetFunnel cookie and extract CDN URL from inline JS
        $imageUrl = $this->fetchWithmuuPageImage($goodsNo);

        // Fallback: CDN pattern scanning
        if (!$imageUrl) {
            $imageUrl = $this->findWithmuuCdnImage($goodsNo);
        }

        if ($imageUrl) {
            $this->withmuu_cache[$goodsNo] = $imageUrl;
        }

        return $imageUrl;
    }

    /**
     * Fetch the withmuu product page using the NetFunnel cookie to bypass the queue,
     * then extract the CDN image URL from the inline detailKeyID JavaScript array.
     */
    private function fetchWithmuuPageImage(string $goodsNo): ?string
    {
        if (!$this->netfunnelCookie) {
            return null;
        }

        $ch = curl_init("https://withmuu.com/goods/goods_view.php?goodsNo={$goodsNo}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                "Cookie: NetFunnel_ID={$this->netfunnelCookie}",
            ],
        ]);
        $html = curl_exec($ch);
        curl_close($ch);

        if (!$html) {
            return null;
        }

        // Extract CDN URL from the detailKeyID inline JS array
        if (preg_match('/detailKeyID\[0\]\s*=\s*"[^"]*src=\\\\"(https?:\/\/godomall\.speedycdn\.net[^\\\\]+)\\\\"/', $html, $m)) {
            return $m[1];
        }

        // Fallback: try to find any godomall CDN URL in the page for the detail image
        if (preg_match('/https:\/\/godomall\.speedycdn\.net\/[^"\'\\s]+\/image\/detail\/[^"\'\\s]+\.jpg/', $html, $m)) {
            return $m[0];
        }

        return null;
    }

    /**
     * Acquire a NetFunnel cookie by calling the NHN Commerce NetFunnel service.
     * The cookie can be reused across multiple product page requests.
     */
    private function acquireNetfunnelCookie(): ?string
    {
        $ch = curl_init('https://netfunnel.nhn-commerce.com/ts.wseq?opcode=5101&nfid=0&prefix=NetFunnel.gRtype%3D5101%3B&sid=service_1&aid=intro_09&js=yes');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36',
                'Referer: https://withmuu.com/',
            ],
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response && preg_match("/5002:200:([^']+)/", $response, $m)) {
            return urlencode($m[1]);
        }

        return null;
    }

    private function findWithmuuCdnImage(string $goodsNo): ?string
    {
        $goodsBase = self::WITHMUU_CDN_BASE . "/goods/{$goodsNo}/image";

        // Check ALL patterns in a single parallel batch for speed
        $urls = [];
        // Legacy patterns (old-style products)
        $urls['legacy_detail'] = "{$goodsBase}/detail/{$goodsNo}_Detail0.jpg";
        $urls['legacy_main'] = "{$goodsBase}/main/{$goodsNo}_Main.jpg";

        // Numeric detail patterns: _detail_001 .. _detail_100
        for ($i = 1; $i <= 100; $i++) {
            $num = str_pad($i, 3, '0', STR_PAD_LEFT);
            $urls["detail_{$num}"] = "{$goodsBase}/detail/{$goodsNo}_detail_{$num}.jpg";
        }

        // Also check magnify folder (same product, sometimes different numbering)
        for ($i = 1; $i <= 100; $i++) {
            $num = str_pad($i, 3, '0', STR_PAD_LEFT);
            $urls["magnify_{$num}"] = "{$goodsBase}/magnify/{$goodsNo}_magnify_{$num}.jpg";
        }

        return $this->parallelHeadCheck($urls);
    }

    private function headCheck(string $url): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    /**
     * Use curl_multi to do parallel HEAD requests and find the first URL returning HTTP 200.
     */
    private function parallelHeadCheck(array $urls): ?string
    {
        $mh = curl_multi_init();
        curl_multi_setopt($mh, CURLMOPT_MAXCONNECTS, 20);
        $handles = [];

        foreach ($urls as $num => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[(int) $ch] = ['url' => $url, 'handle' => $ch];
        }

        // Execute all requests in parallel
        do {
            $status = curl_multi_exec($mh, $active);
            if ($active) {
                curl_multi_select($mh);
            }
        } while ($active && $status === CURLM_OK);

        $foundUrl = null;
        foreach ($handles as $info) {
            $httpCode = curl_getinfo($info['handle'], CURLINFO_HTTP_CODE);
            if ($httpCode === 200 && !$foundUrl) {
                $foundUrl = $info['url'];
            }
            curl_multi_remove_handle($mh, $info['handle']);
            curl_close($info['handle']);
        }

        curl_multi_close($mh);
        return $foundUrl;
    }

    /**
     * Handle URLs that contain a direct CDN image URL as a query parameter or in the CSV data.
     */
    private function extractDirectCdnUrl(string $url, string $type): ?string
    {
        // withdrama URLs are often direct image links
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

        // Fallback: try to find first large product image in img tags
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $imgMatches)) {
            foreach ($imgMatches[1] as $imgSrc) {
                // Skip tiny icons, logos, tracking pixels
                if (preg_match('/(logo|icon|pixel|tracking|banner|badge|flag|avatar)/i', $imgSrc)) {
                    continue;
                }
                // Only accept common image CDN patterns
                if (preg_match('/\.(jpg|jpeg|png|webp)(\?|$)/i', $imgSrc)) {
                    if (str_starts_with($imgSrc, '//')) {
                        $imgSrc = 'https:' . $imgSrc;
                    }
                    return $imgSrc;
                }
            }
        }

        return null;
    }

    private function downloadImage(string $imageUrl, int $productId): ?string
    {
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

        // Verify it's actually an image
        $contentType = $response->header('Content-Type') ?? '';
        $isCdnUrl = str_contains($imageUrl, 'godomall.speedycdn.net');

        // Some CDNs (like Godo Mall) return wrong content-type; fall back to URL extension
        if (!str_contains($contentType, 'image') && !$isCdnUrl) {
            return null;
        }

        // Determine extension from content-type first, then URL
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

        $filename = "products/{$productId}_" . Str::random(8) . ".{$extension}";
        Storage::disk('public')->put($filename, $response->body());

        return $filename;
    }
}
