{{-- Tier A category navigation with visible-catalog counts (FR-SF-03). --}}
<div class="sf-categories sf-categories--{{ $layout }}" data-sf-function="categories" style="--sf-columns: {{ $columns }};">
    @forelse($items as $cat)
        @php $url = $categoryPage ? $categoryPage.'?sfcat='.rawurlencode($cat['name']) : null; @endphp
        <div class="sf-category-card">
            <h3 class="sf-category-card__name">
                @if($url)<a href="{{ $url }}">{{ $cat['name'] }}</a>@else{{ $cat['name'] }}@endif
            </h3>
            @if($showCount)
                <p class="sf-category-card__count">{{ $cat['product_count'] }} {{ Str::plural('product', $cat['product_count']) }}</p>
            @endif
        </div>
    @empty
        <p class="sf-categories__empty">No collections yet.</p>
    @endforelse
</div>
