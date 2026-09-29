{{-- Tier A single product detail (FR-SF-02/07/08): variants, tenant-policy
     availability, quantity control and the add-to-cart island. --}}
@php
    use Modules\Storefront\Support\StorefrontFormatter;
    $settings = $context->settings();
    $availabilityLabels = [
        'in' => 'In stock',
        'low' => 'Low stock',
        'backorder' => 'Available on backorder',
        'out' => 'Out of stock',
        'available' => 'Available',
    ];
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
        <p class="sf-product__price" data-sf-base-price>{{ StorefrontFormatter::price($settings->currency_code, $item['price']) }}</p>
        <span class="sf-product__availability sf-availability--{{ $item['availability'] }}">
            {{ $availabilityLabels[$item['availability']] ?? $item['availability_label'] }}
        </span>
        @if($item['category'])
            <p class="sf-product__category">{{ $item['category']['name'] }}</p>
        @endif
        @if($layout === 'detail' && $item['description'])
            <div class="sf-product__description">{{ $item['description'] }}</div>
        @elseif($item['short_description'])
            <p class="sf-product__excerpt">{{ $item['short_description'] }}</p>
        @endif

        @if($layout === 'detail')
            <div class="sf-product__purchase">
                @if(!empty($item['variants']))
                    <fieldset class="sf-product__variants">
                        <legend>Options</legend>
                        @foreach($item['variants'] as $variant)
                            <label class="sf-product__variant">
                                <input type="radio" name="sf_variant" value="{{ $variant['id'] }}">
                                <span>{{ $variant['name'] }}</span>
                                <span class="sf-product__variant-price">
                                    {{ StorefrontFormatter::price($settings->currency_code, (float) $variant['price']) }}
                                </span>
                                <span class="sf-availability--{{ $variant['availability'] }}">
                                    {{ $availabilityLabels[$variant['availability']] ?? '' }}
                                </span>
                            </label>
                        @endforeach
                    </fieldset>
                @endif

                <label class="sf-product__quantity">
                    <span>Quantity</span>
                    <input type="number" name="sf_quantity" value="1" min="1" max="999" step="1">
                </label>

                <button type="button" class="sf-add-to-cart"
                        data-sf-island="add_to_cart"
                        data-sf-product-id="{{ $item['id'] }}"
                        data-sf-quantity="1">
                    <span class="sf-atc__label">Add to cart</span>
                </button>
            </div>
        @endif

        @if(!empty($related))
            <div class="sf-product__related">
                <h4>Related products</h4>
                @foreach($related as $r)
                    <p class="sf-product__related-item" data-sf-product-id="{{ $r['id'] }}">
                        {{ $r['name'] }} — {{ StorefrontFormatter::price($settings->currency_code, $r['price']) }}
                    </p>
                @endforeach
            </div>
        @endif
    </div>
</article>
