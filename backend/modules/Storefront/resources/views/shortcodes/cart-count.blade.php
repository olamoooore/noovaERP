{{-- Tier B shell: live cart item-count badge. --}}
<span class="sf-count" data-sf-island="cart_count">
    @if($a['label'] !== '')<span class="sf-count__label">{{ $a['label'] }}</span>@endif
    <span class="sf-count__value" aria-label="items in cart">0</span>
</span>
