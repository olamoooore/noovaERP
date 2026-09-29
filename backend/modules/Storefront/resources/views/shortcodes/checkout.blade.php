{{-- Tier B shell: checkout. The runtime hydrates the form; all authoritative
     values (totals, stock, gateway truth) are re-derived server-side. --}}
<div class="sf-checkout"
     data-sf-island="checkout"
     data-sf-show-billing="{{ $showBilling ? '1' : '0' }}"
     @if($termsUrl) data-sf-terms-url="{{ $termsUrl }}" @endif>
    <div class="sf-checkout__body">
        <p class="sf-checkout__loading">Loading your cart…</p>
        <noscript><p class="sf-noscript">JavaScript is required to check out.</p></noscript>
    </div>
</div>
