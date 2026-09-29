{{-- Tier A product grid (FR-SF-01/07). Stable sf-* classes + design tokens. --}}
@php
    use Modules\Storefront\Support\StorefrontFormatter;
    $settings = $context->settings();
@endphp
@if(!empty($heading))
    <h2 class="sf-products__heading">{{ $heading }}</h2>
@endif
<div class="sf-products sf-products--{{ $layout }}" data-sf-function="products" style="--sf-columns: {{ $columns }};">
    @forelse($items as $item)
        @include('storefront::shortcodes.partials.product-card', ['item' => $item, 'settings' => $settings, 'productPage' => $productPage ?? null])
    @empty
        <p class="sf-products__empty">{{ $empty_message ?? 'No products are available here yet. Please check back soon.' }}</p>
    @endforelse
    <p class="sf-products__tax-note">{{ StorefrontFormatter::taxNote($settings->tax_display) }}</p>
</div>
