{{-- Tier A single product detail (FR-SF-02/07). --}}
@php
    use Modules\Storefront\Support\StorefrontFormatter;
    $settings = $context->settings();
@endphp
<article class="sf-product sf-product--{{ $layout }}" data-sf-function="product" data-sf-product-id="{{ $item['id'] }}">
    <div class="sf-product__media">
        @if($item['image'])
            <img class="sf-product__image" src="{{ $item['image'] }}" alt="{{ $item['name'] }}" loading="lazy">
        @endif
        @if($layout === 'detail' && !empty($item['gallery']))
            <div class="sf-product__gallery">
                @foreach($item['gallery'] as $g)
                    <img src="{{ $g }}" alt="{{ $item['name'] }}" loading="lazy">
                @endforeach
            </div>
        @endif
    </div>
    <div class="sf-product__body">
        <h2 class="sf-product__name">{{ $item['name'] }}</h2>
        @if($item['secondary_name'])
            <p class="sf-product__secondary">{{ $item['secondary_name'] }}</p>
        @endif
        <p class="sf-product__price">{{ StorefrontFormatter::price($settings->currency_code, $item['price']) }}</p>
        <span class="sf-product__availability sf-availability--{{ $item['in_stock'] ? 'in' : 'out' }}">
            {{ $item['in_stock'] ? 'In stock' : 'Out of stock' }}
        </span>
        @if($item['category'])
            <p class="sf-product__category">{{ $item['category']['name'] }}</p>
        @endif
        @if($layout === 'detail' && $item['description'])
            <div class="sf-product__description">{{ $item['description'] }}</div>
        @elseif($item['short_description'])
            <p class="sf-product__excerpt">{{ $item['short_description'] }}</p>
        @endif

        {{-- Tier B add-to-cart control is wired in P1 (runtime islands). --}}

        @if(!empty($related))
            <div class="sf-product__related">
                <h4>Related products</h4>
                @foreach($related as $r)
                    <span class="sf-product__related-item" data-sf-product-id="{{ $r['id'] }}">
                        {{ $r['name'] }} — {{ StorefrontFormatter::price($settings->currency_code, $r['price']) }}
                    </span>
                @endforeach
            </div>
        @endif
    </div>
</article>
