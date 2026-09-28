{{-- Tier B dashboard shell: the runtime fills it from the account APIs;
     anonymous visitors keep the prompt (API answers 401 -> sign-in copy). --}}
<div class="sf-dashboard__block sf-dashboard__block--{{ $island }}"
     data-sf-island="{{ $island }}"
     @if(isset($a['limit'])) data-sf-limit="{{ $a['limit'] }}" @endif
     @if(isset($a['id']) && (int) $a['id'] > 0) data-sf-order-id="{{ $a['id'] }}" @endif
     @if(isset($a['mode'])) data-sf-mode="{{ $a['mode'] }}" @endif>
    <h3 class="sf-dashboard__title">{{ $label }}</h3>
    <div class="sf-dashboard__body">
        <p class="sf-dashboard__loading">Loading…</p>
        <noscript><p class="sf-noscript">JavaScript is required for this section.</p></noscript>
    </div>
</div>
