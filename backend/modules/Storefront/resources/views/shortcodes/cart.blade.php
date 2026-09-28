{{-- Tier B shell: cart page or drawer. Empty-state text lives HERE (no-JS
     fallback); the runtime replaces it with the server-quoted cart. --}}
<div class="sf-cart sf-cart--{{ $island }}"
     data-sf-island="{{ $island }}"
     data-sf-show-checkout="{{ !empty($a['show_checkout_button']) ? '1' : '0' }}"
     @if($island === 'cart_drawer')
         data-sf-position="{{ $a['position'] ?? 'right' }}"
         data-sf-open-on-add="{{ !empty($a['open_on_add']) ? '1' : '0' }}"
         hidden
     @endif>
    @if($island === 'cart_drawer')
        <button type="button" class="sf-cart__opener" data-sf-drawer-toggle>Cart</button>
    @endif
    <div class="sf-cart__body">
        <p class="sf-cart__fallback">Your cart will appear here.</p>
        <noscript><span class="sf-noscript">JavaScript is required to manage your cart.</span></noscript>
    </div>
</div>
