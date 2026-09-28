/*
 * Storefront runtime v1 — tenant-neutral, dependency-free islands (decision D4).
 *
 * Contract with the server:
 *  - Tier B shortcodes render SHELLS with data-sf-island + data-sf-* config
 *    attributes. This bundle mounts only what exists on the page.
 *  - All money/identity values come from the API (credentials: same-origin
 *    cookie flow). The bundle NEVER computes prices or totals client-side
 *    (FR-CO-03); it renders server responses verbatim via textContent, so
 *    product/account data can never inject markup (XSS).
 *  - No inline handlers / eval: CSP-compatible (FR-PLAT-06). Served immutable
 *    from /storefront/assets/v{n}/; the page declares its pin via
 *    data-sf-runtime on islands (reserved for v2+ compat, B5 versioning).
 */
(function () {
  'use strict';

  var API = '/api/v1/storefront';

  /* ---------- tiny helpers (textContent-only DOM building) ---------- */

  function el(tag, cls, text) {
    var node = document.createElement(tag);
    if (cls) node.className = cls;
    if (text !== undefined && text !== null) node.textContent = String(text);
    return node;
  }

  function money(v) { return String(v); } // server sends 2-decimal strings — pass through untouched

  function cookieValue(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function req(path, options) {
    var opts = options || {};
    opts.credentials = 'same-origin';
    opts.headers = Object.assign(
      { 'Accept': 'application/json', 'Content-Type': 'application/json' },
      opts.headers || {}
    );
    var csrf = cookieValue('sf_csrf');
    if (csrf) opts.headers['X-CSRF-Token'] = csrf;
    if (opts.body && typeof opts.body !== 'string') opts.body = JSON.stringify(opts.body);
    return fetch(API + path, opts).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  function announce(msg) {
    document.dispatchEvent(new CustomEvent('sf:announce', { detail: msg }));
  }

  function refreshCartIslands() {
    document.querySelectorAll('[data-sf-island="cart"],[data-sf-island="cart_drawer"]').forEach(mountCart);
    document.querySelectorAll('[data-sf-island="cart_count"]').forEach(mountCount);
  }

  /* ---------- add_to_cart ---------- */

  function mountAddToCart(island) {
    if (island.dataset.sfMounted) return;
    island.dataset.sfMounted = '1';

    var productId = parseInt(island.dataset.sfProductId, 10);
    if (!productId) return;
    var variantId = island.dataset.sfVariantId ? parseInt(island.dataset.sfVariantId, 10) : null;
    var qty = Math.max(1, parseInt(island.dataset.sfQuantity || '1', 10));
    var redirect = island.dataset.sfRedirect;

    island.addEventListener('click', function () {
      if (island.disabled) return;
      island.disabled = true;
      req('/cart/items', { method: 'POST', body: { product_id: productId, variant_id: variantId, quantity: qty } })
        .then(function (res) {
          if (res.ok) {
            announce({ type: 'success', text: 'Item added to cart.' });
            document.dispatchEvent(new CustomEvent('sf:cart-updated'));
            refreshCartIslands();
            if (redirect) window.location.href = redirect;
          } else {
            announce({ type: 'error', text: (res.data && res.data.message) || 'Could not add to cart.' });
          }
        })
        .catch(function () { announce({ type: 'error', text: 'Network error — please try again.' }); })
        .finally(function () { island.disabled = false; });
    });
  }

  /* ---------- cart_count ---------- */

  function mountCount(island) {
    var badge = island.querySelector('.sf-count__value') || island;
    req('/cart').then(function (res) {
      badge.textContent = res.ok ? String(res.data.data.item_count || 0) : '0';
    }).catch(function () { /* silent — badge is decoration */ });
  }

  /* ---------- cart / cart_drawer ---------- */

  function renderCart(container, quote) {
    container.textContent = '';
    var items = quote.items || [];

    if (!items.length) {
      container.appendChild(el('p', 'sf-cart__empty', 'Your cart is empty.'));
      return;
    }

    var list = el('ul', 'sf-cart__lines');
    items.forEach(function (item) {
      var line = el('li', 'sf-cart__line');
      line.dataset.itemId = item.id;

      line.appendChild(el('span', 'sf-cart__name', item.name));
      if (item.variant_id) line.appendChild(el('span', 'sf-cart__variant', '#' + item.variant_id));

      var qtyBox = el('span', 'sf-cart__qty');
      var minus = el('button', 'sf-cart__qty-btn', '−');
      minus.type = 'button'; minus.setAttribute('aria-label', 'Decrease quantity of ' + item.name);
      var val = el('span', 'sf-cart__qty-value', String(parseInt(item.quantity, 10)));
      var plus = el('button', 'sf-cart__qty-btn', '+');
      plus.type = 'button'; plus.setAttribute('aria-label', 'Increase quantity of ' + item.name);

      minus.addEventListener('click', function () { patch(item.id, Math.max(0, parseInt(val.textContent, 10) - 1)); });
      plus.addEventListener('click', function () { patch(item.id, parseInt(val.textContent, 10) + 1); });
      qtyBox.appendChild(minus); qtyBox.appendChild(val); qtyBox.appendChild(plus);
      line.appendChild(qtyBox);

      line.appendChild(el('span', 'sf-cart__price', money(item.line_total)));

      var remove = el('button', 'sf-cart__remove', 'Remove');
      remove.type = 'button';
      remove.addEventListener('click', function () { patch(item.id, 0); });
      line.appendChild(remove);

      list.appendChild(line);
    });
    container.appendChild(list);

    var t = quote.totals || {};
    var totals = el('dl', 'sf-cart__totals');
    [['Subtotal', t.subtotal], ['Tax', t.tax], ['Total', t.total]].forEach(function (pair) {
      totals.appendChild(el('dt', null, pair[0]));
      totals.appendChild(el('dd', pair[1] === t.total ? 'sf-cart__total' : null, money(pair[1]) + ' ' + (quote.currency || '')));
    });
    container.appendChild(totals);

    var checkout = container.closest('[data-sf-show-checkout="1"]');
    if (checkout) {
      var btn = el('a', 'sf-cart__checkout', 'Continue to checkout');
      btn.href = checkout.dataset.sfCheckoutUrl || '#';
      btn.setAttribute('aria-disabled', 'true');
      btn.title = 'Checkout opens when payment is enabled for this store.';
      container.appendChild(btn);
    }
  }

  function mountCart(island) {
    var body = island.querySelector('.sf-cart__body');
    if (!body) return;
    island.dataset.sfMounted = '1';
    req('/cart').then(function (res) {
      if (res.ok) renderCart(body, res.data.data || {});
      else body.textContent = 'We could not load your cart.';
    }).catch(function () { body.textContent = 'We could not load your cart. Please try again.'; });

    function patch(itemId, qty) {
      req('/cart/items/' + itemId, { method: qty > 0 ? 'PATCH' : 'DELETE', body: qty > 0 ? { quantity: qty } : undefined })
        .then(function (res) {
          if (res.ok) { refreshCartIslands(); announce({ type: 'cart', text: 'Cart updated.' }); }
          else announce({ type: 'error', text: (res.data && res.data.message) || 'Update failed.' });
        })
        .catch(function () { announce({ type: 'error', text: 'Network error — please try again.' }); });
    }

    if (island.dataset.sfIsland === 'cart_drawer') {
      var opener = island.querySelector('[data-sf-drawer-toggle]');
      if (opener) opener.addEventListener('click', function () { island.toggleAttribute('hidden'); });
      if (island.dataset.sfOpenOnAdd === '1') {
        document.addEventListener('sf:cart-updated', function () { island.removeAttribute('hidden'); });
      }
    }
  }

  /* ---------- auth islands (login / register) ---------- */

  function mountAuth(island) {
    if (island.dataset.sfMounted) return;
    island.dataset.sfMounted = '1';

    var form = island.querySelector('form');
    if (!form) return;
    var endpoint = island.dataset.sfIsland === 'login' ? '/auth/login' : '/auth/register';
    var msgBox = island.querySelector('.sf-auth__error');
    var redirect = island.dataset.sfRedirect;

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      if (msgBox) { msgBox.textContent = ''; }
      var data = {};
      new FormData(form).forEach(function (v, k) { data[k] = v; });

      req(endpoint, { method: 'POST', body: data }).then(function (res) {
        if (res.ok) {
          announce({ type: 'success', text: endpoint === '/auth/login' ? 'Signed in.' : 'Account created — check your inbox to verify.' });
          refreshCartIslands();
          if (redirect) window.location.href = redirect;
          else if (endpoint === '/auth/login') window.location.reload();
          else form.reset();
        } else {
          var msg = (res.data && res.data.message) || 'Something went wrong. Please try again.';
          if (msgBox) { msgBox.textContent = msg; } else { announce({ type: 'error', text: msg }); }
        }
      }).catch(function () {
        if (msgBox) msgBox.textContent = 'Network error — please try again.';
      });
    });
  }

  /* ---------- account summary ---------- */

  function mountAccount(island) {
    var body = island.querySelector('.sf-account__body');
    if (!body) return;
    req('/account').then(function (res) {
      body.textContent = '';
      if (!res.ok) {
        body.appendChild(el('p', 'sf-account__anon', 'Sign in to see your orders and details.'));
        return;
      }
      var a = res.data.data;
      body.appendChild(el('p', 'sf-account__hello', 'Hello' + (a.first_name ? ', ' + a.first_name : '')));
      body.appendChild(el('p', 'sf-account__meta', 'Orders: ' + a.orders_count + ' · Cart items: ' + a.active_cart_items));
      var out = el('button', 'sf-account__logout', 'Sign out');
      out.type = 'button';
      out.addEventListener('click', function () {
        req('/auth/logout', { method: 'POST' }).then(function () { window.location.reload(); });
      });
      body.appendChild(out);
    }).catch(function () { body.textContent = 'Could not load your account.'; });
  }

  /* ---------- dashboard islands (orders/order/addresses/profile/security/verify) ---------- */

  function dashBody(island) { return island.querySelector('.sf-dashboard__body'); }

  function dashNeedAuth(body) {
    body.textContent = '';
    body.appendChild(el('p', 'sf-dashboard__anon', 'Please sign in to see this section.'));
  }

  function dashError(body, msg) {
    body.textContent = '';
    body.appendChild(el('p', 'sf-dashboard__error', msg));
  }

  function field(labelText, name, type, value, required) {
    var lab = el('label', 'sf-field');
    lab.appendChild(el('span', null, labelText));
    var inp = el('input');
    inp.name = name; inp.type = type || 'text';
    if (value !== undefined && value !== null) inp.value = value;
    if (required) inp.required = true;
    lab.appendChild(inp);
    return { label: lab, input: inp };
  }

  function mountOrders(island) {
    var body = dashBody(island);
    if (!body) return;
    req('/account/orders?per_page=' + encodeURIComponent(island.dataset.sfLimit || '10')).then(function (res) {
      body.textContent = '';
      if (res.status === 401) { dashNeedAuth(body); return; }
      if (!res.ok) { dashError(body, 'Could not load your orders.'); return; }
      var orders = res.data.data || [];
      if (!orders.length) { body.appendChild(el('p', 'sf-dashboard__empty', 'No orders yet.')); return; }

      var list = el('ul', 'sf-orders__list');
      orders.forEach(function (o) {
        var row = el('li', 'sf-orders__row');
        row.appendChild(el('span', 'sf-orders__num', o.order_number));
        row.appendChild(el('span', 'sf-orders__date', (o.placed_at || '').slice(0, 10)));
        row.appendChild(el('span', 'sf-orders__status', o.status + ' / ' + o.payment_status));
        row.appendChild(el('span', 'sf-orders__total', o.total + ' ' + (o.currency || '')));

        var acts = el('span', 'sf-orders__actions');
        var view = el('button', 'sf-orders__view', 'View');
        view.type = 'button';
        view.addEventListener('click', function () { openOrderDetail(row, o.id); });
        acts.appendChild(view);

        if (o.status === 'pending') {
          var re = el('button', 'sf-orders__reorder', 'Reorder'); re.type = 'button';
          re.addEventListener('click', function () {
            req('/account/orders/' + o.id + '/reorder', { method: 'POST' }).then(function (r) {
              announce({ type: r.ok ? 'success' : 'error', text: r.ok ? 'Items added to your cart.' : 'Reorder failed.' });
              refreshCartIslands();
            });
          });
          acts.appendChild(re);

          var cancel = el('button', 'sf-orders__cancel', 'Cancel'); cancel.type = 'button';
          cancel.addEventListener('click', function () {
            req('/account/orders/' + o.id + '/cancel', { method: 'POST' }).then(function (r) {
              if (r.ok) { announce({ type: 'success', text: 'Order cancelled.' }); }
              else { announce({ type: 'error', text: (r.data && r.data.message) || 'This order can no longer be cancelled.' }); }
            });
          });
          acts.appendChild(cancel);
        }
        row.appendChild(acts);
        list.appendChild(row);
      });
      body.appendChild(list);
    });
  }

  function orderDetailBox(d) {
    var box = el('div', 'sf-order__detail');
    box.appendChild(el('p', 'sf-order__status', 'Status: ' + d.status + ' / ' + d.payment_status));
    (d.items || []).forEach(function (i) {
      box.appendChild(el('p', 'sf-order__item', i.quantity + ' × ' + i.name + ' — ' + i.line_total));
    });
    (d.tracking_numbers || []).forEach(function (t) {
      box.appendChild(el('p', 'sf-order__track', 'Tracking: ' + t));
    });
    (d.history || []).forEach(function (h) {
      box.appendChild(el('p', 'sf-order__hist', (h.at || '').slice(0, 16).replace('T', ' ') + '  ' + (h.from || 'created') + ' → ' + h.to));
    });
    return box;
  }

  function openOrderDetail(rowEl, id) {
    var sel = '[data-sf-detail-for="' + id + '"]';
    var existing = rowEl.parentNode.querySelector(sel);
    if (existing) { existing.remove(); return; }
    req('/account/orders/' + id).then(function (res) {
      if (!res.ok) { announce({ type: 'error', text: 'Could not load the order.' }); return; }
      var box = orderDetailBox(res.data.data);
      box.dataset.sfDetailFor = String(id);
      rowEl.parentNode.insertBefore(box, rowEl.nextSibling);
    });
  }

  function mountOrderDetailIsland(island) {
    var body = dashBody(island);
    if (!body) return;
    var id = island.dataset.sfOrderId || new URLSearchParams(window.location.search).get('order');
    if (!id) { dashError(body, 'Open an order from your orders list, or add ?order=NUMBER to this page URL.'); return; }
    req('/account/orders/' + encodeURIComponent(id)).then(function (res) {
      body.textContent = '';
      if (res.status === 401) { dashNeedAuth(body); return; }
      if (!res.ok) { dashError(body, 'Order not found or not yours.'); return; }
      body.appendChild(orderDetailBox(res.data.data));
    });
  }

  function mountAddresses(island) {
    var body = dashBody(island);
    if (!body) return;

    function refresh() {
      req('/account/addresses').then(function (res) {
        body.textContent = '';
        if (res.status === 401) { dashNeedAuth(body); return; }
        if (!res.ok) { dashError(body, 'Could not load addresses.'); return; }
        var rows = res.data.data || [];
        if (!rows.length) body.appendChild(el('p', 'sf-dashboard__empty', 'No addresses saved yet.'));

        rows.forEach(function (a) {
          var card = el('div', 'sf-address__card');
          card.appendChild(el('p', 'sf-address__name', a.name + (a.company ? ' · ' + a.company : '')));
          card.appendChild(el('p', null, [a.address1, a.address2, a.city, a.state, a.postal_code, a.country_code].filter(Boolean).join(', ')));
          if (a.phone) card.appendChild(el('p', null, a.phone));
          if (a.is_default_shipping) card.appendChild(el('span', 'sf-address__tag', 'Default shipping'));
          if (a.is_default_billing) card.appendChild(el('span', 'sf-address__tag', 'Default billing'));

          var ops = el('span', 'sf-address__ops');
          var defBtn = el('button', 'sf-address__default', 'Make default'); defBtn.type = 'button';
          defBtn.addEventListener('click', function () {
            req('/account/addresses/' + a.id, { method: 'PUT', body: { is_default_shipping: true } })
              .then(function (r) { if (r.ok) { refresh(); } else { announce({ type: 'error', text: 'Update failed.' }); } });
          });
          ops.appendChild(defBtn);

          var rm = el('button', 'sf-address__remove', 'Remove'); rm.type = 'button';
          rm.addEventListener('click', function () {
            req('/account/addresses/' + a.id, { method: 'DELETE' }).then(function (r) { if (r.ok) { refresh(); } });
          });
          ops.appendChild(rm);
          card.appendChild(ops);
          body.appendChild(card);
        });

        if (island.dataset.sfMode !== 'list') { renderAddForm(); }
      });
    }

    function renderAddForm() {
      var form = el('form', 'sf-address__form');
      var f1 = field('Full name', 'name', 'text', '', true);
      var f2 = field('Address', 'address1', 'text', '', true);
      var f3 = field('City', 'city', 'text', '', true);
      var f4 = field('Postal code', 'postal_code', 'text', '', true);
      var f5 = field('Country', 'country_code', 'text', '', true);
      f5.input.maxLength = 2;
      var err = el('p', 'sf-auth__error'); err.setAttribute('role', 'alert');
      var submit = el('button', 'sf-auth__submit', 'Add address'); submit.type = 'submit';
      [f1.label, f2.label, f3.label, f4.label, f5.label, err, submit].forEach(function (n) { form.appendChild(n); });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var data = {};
        new FormData(form).forEach(function (v, k) { data[k] = v; });
        req('/account/addresses', { method: 'POST', body: data }).then(function (res) {
          if (res.ok) { refresh(); } else { err.textContent = (res.data && res.data.message) || 'Could not save the address.'; }
        });
      });
      body.appendChild(form);
    }

    refresh();
  }

  function mountProfile(island) {
    var body = dashBody(island);
    if (!body) return;
    req('/account').then(function (res) {
      body.textContent = '';
      if (res.status === 401) { dashNeedAuth(body); return; }
      if (!res.ok) { dashError(body, 'Could not load your profile.'); return; }
      var a = res.data.data;
      var form = el('form', 'sf-profile__form');
      var fn = field('First name', 'first_name', 'text', a.first_name || '');
      var ln = field('Last name', 'last_name', 'text', a.last_name || '');
      var ph = field('Phone', 'phone', 'tel', a.phone || '');
      var err = el('p', 'sf-auth__error'); err.setAttribute('role', 'alert');
      var submit = el('button', 'sf-auth__submit', 'Save'); submit.type = 'submit';
      [fn.label, ln.label, ph.label, err, submit].forEach(function (n) { form.appendChild(n); });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var data = {};
        new FormData(form).forEach(function (v, k) { data[k] = v; });
        req('/account/profile', { method: 'PUT', body: data }).then(function (r) {
          if (r.ok) { announce({ type: 'success', text: 'Profile saved.' }); }
          else { err.textContent = (r.data && r.data.message) || 'Save failed.'; }
        });
      });
      body.appendChild(form);
    });
  }

  function mountSecurity(island) {
    var body = dashBody(island);
    if (!body) return;

    var pwForm = el('form', 'sf-security__password');
    var p1 = field('Current password', 'current_password', 'password', '', true);
    var p2 = field('New password', 'password', 'password', '', true);
    var p3 = field('Confirm new password', 'password_confirmation', 'password', '', true);
    var perr = el('p', 'sf-auth__error'); perr.setAttribute('role', 'alert');
    var psub = el('button', 'sf-auth__submit', 'Change password'); psub.type = 'submit';
    [p1.label, p2.label, p3.label, perr, psub].forEach(function (n) { pwForm.appendChild(n); });
    pwForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = {};
      new FormData(pwForm).forEach(function (v, k) { data[k] = v; });
      req('/account/password', { method: 'POST', body: data }).then(function (r) {
        if (r.ok) { announce({ type: 'success', text: 'Password changed. Other sessions were signed out.' }); pFormReset(); listSessions(); }
        else { perr.textContent = (r.data && r.data.message) || 'Change failed.'; }
      });
    });
    function pFormReset() { [p1, p2, p3].forEach(function (f) { f.input.value = ''; }); }
    body.appendChild(pwForm);

    var sessionsBox = el('div', 'sf-security__sessions');
    body.appendChild(sessionsBox);

    function listSessions() {
      req('/account/sessions').then(function (res) {
        sessionsBox.textContent = '';
        if (res.status === 401) { dashNeedAuth(body); return; }
        if (!res.ok) { return; }
        sessionsBox.appendChild(el('h4', null, 'Active sessions'));
        (res.data.data || []).forEach(function (s) {
          var row = el('p', 'sf-security__session');
          row.appendChild(el('span', null, (s.created_at || '').slice(0, 16).replace('T', ' ') + ' · ' + (s.device || 'device') + (s.current ? ' (this one)' : '')));
          if (!s.current) {
            var btn = el('button', 'sf-security__revoke', 'Sign out'); btn.type = 'button';
            btn.addEventListener('click', function () {
              req('/account/sessions/' + s.id, { method: 'DELETE' }).then(function (r) { if (r.ok) { listSessions(); } });
            });
            row.appendChild(btn);
          }
          sessionsBox.appendChild(row);
        });
      });
    }
    listSessions();
  }

  function mountVerify(island) {
    var body = dashBody(island);
    if (!body) return;
    var form = el('form', 'sf-verify__form');
    var em = field('Email', 'email', 'email', '', true);
    var tk = field('Code from your email', 'token', 'text', '', true);
    var msg = el('p', 'sf-auth__error'); msg.setAttribute('role', 'alert');
    var sub = el('button', 'sf-auth__submit', 'Verify'); sub.type = 'submit';
    [em.label, tk.label, msg, sub].forEach(function (n) { form.appendChild(n); });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var data = {};
      new FormData(form).forEach(function (v, k) { data[k] = v; });
      req('/auth/verify-email', { method: 'POST', body: data }).then(function (r) {
        if (r.ok) { msg.textContent = 'Email verified — your account is active.'; }
        else { msg.textContent = (r.data && r.data.message) || 'Verification failed.'; }
      });
    });
    body.appendChild(form);
  }

  /* ---------- announcer (aria-live) ---------- */

  function ensureAnnouncer() {
    var live = document.getElementById('sf-live');
    if (live) return;
    live = el('div', 'sf-sr-only');
    live.id = 'sf-live';
    live.setAttribute('role', 'status');
    live.setAttribute('aria-live', 'polite');
    document.body.appendChild(live);
  }

  /* ---------- boot ---------- */

  function boot() {
    ensureAnnouncer();
    document.addEventListener('sf:announce', function (e) {
      var live = document.getElementById('sf-live');
      if (live && e.detail) live.textContent = e.detail.text || '';
    });

    document.querySelectorAll('[data-sf-island="add_to_cart"]').forEach(mountAddToCart);
    document.querySelectorAll('[data-sf-island="cart_count"]').forEach(mountCount);
    document.querySelectorAll('[data-sf-island="cart"],[data-sf-island="cart_drawer"]').forEach(mountCart);
    document.querySelectorAll('[data-sf-island="login"],[data-sf-island="register"]').forEach(mountAuth);
    document.querySelectorAll('[data-sf-island="account"]').forEach(mountAccount);
    document.querySelectorAll('[data-sf-island="account_orders"]').forEach(mountOrders);
    document.querySelectorAll('[data-sf-island="order"]').forEach(mountOrderDetailIsland);
    document.querySelectorAll('[data-sf-island="addresses"]').forEach(mountAddresses);
    document.querySelectorAll('[data-sf-island="profile"]').forEach(mountProfile);
    document.querySelectorAll('[data-sf-island="security"]').forEach(mountSecurity);
    document.querySelectorAll('[data-sf-island="verify_email"]').forEach(mountVerify);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
