{{-- Shared public product card (used by grid + category + search results).
     $item comes from CatalogQuery::present() — internal fields were never
     selected, so there is nothing sensitive to forget to hide here. --}}
@php
    use Modules\Storefront\Support\StorefrontFormatter;
    $href = !empty($productPage) ? $productPage.'?product='.$item['id'] : null;
@endphp
<article class="sf-product-card" data-sf-product-id="{{ $item['id'] }}">
    @if($item['image'])
        <img class="sf-product-card__image" src="{{ $item['image'] }}" alt="{{ $item['name'] }}" loading="lazy">
    @endif
    <h3 class="sf-product-card__name">
        @if($href)<a href="{{ $href }}">{{ $item['name'] }}</a>@else{{ $item['name'] }}@endif
    </h3>
    @if($item['secondary_name'])
        <p class="sf-product-card__secondary">{{ $item['secondary_name'] }}</p>
    @endif
    <p class="sf-product-card__price">{{ StorefrontFormatter::price($settings->currency_code, $item['price']) }}</p>
    @if($item['category'])
        <p class="sf-product-card__category">{{ $item['category']['name'] }}</p>
    @endif
    <span class="sf-product-card__availability sf-availability--{{ $item['availability'] }}">
        {{ $item['availability_label'] }}
    </span>
    @if($item['short_description'])
        <p class="sf-product-card__excerpt">{{ $item['short_description'] }}</p>
    @endif
</article>
