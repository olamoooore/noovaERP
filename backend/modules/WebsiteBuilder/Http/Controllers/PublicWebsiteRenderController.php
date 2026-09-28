<?php

namespace Modules\WebsiteBuilder\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\WebsiteBuilder\Models\Page;
use Modules\WebsiteBuilder\Models\Website;

/**
 * Serves published websites as complete HTML pages.
 * This provides persistent, bookmarkable URLs for published sites
 * (similar to Odoo's website module).
 *
 * URLs: /w/{websiteSlug}, /w/{websiteSlug}/{pageSlug}
 */
class PublicWebsiteRenderController extends Controller
{
    /**
     * Serve a published website page as a complete HTML document.
     */
    public function render(Request $request, string $websiteSlug, ?string $pageSlug = null): Response
    {
        // Find website by slug in current tenant
        $website = Website::where('slug', $websiteSlug)->first();

        // Cross-tenant fallback removed for strict isolation.
        if (! $website) {
            return response($this->generateErrorPage('Website Not Found', "The website you're looking for doesn't exist."), 404);
        }

        // Only serve published websites via the public /w/ route.
        // Draft/archived sites should use the editor preview instead.
        if ($website->status !== 'published') {
            return response($this->generateErrorPage('Website Not Published', "This website hasn't been published yet. Please publish it from the editor to make it publicly accessible."), 404);
        }

        // Find the page
        if ($pageSlug === null || $pageSlug === '') {
            $page = $website->pages()
                ->where('is_home', true)
                ->with('currentVersion')
                ->first();

            if (! $page) {
                $page = $website->pages()
                    ->orderBy('sort_order')
                    ->with('currentVersion')
                    ->first();
            }
        } else {
            $page = $website->pages()
                ->where('slug', $pageSlug)
                ->with('currentVersion')
                ->first();
        }

        if (! $page) {
            return response($this->generateErrorPage('Page Not Found', "The page you're looking for doesn't exist."), 404);
        }

        // Get content from current version
        $content = $page->currentVersion?->content ?? [
            'html' => '',
            'css' => '',
        ];

        $html = $content['html'] ?? '';
        $css = $content['css'] ?? '';
        $headerJs = $content['header_js'] ?? '';
        $bodyJs = $content['body_js'] ?? '';

        // Fix broken /sites/ URLs in saved content → rewrite to /w/
        $html = str_replace('/sites/'.$website->slug, '/w/'.$website->slug, $html);
        // Also fix absolute domain URLs like https://domain.com/sites/slug
        $html = preg_replace('#https?://[^/]+/sites/'.preg_quote($website->slug, '#').'#', '/w/'.$website->slug, $html);
        // Fix relative logo path — rewrite noovaerplogo.png to /storage/noovaerplogo.png
        $html = str_replace('noovaerplogo.png', '/storage/noovaerplogo.png', $html);

        // Platform page-content filters (tagged 'page_content_filters') —
        // server-side component rendering such as the Storefront shortcode
        // renderer runs HERE, in the single canonical render path (no
        // parallel renderer). Each filter owns its own degradation; a filter
        // failure must never fatalt the page.
        foreach ($this->pageContentFilters() as $filter) {
            try {
                $html = $filter->filterHtml($html, [
                    'website_slug' => $website->slug,
                    'page_id' => $page->id,
                    'page_version_id' => $page->currentVersion?->id,
                    'mode' => 'public',
                ]);
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::error('Page content filter failed', [
                    'filter' => get_class($filter),
                    'website_slug' => $website->slug,
                    'page_id' => $page->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Get shop settings from website
        $shopSettings = $website->settings['shop'] ?? null;

        // API base URL for e-commerce (relative so it works through any proxy/port)
        $apiBaseUrl = '/api/v1/website-public';

        // Generate navigation for multi-page sites
        $pages = $website->pages()->orderBy('sort_order')->get();
        $navHtml = $this->generateNavigation($website, $pages, $pageSlug);

        // Build the complete HTML document
        $fullHtml = $this->generateFullHtml([
            'title' => $page->seo_title ?: $page->title ?: $website->name,
            'description' => $page->seo_description ?: '',
            'html' => $html,
            'css' => $css,
            'headerJs' => $headerJs,
            'bodyJs' => $bodyJs,
            'apiBaseUrl' => $apiBaseUrl,
            'shopSettings' => $shopSettings,
            'websiteSlug' => $websiteSlug,
            'pageSlug' => $pageSlug,
            'navigation' => $navHtml,
        ]);

        return response($fullHtml, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            // Revalidate instead of a 5-minute cache: editors save and preview
            // repeatedly, and max-age=300 served them pre-save content (browser
            // and Cloudflare) until the window expired.
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }

    /**
     * Generate full HTML document with all required assets.
     */
    private function generateFullHtml(array $options): string
    {
        $title = $options['title'] ?? 'Page';
        $description = $options['description'] ?? '';
        $html = $options['html'] ?? '';
        $css = $options['css'] ?? '';
        $headerJs = $options['headerJs'] ?? '';
        $bodyJs = $options['bodyJs'] ?? '';
        $apiBaseUrl = $options['apiBaseUrl'] ?? '';
        $shopSettings = $options['shopSettings'] ?? null;
        $websiteSlug = $options['websiteSlug'] ?? '';

        $shopSettingsJson = $shopSettings ? json_encode($shopSettings) : 'null';
        $escapedDescription = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

        // Wrap headerJs and bodyJs in <script> tags if they don't already have them.
        // GrapesJS custom-code editor stores raw JS without <script> wrappers.
        $headerJsWrapped = $headerJs !== ''
            ? (preg_match('/^\s*<script/i', $headerJs) ? $headerJs : "<script>\n{$headerJs}\n</script>")
            : '';
        $bodyJsWrapped = $bodyJs !== ''
            ? (preg_match('/^\s*<script/i', $bodyJs) ? $bodyJs : "<script>\n{$bodyJs}\n</script>")
            : '';

        return <<<RENDER_OUTPUT
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
  <meta name="description" content="{$escapedDescription}">
  
  <!-- Fonts -->
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css" rel="stylesheet">
  
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  
  <!-- Custom Styles -->
  <style>
    body { font-family: 'Inter', system-ui, -apple-system, sans-serif; margin: 0; padding: 0; }
    * { box-sizing: border-box; }
    img { max-width: 100%; height: auto; }
    
    /* E-commerce animations */
    @keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
    @keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
    @keyframes bounce { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
    @keyframes addToCartPop { 0% { transform: scale(1); } 30% { transform: scale(0.95); } 60% { transform: scale(1.05); } 100% { transform: scale(1); } }
    @keyframes cartBadgePop { 0% { transform: scale(1); } 50% { transform: scale(1.4); } 100% { transform: scale(1); }  }
    
    .product-card { transition: all 0.3s ease; }
    .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
    .add-to-cart-btn { transition: all 0.2s ease; position: relative; overflow: hidden; }
    .add-to-cart-btn:hover:not(:disabled) { transform: scale(1.05); }
    .add-to-cart-btn:active:not(:disabled) { transform: scale(0.95); }
    .add-to-cart-btn.adding { animation: addToCartPop 0.4s ease; pointer-events: none; }
    .cart-badge.pop { animation: cartBadgePop 0.3s ease; }
    .heart-icon { transition: all 0.2s ease; }
    .heart-icon:hover { transform: scale(1.1); }
    [data-category-filter] button { transition: all 0.2s ease; }
    [data-category-filter] button:hover { transform: translateX(4px); }
    input[type="range"] { -webkit-appearance: none; appearance: none; height: 6px; border-radius: 3px; background: #e6e6e6; }
    
    {$css}
  </style>
  
  <!-- API Configuration -->
  <script>
    window.__API_BASE_URL__ = '{$apiBaseUrl}';
    window.__SHOP_SETTINGS__ = {$shopSettingsJson};
    window.__WEBSITE_SLUG__ = '{$websiteSlug}';
  </script>
  
  {$headerJsWrapped}
</head>
<body>
  {$html}
  
  <!-- External Scripts -->
  <script src="https://unpkg.com/lucide@latest"></script>
  <script>
    // Initialize Lucide icons
    if (typeof lucide !== 'undefined') { lucide.createIcons(); }
  </script>
  
  {$bodyJsWrapped}
  
  <!-- Override any stale demoProducts embedded in old page versions -->
  <script>
  (function() {
    var apiBase = window.__API_BASE_URL__ || '/api/v1/website-public';
    var grid = document.querySelector('[data-product-grid]');
    if (!grid) return;
    
    // Show loading spinner immediately, replacing any demo product cards
    grid.innerHTML = '<div style="text-align:center;padding:60px 20px;color:#666;grid-column:1/-1;">' +
      '<div style="width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:#00b207;border-radius:50%;animation:spin 1s linear infinite;margin:0 auto 15px;"></div>' +
      'Loading products...</div>';
    
    // Fetch real products from API
    fetch(apiBase + '/products?per_page=100')
      .then(function(r) { return r.json(); })
      .then(function(resp) {
        var products = resp.data || resp || [];
        var store = window.EcommerceStore || window.shopStore;
        if (products.length > 0 && store) {
          store.allProducts = products;
          store.renderProductGrid();
          // Also refresh categories
          if (document.querySelector('[data-category-filter]')) {
            store.renderCategoryFilter();
          }
        } else if (products.length === 0) {
          grid.innerHTML = '<div style="text-align:center;padding:60px 20px;color:#666;grid-column:1/-1;">No products available.</div>';
        }
      })
      .catch(function(err) {
        console.error('Failed to load products:', err);
        grid.innerHTML = '<div style="text-align:center;padding:60px 20px;color:#999;grid-column:1/-1;">Unable to load products. Please try again later.</div>';
      });
  })();
  </script>
</body>
</html>
RENDER_OUTPUT;
    }

    /**
     * Generate navigation HTML for multi-page websites.
     */
    private function generateNavigation(Website $website, $pages, ?string $currentSlug): string
    {
        if ($pages->count() <= 1) {
            return '';
        }

        $baseUrl = '/w/'.$website->slug;
        $links = $pages->map(function ($page) use ($baseUrl, $currentSlug) {
            $url = $page->is_home ? $baseUrl : $baseUrl.'/'.$page->slug;
            $active = ($page->slug === $currentSlug) || ($currentSlug === null && $page->is_home);
            $activeClass = $active ? 'font-bold' : '';

            return "<a href=\"{$url}\" class=\"{$activeClass}\" style=\"text-decoration:none;color:inherit;\">{$page->title}</a>";
        })->join("\n");

        return $links;
    }

    /**
     * Tagged platform page-content filters (e.g. Storefront shortcodes).
     * Resolved lazily so a missing module never fatals the render path.
     *
     * @return iterable<\App\Contracts\PageContentFilter>
     */
    private function pageContentFilters(): iterable
    {
        try {
            return app()->tagged('page_content_filters');
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Generate a styled error page.
     */
    private function generateErrorPage(string $title, string $message): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$title}</title>
  <style>
    body { font-family: 'Inter', system-ui, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; background: #f9f9f9; color: #333; }
    .container { text-align: center; padding: 40px; }
    h1 { font-size: 2.5rem; font-weight: 700; margin-bottom: 10px; }
    p { font-size: 1.1rem; color: #666; margin-bottom: 30px; }
    a { color: #00b207; text-decoration: none; font-weight: 600; }
    a:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="container">
    <h1>{$title}</h1>
    <p>{$message}</p>
    <a href="/">Go to Homepage</a>
  </div>
</body>
</html>
HTML;
    }
}
