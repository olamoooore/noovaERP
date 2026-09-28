{{-- Tier B shell: add-to-cart button. data-sf-* consumed by the storefront runtime. --}}
<button type="button"
        class="sf-add-to-cart sf-add-to-cart--{{ $a['style'] }}"
        data-sf-island="add_to_cart"
        data-sf-product-id="{{ $a['product_id'] }}"
        @if((int) $a['variant']) data-sf-variant-id="{{ $a['variant'] }}" @endif
        data-sf-quantity="{{ $a['quantity'] }}"
        @if($a['redirect']) data-sf-redirect="{{ $a['redirect'] }}" @endif>
    <span class="sf-atc__label">{{ $a['label'] !== '' ? $a['label'] : 'Add to cart' }}</span>
</button>
<noscript><span class="sf-noscript">JavaScript is required to add items to your cart.</span></noscript>
