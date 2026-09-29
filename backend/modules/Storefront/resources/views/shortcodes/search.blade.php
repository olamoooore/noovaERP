{{-- Tier A search box + inline results (FR-SF-04). The box works without JS
     (GET ?sfq=); the runtime upgrades it with live results against the
     public catalog API — same visibility rules as everything else. --}}
@php
    use Modules\Storefront\Support\StorefrontFormatter;
    $settings = $context->settings();
@endphp
<div class="sf-search" data-sf-island="search">
    <form method="get" action="{{ $target ?? '' }}" class="sf-search__form" role="search">
        <input type="search" name="sfq" class="sf-search__input"
               placeholder="{{ $a['placeholder'] }}" aria-label="Search products"
               value="{{ $initial }}">
        <button type="submit" class="sf-search__submit">Search</button>
    </form>

    @if($a['inline_results'])
        <div class="sf-search__results" data-sf-search-results data-sf-limit="{{ $a['limit'] }}">
            @if($initial !== '')
                @forelse($results as $item)
                    <p class="sf-search__row">
                        {{ $item['name'] }} —
                        {{ StorefrontFormatter::price($settings->currency_code, $item['price']) }}
                        <span class="sf-availability--{{ $item['availability'] }}">{{ $item['availability_label'] }}</span>
                    </p>
                @empty
                    <p class="sf-search__none">No results for “{{ $initial }}”.</p>
                @endforelse
            @endif
        </div>
    @endif
</div>
