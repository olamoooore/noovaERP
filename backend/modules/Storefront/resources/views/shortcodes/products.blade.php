{{-- Tier A product grid (FR-SF-01/07). Stable sf-* classes + design tokens (Appendix B §B7). --}}
@php
    use Modules\Storefront\Support\StorefrontFormatter;
    $settings = $context->settings();
    $taxNote = StorefrontFormatter::taxNote($settings->tax_display);
@endphp
<div class="sf-products sf-products--{{ $layout }}" data-sf-function="products" style="--sf-columns: {{ $columns }};">
    @if(count($items) === 0)
        <p class="sf-products__empty">No products are available here yet. Please check back soon.</p>
    @else
        @foreach($items as $item)
            <article class="sf-product-card" data-sf-product-id="{{ $item['id'] }}">
                @if($item['image'])
                    <img class="sf-product-card__image" src="{{ $item['image'] }}" alt="{{ $item['name'] }}" loading="lazy">
                @endif
                <h3 class="sf-product-card__name">{{ $item['name'] }}</h3>
                @if($item['secondary_name'])
                    <p class="sf-product-card__secondary">{{ $item['secondary_name'] }}</p>
                @endif
                <p class="sf-product-card__price">{{ StorefrontFormatter::price($settings->currency_code, $item['price']) }}</p>
                @if($item['category'])
                    <p class="sf-product-card__category">{{ $item['category']['name'] }}</p>
                @endif
                <span class="sf-product-card__availability sf-availability--{{ $item['in_stock'] ? 'in' : 'out' }}">
                    {{ $item['in_stock'] ? 'In stock' : 'Out of stock' }}
                </span>
                @if($item['short_description'])
                    <p class="sf-product-card__excerpt">{{ $item['short_description'] }}</p>
                @endif
            </article>
        @endforeach
    @endif
    <p class="sf-products__tax-note">{{ $taxNote }}</p>
</div>
