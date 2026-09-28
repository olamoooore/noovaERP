{{-- Tier B shell: login / register island. Field names match the public
     auth API exactly (email, password, first_name, last_name, phone,
     marketing_email_opt_in). --}}
<div class="sf-auth sf-auth--{{ $mode }}"
     data-sf-island="{{ $mode }}"
     @if($a['redirect']) data-sf-redirect="{{ $a['redirect'] }}" @endif>
    @if($mode === 'login')
        <p class="sf-auth__note">Sign in to your account.</p>
    @else
        <p class="sf-auth__note">Create an account — we will email you a link to verify it.</p>
    @endif

    <form method="post" novalidate>
        @if($mode === 'register')
            <label class="sf-auth__field"><span>Name</span>
                <input type="text" name="first_name" autocomplete="given-name" required>
            </label>
            <label class="sf-auth__field"><span>Last name</span>
                <input type="text" name="last_name" autocomplete="family-name">
            </label>
        @endif

        <label class="sf-auth__field"><span>Email</span>
            <input type="email" name="email" autocomplete="email" required>
        </label>

        <label class="sf-auth__field"><span>Password</span>
            <input type="password" name="password"
                   autocomplete="{{ $mode === 'login' ? 'current-password' : 'new-password' }}"
                   minlength="8" required>
        </label>

        @if($mode === 'register')
            <label class="sf-auth__field"><span>Confirm password</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required>
            </label>
            @if(!empty($a['require_phone']))
                <label class="sf-auth__field"><span>Phone</span>
                    <input type="tel" name="phone" autocomplete="tel" required>
                </label>
            @endif
            <label class="sf-auth__check">
                <input type="checkbox" name="marketing_email_opt_in" value="1">
                <span>Email me news and offers (optional)</span>
            </label>
        @endif

        <button type="submit" class="sf-auth__submit">{{ $submitLabel }}</button>
        <p class="sf-auth__error" role="alert"></p>
    </form>

    @if($mode === 'login' && !empty($a['show_register_link']))
        <p class="sf-auth__alt"><a href="#storefront-register">Need an account?</a></p>
    @endif

    <noscript><p class="sf-noscript">JavaScript is required to {{ $mode }} right now.</p></noscript>
</div>
