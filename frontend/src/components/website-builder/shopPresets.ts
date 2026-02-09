import { Preset } from './types'

// =============================================================================
// SHOP THEME CONFIGURATION (Default values)
// These can be overridden via website settings in the builder
// =============================================================================
export const SHOP_THEME = {
  // Primary brand color (buttons, accents, links)
  primary: '#00b207',
  primaryHover: '#009906',
  primaryLight: '#e6f8e7',
  
  // Secondary/accent color
  secondary: '#ffc107',
  
  // Text colors
  textDark: '#1a1a1a',
  textGray: '#666666',
  textLight: '#999999',
  
  // Background colors
  bgBody: '#f9f9f9',
  bgCard: '#ffffff',
  
  // Border colors
  border: '#e6e6e6',
  borderLight: '#f0f0f0',
  
  // Status colors
  success: '#00b207',
  error: '#ef4444',
  warning: '#f59e0b',
  
  // Badge colors
  badgeNew: '#ff6b6b',
  badgeSale: '#00b207',
}

// =============================================================================
// SHARED E-COMMERCE JAVASCRIPT MODULE
// Complete e-commerce functionality with proper routing and state management
// Reads theme settings from window.__SHOP_SETTINGS__ if available
// =============================================================================

const ECOMMERCE_SCRIPT = `
<script>
(function() {
  if (window.EcommerceStore && window.EcommerceStore._initialized) return;
  
  // Get shop settings from global variable or use defaults
  var shopSettings = window.__SHOP_SETTINGS__ || {};
  var defaultTheme = {
    primary: '${SHOP_THEME.primary}',
    primaryHover: '${SHOP_THEME.primaryHover}',
    primaryLight: '${SHOP_THEME.primaryLight}',
    textDark: '${SHOP_THEME.textDark}',
    textGray: '${SHOP_THEME.textGray}',
    success: '${SHOP_THEME.success}',
    error: '${SHOP_THEME.error}',
    badgeNew: '${SHOP_THEME.badgeNew}'
  };
  
  // Merge settings with defaults
  var theme = {
    primary: shopSettings.primaryColor || defaultTheme.primary,
    primaryHover: shopSettings.primaryHoverColor || defaultTheme.primaryHover,
    primaryLight: shopSettings.primaryLightColor || defaultTheme.primaryLight,
    textDark: defaultTheme.textDark,
    textGray: defaultTheme.textGray,
    success: shopSettings.primaryColor || defaultTheme.success,
    error: defaultTheme.error,
    badgeNew: defaultTheme.badgeNew
  };
  
  window.EcommerceStore = {
    _initialized: true,
    cart: JSON.parse(localStorage.getItem('ecommerce_cart') || '[]'),
    wishlist: JSON.parse(localStorage.getItem('ecommerce_wishlist') || '[]'),
    products: [],
    categories: [],
    currentProduct: null,
    filters: { category: null, minPrice: 0, maxPrice: 10000, search: '', sort: 'name' },
    settings: shopSettings,
    theme: theme,
    
    getApiBase: function() {
      if (window.__API_BASE_URL__) return window.__API_BASE_URL__;
      if (window.location.protocol === 'blob:') {
        var match = window.location.href.match(/^blob:(https?:\\/\\/[^/]+)/);
        if (match && match[1]) return match[1] + '/api/v1/website-public';
        if (document.referrer) {
          try { return new URL(document.referrer).origin + '/api/v1/website-public'; } catch (e) {}
        }
        return 'http://localhost:5173/api/v1/website-public';
      }
      try { if (window.parent !== window && window.parent.location) return window.parent.location.origin + '/api/v1/website-public'; } catch (e) {}
      return window.location.origin + '/api/v1/website-public';
    },
    
    get apiBase() { if (!this._apiBase) this._apiBase = this.getApiBase(); return this._apiBase; },

    // Cart Functions
    addToCart: function(productId, quantity, buttonEl) {
      quantity = quantity || 1;
      var self = this;
      
      // Find the button that was clicked for animation
      var btn = buttonEl || document.querySelector('[onclick*="addToCart(' + productId + ')"]');
      if (btn && !btn.classList.contains('adding')) {
        btn.classList.add('adding');
        setTimeout(function() { btn.classList.remove('adding'); }, 500);
      }
      
      var existing = this.cart.find(function(item) { return item.productId === productId; });
      if (existing) { existing.quantity += quantity; }
      else {
        var product = this.products.find(function(p) { return p.id === productId; }) || this.currentProduct;
        if (product) this.cart.push({ productId: productId, name: product.name, price: product.price, image: product.image, quantity: quantity });
      }
      this.saveCart();
      this.updateAllUI();
      
      // Animate cart badge
      var cartBadges = document.querySelectorAll('[data-cart-count]');
      cartBadges.forEach(function(badge) {
        badge.classList.add('pop');
        setTimeout(function() { badge.classList.remove('pop'); }, 300);
      });
      
      this.showNotification('Added to cart!', 'success');
    },
    
    removeFromCart: function(productId) {
      this.cart = this.cart.filter(function(item) { return item.productId !== productId; });
      this.saveCart();
      this.updateAllUI();
    },
    
    updateCartQuantity: function(productId, quantity) {
      if (quantity <= 0) { this.removeFromCart(productId); return; }
      var item = this.cart.find(function(item) { return item.productId === productId; });
      if (item) { item.quantity = quantity; this.saveCart(); this.updateAllUI(); }
    },
    
    getCartTotal: function() { return this.cart.reduce(function(sum, item) { return sum + (item.price * item.quantity); }, 0); },
    getCartCount: function() { return this.cart.reduce(function(sum, item) { return sum + item.quantity; }, 0); },
    saveCart: function() { localStorage.setItem('ecommerce_cart', JSON.stringify(this.cart)); window.dispatchEvent(new CustomEvent('cart-updated', { detail: { cart: this.cart } })); },
    clearCart: function() { this.cart = []; this.saveCart(); this.updateAllUI(); },

    // Wishlist Functions
    toggleWishlist: function(productId) {
      var index = this.wishlist.indexOf(productId);
      if (index > -1) { this.wishlist.splice(index, 1); this.showNotification('Removed from wishlist', 'info'); }
      else { this.wishlist.push(productId); this.showNotification('Added to wishlist!', 'success'); }
      this.saveWishlist();
      this.updateAllUI();
    },
    isInWishlist: function(productId) { return this.wishlist.indexOf(productId) > -1; },
    saveWishlist: function() { localStorage.setItem('ecommerce_wishlist', JSON.stringify(this.wishlist)); window.dispatchEvent(new CustomEvent('wishlist-updated', { detail: { wishlist: this.wishlist } })); },

    // API Functions
    fetchProducts: function(params) {
      var self = this;
      params = params || {};
      var queryParams = new URLSearchParams();
      if (params.category_id) queryParams.set('category_id', params.category_id);
      if (params.min_price) queryParams.set('min_price', params.min_price);
      if (params.max_price) queryParams.set('max_price', params.max_price);
      if (params.search) queryParams.set('search', params.search);
      if (params.per_page) queryParams.set('per_page', params.per_page);
      if (params.is_new_arrival) queryParams.set('is_new_arrival', '1');
      
      return fetch(this.apiBase + '/products?' + queryParams.toString())
        .then(function(response) { return response.json(); })
        .then(function(data) { self.products = data.data || []; return self.products; })
        .catch(function(error) { console.error('Failed to fetch products:', error); return []; });
    },
    
    fetchProduct: function(productId) {
      var self = this;
      return fetch(this.apiBase + '/products/' + productId)
        .then(function(response) { return response.json(); })
        .then(function(product) { self.currentProduct = product; return product; })
        .catch(function(error) { console.error('Failed to fetch product:', error); return null; });
    },
    
    fetchCategories: function() {
      var self = this;
      return fetch(this.apiBase + '/categories')
        .then(function(response) { return response.json(); })
        .then(function(categories) { self.categories = categories; return categories; })
        .catch(function(error) { console.error('Failed to fetch categories:', error); return []; });
    },

    // Filter Functions
    applyFilters: function() {
      var self = this;
      var params = { per_page: 100 };
      if (this.filters.category) params.category_id = this.filters.category;
      if (this.filters.minPrice > 0) params.min_price = this.filters.minPrice;
      if (this.filters.maxPrice < 10000) params.max_price = this.filters.maxPrice;
      if (this.filters.search) params.search = this.filters.search;
      
      this.fetchProducts(params).then(function() {
        self.allProducts = self.products.slice();
        if (self.filters.sort === 'price_asc') self.allProducts.sort(function(a, b) { return a.price - b.price; });
        else if (self.filters.sort === 'price_desc') self.allProducts.sort(function(a, b) { return b.price - a.price; });
        else if (self.filters.sort === 'newest') self.allProducts.sort(function(a, b) { return new Date(b.created_at) - new Date(a.created_at); });
        else self.allProducts.sort(function(a, b) { return a.name.localeCompare(b.name); });
        self.currentPage = 1;
        self.renderProductGrid();
      });
    },
    
    setFilter: function(key, value) { this.filters[key] = value; this.applyFilters(); },

    // Navigation Functions
    getBasePath: function() {
      // For public sites, we need to find the base path
      var path = window.location.pathname;
      
      // Handle /sites/{slug} pattern
      if (path.includes('/sites/')) {
        var match = path.match(/(\\/sites\\/[^\\/]+)/);
        return match ? match[1] : '';
      }
      
      // Handle custom domain or subdomain - path might be /shop, /cart, /product etc
      // In this case, the base is empty (root)
      // But if we're on a page like /shop or /product, we should stay at root
      var knownPages = ['/shop', '/cart', '/checkout', '/product', '/wishlist'];
      for (var i = 0; i < knownPages.length; i++) {
        if (path === knownPages[i] || path.startsWith(knownPages[i] + '?')) {
          return '';
        }
      }
      
      // If path has segments, try to extract base (e.g., /mysite/shop -> /mysite)
      var segments = path.split('/').filter(function(s) { return s.length > 0; });
      if (segments.length > 1) {
        // Check if last segment is a known page
        var lastSegment = '/' + segments[segments.length - 1];
        for (var j = 0; j < knownPages.length; j++) {
          if (lastSegment === knownPages[j] || segments[segments.length - 1].startsWith(knownPages[j].substring(1))) {
            // Return everything except the last segment
            return '/' + segments.slice(0, -1).join('/');
          }
        }
      }
      
      return '';
    },
    
    navigateToProduct: function(productId) {
      // For blob URLs (preview), show modal instead
      if (window.location.protocol === 'blob:') {
        this.showProductModal(productId);
        return;
      }
      var basePath = this.getBasePath();
      var targetUrl = basePath + '/product?id=' + productId;
      console.log('[EcommerceStore] Navigating to product:', targetUrl, 'from:', window.location.href);
      window.location.href = targetUrl;
    },
    
    navigateToCart: function() {
      if (window.location.protocol === 'blob:') { this.showNotification('Cart: ' + this.getCartCount() + ' items, Total: $' + this.getCartTotal().toFixed(2), 'info'); return; }
      var basePath = this.getBasePath();
      // Try navigating within the iframe first for published sites
      window.location.href = basePath + '/cart';
    },
    
    navigateToWishlist: function() {
      if (window.location.protocol === 'blob:') { this.showNotification('Wishlist: ' + this.wishlist.length + ' items', 'info'); return; }
      var basePath = this.getBasePath();
      window.location.href = basePath + '/wishlist';
    },
    
    navigateToCheckout: function() {
      if (this.cart.length === 0) { this.showNotification('Your cart is empty', 'error'); return; }
      if (window.location.protocol === 'blob:') { this.showNotification('Checkout Total: $' + this.getCartTotal().toFixed(2), 'info'); return; }
      // Navigate to checkout - break out of iframe if needed
      try {
        if (window.top && window.top !== window) {
          window.top.location.href = '/web-shop/checkout';
        } else if (window.parent && window.parent !== window) {
          window.parent.location.href = '/web-shop/checkout';
        } else {
          window.location.href = '/web-shop/checkout';
        }
      } catch(e) {
        // Cross-origin iframe - use postMessage or direct navigation  
        window.location.href = '/web-shop/checkout';
      }
    },
    
    navigateToShop: function() {
      if (window.location.protocol === 'blob:') { this.showNotification('Shop page', 'info'); return; }
      var basePath = this.getBasePath();
      window.location.href = basePath + '/shop';
    },

    // Product Modal for Preview Mode
    showProductModal: function(productId) {
      var self = this;
      var t = this.theme;
      this.fetchProduct(productId).then(function(product) {
        if (!product) { self.showNotification('Product not found', 'error'); return; }
        var inStock = product.in_stock !== false && product.stock_quantity > 0;
        var modal = document.createElement('div');
        modal.id = 'product-modal-overlay';
        modal.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.8);z-index:10000;display:flex;align-items:center;justify-content:center;padding:20px;';
        modal.onclick = function(e) { if (e.target === modal) modal.remove(); };
        
        modal.innerHTML = '<div style="background:white;border-radius:16px;max-width:800px;width:100%;max-height:90vh;overflow:auto;display:grid;grid-template-columns:1fr 1fr;gap:30px;padding:30px;position:relative;">' +
          '<button onclick="this.closest(\\'#product-modal-overlay\\').remove()" style="position:absolute;top:15px;right:15px;background:none;border:none;font-size:24px;cursor:pointer;color:#666;">&times;</button>' +
          '<div style="display:flex;align-items:center;justify-content:center;background:#f9f9f9;border-radius:12px;padding:20px;">' +
            '<img src="' + (product.image || 'https://placehold.co/400x400?text=No+Image') + '" alt="' + product.name + '" style="max-width:100%;max-height:400px;object-fit:contain;">' +
          '</div>' +
          '<div style="display:flex;flex-direction:column;gap:15px;">' +
            '<span style="background:' + (inStock ? t.primaryLight : '#fee') + ';color:' + (inStock ? t.primary : t.error) + ';padding:6px 14px;border-radius:6px;font-size:0.85rem;font-weight:500;width:fit-content;">' + (inStock ? 'In Stock' : 'Out of Stock') + '</span>' +
            '<h2 style="font-size:1.8rem;font-weight:600;color:' + t.textDark + ';margin:0;">' + product.name + '</h2>' +
            '<p style="font-size:2rem;font-weight:700;color:' + t.primary + ';margin:0;">$' + product.price.toFixed(2) + '</p>' +
            '<p style="color:' + t.textGray + ';line-height:1.6;">' + (product.description || 'No description available.') + '</p>' +
            '<div style="display:flex;gap:15px;margin-top:auto;">' +
              '<button class="add-to-cart-btn" onclick="EcommerceStore.addToCart(' + product.id + ', 1, this)" style="flex:1;background:' + t.primary + ';color:white;border:none;padding:14px 24px;border-radius:40px;font-weight:600;cursor:pointer;' + (!inStock ? 'opacity:0.5;cursor:not-allowed;' : '') + '"' + (!inStock ? ' disabled' : '') + '>Add to Cart</button>' +
              '<button onclick="EcommerceStore.toggleWishlist(' + product.id + ')" style="width:50px;height:50px;border-radius:50%;border:1px solid ' + t.primaryLight + ';background:' + t.primaryLight + ';cursor:pointer;color:' + t.primary + ';">' +
                '<svg width="20" height="20" viewBox="0 0 24 24" fill="' + (self.isInWishlist(product.id) ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>' +
              '</button>' +
            '</div>' +
          '</div>' +
        '</div>';
        document.body.appendChild(modal);
      });
    },

    // UI Update Functions
    updateAllUI: function() {
      this.updateCartUI();
      this.updateWishlistUI();
      this.renderCartPage();
      this.renderWishlistPage();
      this.renderMiniCart();
      this.renderCheckoutItems();
    },
    
    updateCartUI: function() {
      var count = this.getCartCount();
      var total = this.getCartTotal();
      document.querySelectorAll('[data-cart-count]').forEach(function(el) {
        el.textContent = count;
        if (el.classList.contains('cart-badge') || el.closest('.cart-badge')) el.style.display = count > 0 ? 'flex' : 'none';
      });
      document.querySelectorAll('[data-cart-total]').forEach(function(el) { el.textContent = '$' + total.toFixed(2); });
      document.querySelectorAll('[data-cart-subtotal]').forEach(function(el) { el.textContent = '$' + total.toFixed(2); });
      document.querySelectorAll('[data-cart-total-final]').forEach(function(el) { el.textContent = '$' + total.toFixed(2); });
    },
    
    updateWishlistUI: function() {
      var self = this;
      var t = this.theme;
      var count = this.wishlist.length;
      document.querySelectorAll('[data-wishlist-count]').forEach(function(el) {
        el.textContent = count;
        if (el.classList.contains('wishlist-badge') || el.closest('.wishlist-badge')) el.style.display = count > 0 ? 'flex' : 'none';
      });
      document.querySelectorAll('[data-wishlist-btn]').forEach(function(btn) {
        var productId = parseInt(btn.dataset.productId);
        var isInWishlist = self.isInWishlist(productId);
        btn.classList.toggle('active', isInWishlist);
        // Update button styling based on wishlist state
        btn.style.backgroundColor = isInWishlist ? t.primary : 'white';
        btn.style.borderColor = t.primary;
        btn.style.color = isInWishlist ? 'white' : t.primary;
        var svg = btn.querySelector('svg');
        if (svg) {
          svg.setAttribute('fill', isInWishlist ? 'currentColor' : 'none');
          svg.setAttribute('stroke', 'currentColor');
        }
      });
    },

    // Pagination state
    currentPage: 1,
    totalPages: 1,
    productsPerPage: shopSettings.productsPerPage || 28,
    allProducts: [],

    // Render Functions
    renderProductGrid: function() {
      var self = this;
      var grid = document.querySelector('[data-product-grid]');
      if (!grid) return;
      
      // Calculate pagination
      var startIndex = (this.currentPage - 1) * this.productsPerPage;
      var endIndex = startIndex + this.productsPerPage;
      var pageProducts = this.allProducts.slice(startIndex, endIndex);
      this.totalPages = Math.ceil(this.allProducts.length / this.productsPerPage);
      
      if (this.allProducts.length === 0) {
        grid.innerHTML = '<div style="text-align:center;padding:60px 20px;color:' + this.theme.textGray + ';grid-column:1/-1;">No products found</div>';
        this.renderPagination();
        return;
      }
      grid.innerHTML = pageProducts.map(function(product) { return self.renderProductCard(product); }).join('');
      this.updateWishlistUI();
      this.renderPagination();
    },
    
    renderPagination: function() {
      var self = this;
      var container = document.querySelector('[data-pagination]');
      if (!container || this.totalPages <= 1) {
        if (container) container.innerHTML = '';
        return;
      }
      
      var pages = [];
      for (var i = 1; i <= this.totalPages; i++) {
        var isActive = i === this.currentPage;
        pages.push('<button onclick="EcommerceStore.goToPage(' + i + ')" style="width:40px;height:40px;border-radius:8px;border:1px solid ' + (isActive ? this.theme.primary : '#e6e6e6') + ';background:' + (isActive ? this.theme.primary : 'white') + ';color:' + (isActive ? 'white' : '#1a1a1a') + ';cursor:pointer;font-weight:' + (isActive ? '600' : '400') + ';">' + i + '</button>');
      }
      
      container.innerHTML = '<div style="display:flex;justify-content:center;align-items:center;gap:8px;margin-top:40px;">' +
        '<button onclick="EcommerceStore.goToPage(' + Math.max(1, this.currentPage - 1) + ')" style="width:40px;height:40px;border-radius:8px;border:1px solid #e6e6e6;background:white;cursor:pointer;" ' + (this.currentPage === 1 ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '') + '>&lt;</button>' +
        pages.join('') +
        '<button onclick="EcommerceStore.goToPage(' + Math.min(this.totalPages, this.currentPage + 1) + ')" style="width:40px;height:40px;border-radius:8px;border:1px solid #e6e6e6;background:white;cursor:pointer;" ' + (this.currentPage === this.totalPages ? 'disabled style="opacity:0.5;cursor:not-allowed;"' : '') + '>&gt;</button>' +
      '</div>';
    },
    
    goToPage: function(page) {
      this.currentPage = page;
      this.renderProductGrid();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    
    renderProductCard: function(product) {
      var t = this.theme;
      var imageUrl = product.image || 'https://placehold.co/300x200?text=No+Image';
      var isNew = product.is_new_arrival;
      var inStock = product.in_stock !== false && product.stock_quantity > 0;
      var isInWishlist = this.isInWishlist(product.id);
      
      return '<div class="product-card" data-product-id="' + product.id + '" style="background:white;border:1px solid #e6e6e6;border-radius:12px;padding:15px;position:relative;transition:all 0.3s ease;cursor:pointer;" onclick="EcommerceStore.navigateToProduct(' + product.id + ')">' +
        (isNew ? '<span style="position:absolute;top:15px;left:15px;background:' + t.badgeNew + ';color:white;font-size:0.7rem;padding:4px 10px;border-radius:4px;font-weight:600;z-index:2;">NEW</span>' : '') +
        '<button class="heart-icon" data-wishlist-btn data-product-id="' + product.id + '" onclick="event.stopPropagation();EcommerceStore.toggleWishlist(' + product.id + ')" style="position:absolute;top:15px;right:15px;width:35px;height:35px;border-radius:50%;background:' + (isInWishlist ? t.primary : 'white') + ';border:1px solid ' + t.primary + ';display:flex;align-items:center;justify-content:center;cursor:pointer;z-index:2;color:' + (isInWishlist ? 'white' : t.primary) + ';">' +
          '<svg width="18" height="18" viewBox="0 0 24 24" fill="' + (isInWishlist ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>' +
        '</button>' +
        '<div style="height:180px;display:flex;align-items:center;justify-content:center;margin-bottom:12px;">' +
          '<img src="' + imageUrl + '" alt="' + product.name + '" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px;" onerror="this.src=\\'https://placehold.co/300x200?text=No+Image\\'">' +
        '</div>' +
        '<div style="display:flex;flex-direction:column;gap:5px;">' +
          '<div style="font-size:0.75rem;color:' + t.textGray + ';">' + (product.category ? product.category.name : 'Uncategorized') + '</div>' +
          '<div style="font-size:0.95rem;font-weight:600;color:' + t.textDark + ';">' + product.name + '</div>' +
          '<div style="font-size:0.75rem;padding:2px 8px;border-radius:4px;display:inline-block;width:fit-content;background:' + (inStock ? t.primaryLight : '#fee') + ';color:' + (inStock ? t.primary : t.error) + ';">' + (inStock ? 'In Stock' : 'Out of Stock') + '</div>' +
          '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;">' +
            '<div style="font-weight:700;color:' + t.textDark + ';font-size:1.1rem;">$' + product.price.toFixed(2) + '</div>' +
            '<button class="add-to-cart-btn" onclick="event.stopPropagation();EcommerceStore.addToCart(' + product.id + ', 1, this)" style="background-color:' + t.primaryLight + ';color:' + t.primary + ';border:none;padding:8px 16px;border-radius:25px;font-weight:600;font-size:0.8rem;cursor:pointer;display:flex;align-items:center;gap:5px;' + (!inStock ? 'opacity:0.5;cursor:not-allowed;' : '') + '"' + (!inStock ? ' disabled' : '') + '>' +
              '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>' +
              'Add' +
            '</button>' +
          '</div>' +
        '</div>' +
      '</div>';
    },


    renderCartPage: function() {
      var self = this;
      var t = this.theme;
      var container = document.querySelector('[data-cart-items]');
      if (!container) return;
      
      if (this.cart.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:60px 20px;"><svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#e6e6e6" stroke-width="1" style="margin-bottom:20px;"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg><h3 style="color:' + t.textDark + ';margin-bottom:10px;">Your cart is empty</h3><p style="color:' + t.textGray + ';margin-bottom:20px;">Add some products to get started</p><button onclick="EcommerceStore.navigateToShop()" style="background:' + t.primary + ';color:white;border:none;padding:12px 30px;border-radius:25px;cursor:pointer;font-weight:600;">Continue Shopping</button></div>';
        return;
      }
      
      container.innerHTML = this.cart.map(function(item) {
        return '<div style="display:flex;gap:20px;padding:20px;background:white;border-radius:12px;margin-bottom:15px;border:1px solid #f0f0f0;">' +
          '<img src="' + (item.image || 'https://placehold.co/100x100?text=No+Image') + '" alt="' + item.name + '" style="width:100px;height:100px;object-fit:cover;border-radius:8px;">' +
          '<div style="flex:1;display:flex;flex-direction:column;justify-content:space-between;">' +
            '<div>' +
              '<h4 style="margin:0 0 5px 0;color:' + t.textDark + ';font-size:1rem;">' + item.name + '</h4>' +
              '<p style="margin:0;color:' + t.primary + ';font-weight:700;font-size:1.1rem;">$' + item.price.toFixed(2) + '</p>' +
            '</div>' +
            '<div style="display:flex;align-items:center;gap:15px;">' +
              '<div style="display:flex;align-items:center;border:1px solid #e6e6e6;border-radius:8px;overflow:hidden;">' +
                '<button onclick="EcommerceStore.updateCartQuantity(' + item.productId + ',' + (item.quantity - 1) + ')" style="width:35px;height:35px;border:none;background:#f9f9f9;cursor:pointer;font-size:1.2rem;">-</button>' +
                '<span style="width:40px;text-align:center;font-weight:600;">' + item.quantity + '</span>' +
                '<button onclick="EcommerceStore.updateCartQuantity(' + item.productId + ',' + (item.quantity + 1) + ')" style="width:35px;height:35px;border:none;background:#f9f9f9;cursor:pointer;font-size:1.2rem;">+</button>' +
              '</div>' +
              '<button onclick="EcommerceStore.removeFromCart(' + item.productId + ')" style="color:' + t.error + ';background:none;border:none;cursor:pointer;padding:8px;">' +
                '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>' +
              '</button>' +
            '</div>' +
          '</div>' +
          '<div style="text-align:right;min-width:80px;">' +
            '<p style="margin:0;font-weight:700;color:' + t.textDark + ';font-size:1.1rem;">$' + (item.price * item.quantity).toFixed(2) + '</p>' +
          '</div>' +
        '</div>';
      }).join('');
    },

    renderWishlistPage: function() {
      var self = this;
      var t = this.theme;
      var container = document.querySelector('[data-wishlist-items]');
      if (!container) return;
      
      if (this.wishlist.length === 0) {
        container.innerHTML = '<div style="text-align:center;padding:60px 20px;"><svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="#e6e6e6" stroke-width="1" style="margin-bottom:20px;"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg><h3 style="color:' + t.textDark + ';margin-bottom:10px;">Your wishlist is empty</h3><p style="color:' + t.textGray + ';margin-bottom:20px;">Save items you love for later</p><button onclick="EcommerceStore.navigateToShop()" style="background:' + t.primary + ';color:white;border:none;padding:12px 30px;border-radius:25px;cursor:pointer;font-weight:600;">Browse Products</button></div>';
        return;
      }
      
      var wishlistProducts = this.allProducts.filter(function(p) { return self.wishlist.indexOf(p.id) > -1; });
      if (wishlistProducts.length === 0 && this.wishlist.length > 0) {
        container.innerHTML = '<div style="text-align:center;padding:40px;color:' + t.textGray + ';">Loading wishlist items...</div>';
        this.fetchProducts({ per_page: 100 }).then(function() { self.renderWishlistPage(); });
        return;
      }
      
      container.innerHTML = '<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:20px;">' +
        wishlistProducts.map(function(product) { return self.renderProductCard(product); }).join('') +
      '</div>';
    },

    renderMiniCart: function() {
      var self = this;
      var t = this.theme;
      var container = document.querySelector('[data-mini-cart]');
      if (!container) return;
      
      if (this.cart.length === 0) {
        container.innerHTML = '<div style="padding:30px;text-align:center;color:' + t.textGray + ';">Your cart is empty</div>';
        return;
      }
      
      container.innerHTML = this.cart.slice(0, 3).map(function(item) {
        return '<div style="display:flex;gap:12px;padding:12px 0;border-bottom:1px solid #f0f0f0;">' +
          '<img src="' + (item.image || 'https://placehold.co/60x60?text=No+Image') + '" alt="' + item.name + '" style="width:60px;height:60px;object-fit:cover;border-radius:8px;">' +
          '<div style="flex:1;">' +
            '<p style="margin:0 0 4px 0;font-size:0.9rem;font-weight:500;color:' + t.textDark + ';">' + item.name + '</p>' +
            '<p style="margin:0;font-size:0.85rem;color:' + t.textGray + ';">' + item.quantity + ' x $' + item.price.toFixed(2) + '</p>' +
          '</div>' +
          '<button onclick="EcommerceStore.removeFromCart(' + item.productId + ')" style="background:none;border:none;color:' + t.textGray + ';cursor:pointer;padding:4px;">&times;</button>' +
        '</div>';
      }).join('') +
      (this.cart.length > 3 ? '<p style="text-align:center;color:' + t.textGray + ';font-size:0.85rem;padding:10px 0;">+ ' + (this.cart.length - 3) + ' more items</p>' : '') +
      '<div style="padding:15px 0;border-top:1px solid #e6e6e6;margin-top:10px;">' +
        '<div style="display:flex;justify-content:space-between;margin-bottom:15px;">' +
          '<span style="font-weight:600;color:' + t.textDark + ';">Total:</span>' +
          '<span style="font-weight:700;color:' + t.primary + ';">$' + this.getCartTotal().toFixed(2) + '</span>' +
        '</div>' +
        '<button onclick="EcommerceStore.navigateToCart()" style="width:100%;background:' + t.primary + ';color:white;border:none;padding:12px;border-radius:8px;cursor:pointer;font-weight:600;">View Cart</button>' +
      '</div>';
    },

    renderCheckoutItems: function() {
      var self = this;
      var t = this.theme;
      var container = document.querySelector('[data-checkout-items]');
      if (!container) return;
      
      container.innerHTML = this.cart.map(function(item) {
        return '<div style="display:flex;gap:15px;padding:15px 0;border-bottom:1px solid #f0f0f0;">' +
          '<div style="position:relative;">' +
            '<img src="' + (item.image || 'https://placehold.co/70x70?text=No+Image') + '" alt="' + item.name + '" style="width:70px;height:70px;object-fit:cover;border-radius:8px;">' +
            '<span style="position:absolute;top:-8px;right:-8px;background:' + t.primary + ';color:white;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.75rem;font-weight:600;">' + item.quantity + '</span>' +
          '</div>' +
          '<div style="flex:1;">' +
            '<p style="margin:0 0 4px 0;font-weight:500;color:' + t.textDark + ';">' + item.name + '</p>' +
            '<p style="margin:0;font-size:0.85rem;color:' + t.textGray + ';">$' + item.price.toFixed(2) + ' each</p>' +
          '</div>' +
          '<div style="text-align:right;">' +
            '<p style="margin:0;font-weight:600;color:' + t.textDark + ';">$' + (item.price * item.quantity).toFixed(2) + '</p>' +
          '</div>' +
        '</div>';
      }).join('');
    },

    renderCategoryFilter: function() {
      var self = this;
      var t = this.theme;
      var container = document.querySelector('[data-category-filter]');
      if (!container) return;
      
      this.fetchCategories().then(function(categories) {
        // Use fetched categories or fallback to demo categories
        var cats = (categories && categories.length > 0) ? categories : [
          { id: 1, name: 'Electronics' },
          { id: 2, name: 'Accessories' },
          { id: 3, name: 'Clothing' },
          { id: 4, name: 'Footwear' },
          { id: 5, name: 'Home' },
          { id: 6, name: 'Fitness' },
          { id: 7, name: 'Bags' }
        ];
        var allActive = self.filters.category === null;
        container.innerHTML = '<button onclick="EcommerceStore.setFilter(\\'category\\',null)" style="text-align: left; padding: 12px 16px; border: none; background:' + (allActive ? 'linear-gradient(135deg, ' + t.primary + ' 0%, ' + t.primaryHover + ' 100%)' : t.bgBody) + '; color:' + (allActive ? 'white' : t.textDark) + '; border-radius: 10px; cursor: pointer; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 10px; transition: all 0.2s ease;">' +
          '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>' +
          'All Categories' +
        '</button>' +
          cats.map(function(cat) {
            var isActive = self.filters.category === cat.id;
            return '<button onclick="EcommerceStore.setFilter(\\'category\\',' + cat.id + ')" style="text-align: left; padding: 12px 16px; border: none; background:' + (isActive ? 'linear-gradient(135deg, ' + t.primary + ' 0%, ' + t.primaryHover + ' 100%)' : t.bgBody) + '; color:' + (isActive ? 'white' : t.textDark) + '; border-radius: 10px; cursor: pointer; font-size: 0.9rem; font-weight: 500; transition: all 0.2s ease;">' + cat.name + '</button>';
          }).join('');
      }).catch(function() {
        // On error, use demo categories
        var demoCategories = [
          { id: 1, name: 'Electronics' },
          { id: 2, name: 'Accessories' },
          { id: 3, name: 'Clothing' },
          { id: 4, name: 'Footwear' },
          { id: 5, name: 'Home' },
          { id: 6, name: 'Fitness' },
          { id: 7, name: 'Bags' }
        ];
        var allActive = self.filters.category === null;
        container.innerHTML = '<button onclick="EcommerceStore.setFilter(\\'category\\',null)" style="text-align: left; padding: 12px 16px; border: none; background:' + (allActive ? 'linear-gradient(135deg, ' + t.primary + ' 0%, ' + t.primaryHover + ' 100%)' : t.bgBody) + '; color:' + (allActive ? 'white' : t.textDark) + '; border-radius: 10px; cursor: pointer; font-size: 0.9rem; font-weight: 500; display: flex; align-items: center; gap: 10px; transition: all 0.2s ease;">' +
          '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>' +
          'All Categories' +
        '</button>' +
          demoCategories.map(function(cat) {
            var isActive = self.filters.category === cat.id;
            return '<button onclick="EcommerceStore.setFilter(\\'category\\',' + cat.id + ')" style="text-align: left; padding: 12px 16px; border: none; background:' + (isActive ? 'linear-gradient(135deg, ' + t.primary + ' 0%, ' + t.primaryHover + ' 100%)' : t.bgBody) + '; color:' + (isActive ? 'white' : t.textDark) + '; border-radius: 10px; cursor: pointer; font-size: 0.9rem; font-weight: 500; transition: all 0.2s ease;">' + cat.name + '</button>';
          }).join('');
      });
    },

    renderProductDetail: function() {
      var self = this;
      var t = this.theme;
      var container = document.querySelector('[data-product-detail]');
      if (!container) return;
      
      var urlParams = new URLSearchParams(window.location.search);
      var productId = urlParams.get('id');
      if (!productId) {
        container.innerHTML = '<div style="text-align:center;padding:60px;color:' + t.textGray + ';">Product not found</div>';
        return;
      }
      
      this.fetchProduct(productId).then(function(product) {
        if (!product) {
          container.innerHTML = '<div style="text-align:center;padding:60px;color:' + t.textGray + ';">Product not found</div>';
          return;
        }
        
        var inStock = product.in_stock !== false && product.stock_quantity > 0;
        var isInWishlist = self.isInWishlist(product.id);
        
        container.innerHTML = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:50px;padding:40px 0;">' +
          '<div style="background:#f9f9f9;border-radius:16px;padding:30px;display:flex;align-items:center;justify-content:center;">' +
            '<img src="' + (product.image || 'https://placehold.co/500x500?text=No+Image') + '" alt="' + product.name + '" style="max-width:100%;max-height:500px;object-fit:contain;border-radius:12px;">' +
          '</div>' +
          '<div style="display:flex;flex-direction:column;gap:20px;">' +
            '<span style="background:' + (inStock ? t.primaryLight : '#fee') + ';color:' + (inStock ? t.primary : t.error) + ';padding:8px 16px;border-radius:8px;font-size:0.9rem;font-weight:500;width:fit-content;">' + (inStock ? 'In Stock (' + product.stock_quantity + ' available)' : 'Out of Stock') + '</span>' +
            '<h1 style="margin:0;font-size:2.5rem;font-weight:700;color:' + t.textDark + ';">' + product.name + '</h1>' +
            '<p style="margin:0;font-size:2.5rem;font-weight:700;color:' + t.primary + ';">$' + product.price.toFixed(2) + '</p>' +
            (product.category ? '<p style="margin:0;color:' + t.textGray + ';font-size:0.9rem;">Category: <span style="color:' + t.primary + ';">' + product.category.name + '</span></p>' : '') +
            '<p style="margin:0;color:' + t.textGray + ';line-height:1.8;font-size:1.1rem;">' + (product.description || 'No description available.') + '</p>' +
            '<div style="display:flex;gap:15px;margin-top:20px;">' +
              '<button class="add-to-cart-btn" onclick="EcommerceStore.addToCart(' + product.id + ', 1, this)" style="flex:1;background:' + t.primary + ';color:white;border:none;padding:18px 30px;border-radius:50px;font-weight:600;font-size:1.1rem;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;' + (!inStock ? 'opacity:0.5;cursor:not-allowed;' : '') + '"' + (!inStock ? ' disabled' : '') + '>' +
                '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>' +
                'Add to Cart' +
              '</button>' +
              '<button onclick="EcommerceStore.toggleWishlist(' + product.id + ')" data-wishlist-btn data-product-id="' + product.id + '" style="width:60px;height:60px;border-radius:50%;border:2px solid ' + t.primary + ';background:' + (isInWishlist ? t.primary : 'white') + ';cursor:pointer;color:' + (isInWishlist ? 'white' : t.primary) + ';display:flex;align-items:center;justify-content:center;">' +
                '<svg width="24" height="24" viewBox="0 0 24 24" fill="' + (isInWishlist ? 'currentColor' : 'none') + '" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>' +
              '</button>' +
            '</div>' +
          '</div>' +
        '</div>' +
        '<div data-related-products style="margin-top:60px;"><div style="text-align:center;padding:40px;color:' + t.textGray + ';">Loading related products...</div></div>';
        
        self.updateWishlistUI();
        
        // Load related products
        self.fetchProducts({ per_page: 8, category_id: product.category ? product.category.id : null }).then(function() {
          var relatedProducts = self.products.filter(function(p) { return p.id !== product.id; }).slice(0, 4);
          var relatedContainer = document.querySelector('[data-related-products]');
          if (relatedContainer && relatedProducts.length > 0) {
            relatedContainer.innerHTML = '<div style="border-top:1px solid #e6e6e6;padding-top:40px;">' +
              '<h2 style="margin:0 0 30px 0;font-size:1.8rem;font-weight:700;color:' + t.textDark + ';text-align:center;">You May Also Like</h2>' +
              '<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:25px;">' +
                relatedProducts.map(function(p) { return self.renderProductCard(p); }).join('') +
              '</div>' +
            '</div>';
            self.updateWishlistUI();
          } else if (relatedContainer) {
            relatedContainer.innerHTML = '';
          }
        });
      });
    },

    showNotification: function(message, type) {
      var t = this.theme;
      type = type || 'info';
      var colors = { success: t.success, error: t.error, warning: '#f59e0b', info: t.primary };
      var notification = document.createElement('div');
      notification.style.cssText = 'position:fixed;bottom:20px;right:20px;background:' + (colors[type] || colors.info) + ';color:white;padding:15px 25px;border-radius:10px;font-weight:500;z-index:10001;animation:slideIn 0.3s ease;box-shadow:0 4px 15px rgba(0,0,0,0.2);';
      notification.textContent = message;
      document.body.appendChild(notification);
      setTimeout(function() { notification.style.animation = 'slideOut 0.3s ease'; setTimeout(function() { notification.remove(); }, 300); }, 3000);
    },

    setupPriceSlider: function() {
      var self = this;
      var minSlider = document.querySelector('[data-price-min]');
      var maxSlider = document.querySelector('[data-price-max]');
      var minDisplay = document.querySelector('[data-price-min-display]');
      var maxDisplay = document.querySelector('[data-price-max-display]');
      
      if (minSlider) {
        minSlider.addEventListener('input', function() {
          self.filters.minPrice = parseInt(this.value);
          if (minDisplay) minDisplay.textContent = '$' + this.value;
        });
        minSlider.addEventListener('change', function() { self.applyFilters(); });
      }
      if (maxSlider) {
        maxSlider.addEventListener('input', function() {
          self.filters.maxPrice = parseInt(this.value);
          if (maxDisplay) maxDisplay.textContent = '$' + this.value;
        });
        maxSlider.addEventListener('change', function() { self.applyFilters(); });
      }
    },

    init: function() {
      var self = this;
      this.updateAllUI();
      
      
      // Auto-load products if grid exists
      var grid = document.querySelector('[data-product-grid]');
      if (grid) {
        // Show loading spinner while fetching products
        grid.innerHTML = '<div style="text-align:center;padding:60px 20px;color:' + self.theme.textGray + ';grid-column:1/-1;"><div style="display:inline-block;width:40px;height:40px;border:3px solid #e5e7eb;border-top-color:' + self.theme.primary + ';border-radius:50%;animation:spin 0.8s linear infinite;"></div><p style="margin-top:16px;font-size:14px;">Loading products...</p></div>';
        // Add spin animation if not already present
        if (!document.querySelector('#ecs-spin-style')) {
          var spinStyle = document.createElement('style');
          spinStyle.id = 'ecs-spin-style';
          spinStyle.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
          document.head.appendChild(spinStyle);
        }
        
        // Fetch real products from the API
        this.fetchProducts({ per_page: 100 }).then(function(fetchedProducts) { 
          if (fetchedProducts && fetchedProducts.length > 0) {
            self.allProducts = fetchedProducts.slice();
          } else {
            self.allProducts = [];
          }
          self.renderProductGrid(); 
        }).catch(function(err) {
          console.log('Could not fetch products:', err);
          self.allProducts = [];
          self.renderProductGrid();
        });
      }
      
      // Auto-load categories if filter exists
      var categoryFilter = document.querySelector('[data-category-filter]');
      if (categoryFilter) { this.renderCategoryFilter(); }
      
      // Auto-load product detail if container exists
      var productDetail = document.querySelector('[data-product-detail]');
      if (productDetail) { this.renderProductDetail(); }
      
      // Setup price slider
      this.setupPriceSlider();
      
      // Setup search
      var searchInput = document.querySelector('[data-product-search]');
      if (searchInput) {
        var debounceTimer;
        searchInput.addEventListener('input', function() {
          clearTimeout(debounceTimer);
          debounceTimer = setTimeout(function() {
            self.filters.search = searchInput.value;
            self.applyFilters();
          }, 300);
        });
      }
      
      // Setup sort
      var sortSelect = document.querySelector('[data-product-sort]');
      if (sortSelect) {
        sortSelect.addEventListener('change', function() { self.setFilter('sort', this.value); });
      }
      
      // Listen for cart/wishlist updates from other tabs
      window.addEventListener('storage', function(e) {
        if (e.key === 'ecommerce_cart') { self.cart = JSON.parse(e.newValue || '[]'); self.updateAllUI(); }
        if (e.key === 'ecommerce_wishlist') { self.wishlist = JSON.parse(e.newValue || '[]'); self.updateAllUI(); }
      });
      
      // Listen for custom events
      window.addEventListener('cart-updated', function() { self.updateCartUI(); });
      window.addEventListener('wishlist-updated', function() { self.updateWishlistUI(); });
    }
  };
  
  // Initialize when DOM is ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { EcommerceStore.init(); });
  } else {
    EcommerceStore.init();
  }
})();
</script>
<style>
/* E-commerce animations */
@keyframes slideIn { from { transform: translateX(100%); opacity: 0; } to { transform: translateX(0); opacity: 1; } }
@keyframes slideOut { from { transform: translateX(0); opacity: 1; } to { transform: translateX(100%); opacity: 0; } }
@keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
@keyframes bounce { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.1); } }
@keyframes addToCartPop { 
  0% { transform: scale(1); } 
  30% { transform: scale(0.95); } 
  60% { transform: scale(1.05); } 
  100% { transform: scale(1); } 
}
@keyframes cartBadgePop {
  0% { transform: scale(1); }
  50% { transform: scale(1.4); }
  100% { transform: scale(1); }
}
@keyframes flyToCart {
  0% { opacity: 1; transform: scale(1) translate(0, 0); }
  100% { opacity: 0; transform: scale(0.3) translate(100px, -100px); }
}

/* Product card hover effects */
.product-card { transition: all 0.3s ease; }
.product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }

/* Add to cart button animation */
.add-to-cart-btn { transition: all 0.2s ease; position: relative; overflow: hidden; }
.add-to-cart-btn:hover:not(:disabled) { transform: scale(1.05); }
.add-to-cart-btn:active:not(:disabled) { transform: scale(0.95); }
.add-to-cart-btn.adding { animation: addToCartPop 0.4s ease; pointer-events: none; }
.add-to-cart-btn.adding::after {
  content: '✓';
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: inherit;
  font-size: 1.2em;
  animation: bounce 0.3s ease;
}

/* Cart badge animation */
.cart-badge.pop { animation: cartBadgePop 0.3s ease; }

/* Wishlist button animation */
.heart-icon { transition: all 0.2s ease; }
.heart-icon:hover { transform: scale(1.1); }
.heart-icon:active { transform: scale(0.9); }
.heart-icon.active svg { animation: bounce 0.3s ease; }

/* Category button hover */
[data-category-filter] button { transition: all 0.2s ease; }
[data-category-filter] button:hover { transform: translateX(4px); }

/* Price slider styling */
input[type="range"] { -webkit-appearance: none; appearance: none; height: 6px; border-radius: 3px; background: #e6e6e6; }
input[type="range"]::-webkit-slider-thumb { -webkit-appearance: none; appearance: none; width: 18px; height: 18px; border-radius: 50%; cursor: pointer; }
input[type="range"]::-moz-range-thumb { width: 18px; height: 18px; border-radius: 50%; cursor: pointer; border: none; }

/* Loading spinner */
.shop-loading { animation: spin 1s linear infinite; }

/* Smooth transitions for filter changes */
[data-product-grid] { transition: opacity 0.3s ease; }

/* Responsive adjustments */
@media (max-width: 768px) {
  .product-card:hover { transform: none; box-shadow: none; }
}
</style>
`


// =============================================================================
// SHOP PRESETS - Using ID-based CSS for GrapesJS editability
// =============================================================================

export const shopPresets: Preset[] = [
  // ============================================
  // E-COMMERCE HEADER
  // ============================================
  {
    id: 'shop-header',
    label: 'E-Commerce Header',
    category: 'Shop',
    content: `
      <header id="shop-header-section" style="background-color: ${SHOP_THEME.bgCard}; border-bottom: 1px solid ${SHOP_THEME.border}; padding: 15px 5%; position: sticky; top: 0; z-index: 1000;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1400px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; gap: 30px; font-family: 'Inter', sans-serif;">
          
          <a href="#" onclick="EcommerceStore.navigateToShop();return false;" style="font-size: 1.5rem; font-weight: 700; color: ${SHOP_THEME.primary}; text-decoration: none; display: flex; align-items: center; gap: 8px;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="${SHOP_THEME.primary}"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6" stroke="white" stroke-width="2"></line><path d="M16 10a4 4 0 0 1-8 0" stroke="white" stroke-width="2" fill="none"></path></svg>
            ShopName
          </a>
          
          <div style="flex: 1; max-width: 500px;">
            <div style="display: flex; align-items: center; background-color: ${SHOP_THEME.bgBody}; border: 1px solid ${SHOP_THEME.border}; border-radius: 50px; padding: 10px 20px;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.textGray}" stroke-width="2"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
              <input type="text" data-product-search placeholder="Search products..." style="flex: 1; border: none; background: none; padding: 5px 15px; font-size: 0.95rem; outline: none; color: ${SHOP_THEME.textDark};">
            </div>
          </div>
          
          <nav style="display: flex; align-items: center; gap: 25px;">
            <a href="#" onclick="EcommerceStore.navigateToShop();return false;" style="color: ${SHOP_THEME.textDark}; text-decoration: none; font-weight: 500; font-size: 0.95rem;">Shop</a>
            <a href="#" style="color: ${SHOP_THEME.textGray}; text-decoration: none; font-weight: 500; font-size: 0.95rem;">Categories</a>
            <a href="#" style="color: ${SHOP_THEME.textGray}; text-decoration: none; font-weight: 500; font-size: 0.95rem;">Deals</a>
          </nav>
          
          <div style="display: flex; align-items: center; gap: 20px;">
            <button onclick="EcommerceStore.navigateToWishlist()" style="position: relative; background: none; border: none; cursor: pointer; padding: 8px; color: ${SHOP_THEME.textDark};">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
              <span class="wishlist-badge" data-wishlist-count style="position: absolute; top: 0; right: 0; background-color: ${SHOP_THEME.badgeNew}; color: white; font-size: 0.7rem; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600;">0</span>
            </button>
            
            <button onclick="EcommerceStore.navigateToCart()" style="position: relative; background: none; border: none; cursor: pointer; padding: 8px; color: ${SHOP_THEME.textDark};">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"></path><line x1="3" y1="6" x2="21" y2="6"></line><path d="M16 10a4 4 0 0 1-8 0"></path></svg>
              <span class="cart-badge" data-cart-count style="position: absolute; top: 0; right: 0; background-color: ${SHOP_THEME.primary}; color: white; font-size: 0.7rem; width: 18px; height: 18px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 600;">0</span>
            </button>
            
            <button style="background: none; border: none; cursor: pointer; padding: 8px; color: ${SHOP_THEME.textDark};">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
            </button>
          </div>
        </div>
      </header>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // STORE PAGE (Main Shop Grid with Sidebar)
  // ============================================
  {
    id: 'shop-store-page',
    label: 'Store Page',
    category: 'Shop',
    content: `
      <section id="shop-store-section" style="background-color: ${SHOP_THEME.bgBody}; padding: 40px 5%; min-height: 80vh; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1400px; margin: 0 auto;">
          
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px;">
            <div>
              <h1 style="margin: 0 0 8px 0; font-size: 2rem; font-weight: 700; color: ${SHOP_THEME.textDark};">Our Products</h1>
              <p style="margin: 0; color: ${SHOP_THEME.textGray};">Discover our amazing collection</p>
            </div>
            
            <div style="display: flex; align-items: center; gap: 15px;">
              <select data-product-sort style="padding: 10px 20px; border: 1px solid ${SHOP_THEME.border}; border-radius: 8px; background-color: ${SHOP_THEME.bgCard}; color: ${SHOP_THEME.textDark}; font-size: 0.9rem; cursor: pointer;">
                <option value="name">Sort by Name</option>
                <option value="price_asc">Price: Low to High</option>
                <option value="price_desc">Price: High to Low</option>
                <option value="newest">Newest First</option>
              </select>
            </div>
          </div>
          
          <div style="display: flex; gap: 30px;">
            <!-- Redesigned Sidebar -->
            <aside style="width: 280px; flex-shrink: 0;">
              <div style="background: linear-gradient(180deg, ${SHOP_THEME.bgCard} 0%, #fafafa 100%); border-radius: 20px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid ${SHOP_THEME.border}; position: sticky; top: 100px;">
                
                <!-- Search Section -->
                <div style="padding: 24px; background: linear-gradient(135deg, ${SHOP_THEME.primary} 0%, ${SHOP_THEME.primaryHover} 100%);">
                  <div style="position: relative;">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: 0.7;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
                    <input type="text" data-product-search placeholder="Search products..." style="width: 100%; padding: 14px 14px 14px 44px; border: none; border-radius: 12px; font-size: 0.9rem; background: rgba(255,255,255,0.95); color: ${SHOP_THEME.textDark}; box-sizing: border-box;">
                  </div>
                </div>
                
                <!-- Categories Section -->
                <div style="padding: 24px; border-bottom: 1px solid ${SHOP_THEME.borderLight};">
                  <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px;">
                    <div style="width: 36px; height: 36px; background: ${SHOP_THEME.primaryLight}; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
                    </div>
                    <h3 style="margin: 0; font-size: 1rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Categories</h3>
                  </div>
                  <div data-category-filter style="display: flex; flex-direction: column; gap: 6px;"></div>
                </div>
                
                <!-- Price Range Section -->
                <div style="padding: 24px; border-bottom: 1px solid ${SHOP_THEME.borderLight};">
                  <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px;">
                    <div style="width: 36px; height: 36px; background: ${SHOP_THEME.primaryLight}; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                    </div>
                    <h3 style="margin: 0; font-size: 1rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Price Range</h3>
                  </div>
                  <div style="background: ${SHOP_THEME.bgBody}; border-radius: 12px; padding: 16px;">
                    <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
                      <span style="font-size: 0.9rem; font-weight: 600; color: ${SHOP_THEME.primary};" data-price-min-display>$0</span>
                      <span style="font-size: 0.85rem; color: ${SHOP_THEME.textGray};">to</span>
                      <span style="font-size: 0.9rem; font-weight: 600; color: ${SHOP_THEME.primary};" data-price-max-display>$10000</span>
                    </div>
                    <input type="range" data-price-min min="0" max="10000" value="0" style="width: 100%; accent-color: ${SHOP_THEME.primary}; height: 6px; cursor: pointer;">
                    <input type="range" data-price-max min="0" max="10000" value="10000" style="width: 100%; accent-color: ${SHOP_THEME.primary}; height: 6px; margin-top: 8px; cursor: pointer;">
                  </div>
                </div>
                
                <!-- Quick Filters Section -->
                <div style="padding: 24px;">
                  <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px;">
                    <div style="width: 36px; height: 36px; background: ${SHOP_THEME.primaryLight}; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
                      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
                    </div>
                    <h3 style="margin: 0; font-size: 1rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Quick Filters</h3>
                  </div>
                  <div style="display: flex; flex-direction: column; gap: 10px;">
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 12px 14px; background: ${SHOP_THEME.bgBody}; border-radius: 10px; transition: all 0.2s ease;">
                      <input type="checkbox" checked style="accent-color: ${SHOP_THEME.primary}; width: 18px; height: 18px; cursor: pointer;">
                      <span style="font-size: 0.9rem; color: ${SHOP_THEME.textDark}; font-weight: 500;">In Stock Only</span>
                      <span style="margin-left: auto; background: ${SHOP_THEME.success}; color: white; font-size: 0.7rem; padding: 3px 8px; border-radius: 10px;">Available</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 12px 14px; background: ${SHOP_THEME.bgBody}; border-radius: 10px; transition: all 0.2s ease;">
                      <input type="checkbox" style="accent-color: ${SHOP_THEME.primary}; width: 18px; height: 18px; cursor: pointer;">
                      <span style="font-size: 0.9rem; color: ${SHOP_THEME.textDark}; font-weight: 500;">New Arrivals</span>
                      <span style="margin-left: auto; background: ${SHOP_THEME.badgeNew}; color: white; font-size: 0.7rem; padding: 3px 8px; border-radius: 10px;">New</span>
                    </label>
                    <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 12px 14px; background: ${SHOP_THEME.bgBody}; border-radius: 10px; transition: all 0.2s ease;">
                      <input type="checkbox" style="accent-color: ${SHOP_THEME.primary}; width: 18px; height: 18px; cursor: pointer;">
                      <span style="font-size: 0.9rem; color: ${SHOP_THEME.textDark}; font-weight: 500;">On Sale</span>
                      <span style="margin-left: auto; background: ${SHOP_THEME.secondary}; color: ${SHOP_THEME.textDark}; font-size: 0.7rem; padding: 3px 8px; border-radius: 10px;">Sale</span>
                    </label>
                  </div>
                </div>
                
                <!-- Reset Button -->
                <div style="padding: 0 24px 24px;">
                  <button onclick="EcommerceStore.filters = { category: null, minPrice: 0, maxPrice: 10000, search: '', sort: 'name' }; EcommerceStore.applyFilters();" style="width: 100%; padding: 14px; background: transparent; border: 2px dashed ${SHOP_THEME.border}; border-radius: 12px; color: ${SHOP_THEME.textGray}; font-weight: 500; cursor: pointer; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.2s ease;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="1 4 1 10 7 10"></polyline><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"></path></svg>
                    Reset All Filters
                  </button>
                </div>
              </div>
            </aside>
            
            <!-- Main Content -->
            <div style="flex: 1;">
              <div data-product-grid style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 25px;">
                <div style="text-align: center; padding: 60px 20px; color: ${SHOP_THEME.textGray}; grid-column: 1/-1;" data-loading-msg>
                  <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="margin-bottom: 15px; animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
                  <p>Loading products...</p>
                </div>
              </div>
              
              <!-- Pagination -->
              <div data-pagination style="margin-top: 40px;"></div>
            </div>
          </div>
        </div>
        
        <style>
          @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
          #shop-store-section .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
          #shop-store-section aside label:hover { background: ${SHOP_THEME.primaryLight} !important; }
          #shop-store-section aside button:hover { border-color: ${SHOP_THEME.primary}; color: ${SHOP_THEME.primary}; }
          @media (max-width: 1200px) {
            #shop-store-section [data-product-grid] { grid-template-columns: repeat(3, 1fr); }
          }
          @media (max-width: 900px) {
            #shop-store-section [data-product-grid] { grid-template-columns: repeat(2, 1fr); }
            #shop-store-section aside { display: none; }
          }
          @media (max-width: 600px) {
            #shop-store-section [data-product-grid] { grid-template-columns: 1fr; }
          }
        </style>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // PRODUCT GRID (Standalone)
  // ============================================
  {
    id: 'shop-product-grid',
    label: 'Product Grid',
    category: 'Shop',
    content: `
      <section id="shop-product-grid-section" style="background-color: ${SHOP_THEME.bgBody}; padding: 60px 5%; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1400px; margin: 0 auto;">
          <div style="text-align: center; margin-bottom: 40px;">
            <h2 style="margin: 0 0 10px 0; font-size: 2.2rem; font-weight: 700; color: ${SHOP_THEME.textDark};">Featured Products</h2>
            <p style="margin: 0; color: ${SHOP_THEME.textGray}; font-size: 1.1rem;">Check out our best sellers</p>
          </div>
          
          <div data-product-grid style="display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 25px;">
            <div style="text-align: center; padding: 60px 20px; color: ${SHOP_THEME.textGray}; grid-column: 1/-1;" data-loading-msg>Loading products...</div>
          </div>
          
          <div style="text-align: center; margin-top: 40px;">
            <button onclick="EcommerceStore.navigateToShop()" style="background-color: ${SHOP_THEME.primary}; color: white; border: none; padding: 15px 40px; border-radius: 50px; font-weight: 600; font-size: 1rem; cursor: pointer;">
              View All Products
            </button>
          </div>
        </div>
        
        <style>
          #shop-product-grid-section .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        </style>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },


  // ============================================
  // CART PAGE
  // ============================================
  {
    id: 'shop-cart-page',
    label: 'Cart Page',
    category: 'Shop',
    content: `
      <section id="shop-cart-section" style="background-color: ${SHOP_THEME.bgBody}; padding: 40px 5%; min-height: 70vh; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1200px; margin: 0 auto;">
          
          <h1 style="margin: 0 0 30px 0; font-size: 2rem; font-weight: 700; color: ${SHOP_THEME.textDark};">Shopping Cart</h1>
          
          <div style="display: grid; grid-template-columns: 1fr 380px; gap: 30px;">
            <div data-cart-items>
              <div style="text-align: center; padding: 60px 20px; color: ${SHOP_THEME.textGray};">Loading cart...</div>
            </div>
            
            <div style="background-color: ${SHOP_THEME.bgCard}; border-radius: 16px; padding: 25px; height: fit-content; border: 1px solid ${SHOP_THEME.border}; position: sticky; top: 100px;">
              <h3 style="margin: 0 0 20px 0; font-size: 1.2rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Order Summary</h3>
              
              <div style="display: flex; flex-direction: column; gap: 12px; padding-bottom: 20px; border-bottom: 1px solid ${SHOP_THEME.borderLight};">
                <div style="display: flex; justify-content: space-between;">
                  <span style="color: ${SHOP_THEME.textGray};">Subtotal</span>
                  <span style="font-weight: 600; color: ${SHOP_THEME.textDark};" data-cart-subtotal>$0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                  <span style="color: ${SHOP_THEME.textGray};">Shipping</span>
                  <span style="color: ${SHOP_THEME.success}; font-weight: 500;">Free</span>
                </div>
              </div>
              
              <div style="display: flex; justify-content: space-between; padding: 20px 0; border-bottom: 1px solid ${SHOP_THEME.borderLight};">
                <span style="font-weight: 600; color: ${SHOP_THEME.textDark};">Total</span>
                <span style="font-weight: 700; font-size: 1.3rem; color: ${SHOP_THEME.primary};" data-cart-total-final>$0.00</span>
              </div>
              
              <div style="margin-top: 20px;">
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                  <input type="text" placeholder="Coupon code" style="flex: 1; padding: 12px 15px; border: 1px solid ${SHOP_THEME.border}; border-radius: 8px; font-size: 0.9rem;">
                  <button style="padding: 12px 20px; background-color: ${SHOP_THEME.bgBody}; border: 1px solid ${SHOP_THEME.border}; border-radius: 8px; font-weight: 500; cursor: pointer; color: ${SHOP_THEME.textDark};">Apply</button>
                </div>
                
                <button onclick="EcommerceStore.navigateToCheckout()" style="width: 100%; background-color: ${SHOP_THEME.primary}; color: white; border: none; padding: 16px; border-radius: 50px; font-weight: 600; font-size: 1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                  Proceed to Checkout
                </button>
                
                <button onclick="EcommerceStore.navigateToShop()" style="width: 100%; background: none; border: none; padding: 15px; color: ${SHOP_THEME.primary}; font-weight: 500; cursor: pointer; margin-top: 10px;">
                  ← Continue Shopping
                </button>
              </div>
            </div>
          </div>
        </div>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // WISHLIST PAGE
  // ============================================
  {
    id: 'shop-wishlist-page',
    label: 'Wishlist Page',
    category: 'Shop',
    content: `
      <section id="shop-wishlist-section" style="background-color: ${SHOP_THEME.bgBody}; padding: 40px 5%; min-height: 70vh; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1200px; margin: 0 auto;">
          
          <div style="display: flex; align-items: center; gap: 15px; margin-bottom: 30px;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="${SHOP_THEME.primary}" stroke="${SHOP_THEME.primary}" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"></path></svg>
            <h1 style="margin: 0; font-size: 2rem; font-weight: 700; color: ${SHOP_THEME.textDark};">My Wishlist</h1>
            <span style="background-color: ${SHOP_THEME.primaryLight}; color: ${SHOP_THEME.primary}; padding: 5px 15px; border-radius: 20px; font-weight: 600; font-size: 0.9rem;" data-wishlist-count>0</span>
          </div>
          
          <div data-wishlist-items>
            <div style="text-align: center; padding: 60px 20px; color: ${SHOP_THEME.textGray};">Loading wishlist...</div>
          </div>
        </div>
        
        <style>
          #shop-wishlist-section .product-card:hover { transform: translateY(-5px); box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        </style>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // CHECKOUT PAGE
  // ============================================
  {
    id: 'shop-checkout-page',
    label: 'Checkout Page',
    category: 'Shop',
    content: `
      <section id="shop-checkout-section" style="background-color: ${SHOP_THEME.bgBody}; padding: 40px 5%; min-height: 80vh; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1200px; margin: 0 auto;">
          
          <h1 style="margin: 0 0 30px 0; font-size: 2rem; font-weight: 700; color: ${SHOP_THEME.textDark};">Checkout</h1>
          
          <div style="display: grid; grid-template-columns: 1fr 420px; gap: 40px;">
            <div>
              <div style="background-color: ${SHOP_THEME.bgCard}; border-radius: 16px; padding: 30px; margin-bottom: 25px; border: 1px solid ${SHOP_THEME.border};">
                <h3 style="margin: 0 0 25px 0; font-size: 1.2rem; font-weight: 600; color: ${SHOP_THEME.textDark}; display: flex; align-items: center; gap: 10px;">
                  <span style="width: 28px; height: 28px; background-color: ${SHOP_THEME.primary}; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">1</span>
                  Contact Information
                </h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                  <input type="text" placeholder="First Name" style="padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                  <input type="text" placeholder="Last Name" style="padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                  <input type="email" placeholder="Email Address" style="grid-column: 1/-1; padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                  <input type="tel" placeholder="Phone Number" style="grid-column: 1/-1; padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                </div>
              </div>
              
              <div style="background-color: ${SHOP_THEME.bgCard}; border-radius: 16px; padding: 30px; margin-bottom: 25px; border: 1px solid ${SHOP_THEME.border};">
                <h3 style="margin: 0 0 25px 0; font-size: 1.2rem; font-weight: 600; color: ${SHOP_THEME.textDark}; display: flex; align-items: center; gap: 10px;">
                  <span style="width: 28px; height: 28px; background-color: ${SHOP_THEME.primary}; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">2</span>
                  Shipping Address
                </h3>
                <div style="display: grid; grid-template-columns: 1fr; gap: 15px;">
                  <input type="text" placeholder="Street Address" style="padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                  <input type="text" placeholder="Apartment, suite, etc. (optional)" style="padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                  <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 15px;">
                    <input type="text" placeholder="City" style="padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                    <input type="text" placeholder="State" style="padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                    <input type="text" placeholder="ZIP Code" style="padding: 14px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; font-size: 0.95rem;">
                  </div>
                </div>
              </div>
              
              <div style="background-color: ${SHOP_THEME.bgCard}; border-radius: 16px; padding: 30px; border: 1px solid ${SHOP_THEME.border};">
                <h3 style="margin: 0 0 25px 0; font-size: 1.2rem; font-weight: 600; color: ${SHOP_THEME.textDark}; display: flex; align-items: center; gap: 10px;">
                  <span style="width: 28px; height: 28px; background-color: ${SHOP_THEME.primary}; color: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 0.85rem;">3</span>
                  Payment Method
                </h3>
                <div style="display: flex; flex-direction: column; gap: 12px;">
                  <label style="display: flex; align-items: center; gap: 12px; padding: 15px 18px; border: 2px solid ${SHOP_THEME.primary}; border-radius: 10px; cursor: pointer; background-color: ${SHOP_THEME.primaryLight};">
                    <input type="radio" name="payment" checked style="accent-color: ${SHOP_THEME.primary};">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"></rect><line x1="1" y1="10" x2="23" y2="10"></line></svg>
                    <span style="font-weight: 500; color: ${SHOP_THEME.textDark};">Credit / Debit Card</span>
                  </label>
                  <label style="display: flex; align-items: center; gap: 12px; padding: 15px 18px; border: 1px solid ${SHOP_THEME.border}; border-radius: 10px; cursor: pointer;">
                    <input type="radio" name="payment" style="accent-color: ${SHOP_THEME.primary};">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.textGray}" stroke-width="2"><rect x="2" y="5" width="20" height="14" rx="2"></rect><line x1="2" y1="10" x2="22" y2="10"></line></svg>
                    <span style="font-weight: 500; color: ${SHOP_THEME.textDark};">PayPal</span>
                  </label>
                </div>
              </div>
            </div>
            
            <div style="background-color: ${SHOP_THEME.bgCard}; border-radius: 16px; padding: 25px; height: fit-content; border: 1px solid ${SHOP_THEME.border}; position: sticky; top: 100px;">
              <h3 style="margin: 0 0 20px 0; font-size: 1.2rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Order Summary</h3>
              
              <div data-checkout-items style="max-height: 300px; overflow-y: auto; margin-bottom: 20px;">
                <div style="text-align: center; padding: 20px; color: ${SHOP_THEME.textGray};">Loading items...</div>
              </div>
              
              <div style="display: flex; flex-direction: column; gap: 12px; padding: 20px 0; border-top: 1px solid ${SHOP_THEME.borderLight}; border-bottom: 1px solid ${SHOP_THEME.borderLight};">
                <div style="display: flex; justify-content: space-between;">
                  <span style="color: ${SHOP_THEME.textGray};">Subtotal</span>
                  <span style="font-weight: 600; color: ${SHOP_THEME.textDark};" data-cart-subtotal>$0.00</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                  <span style="color: ${SHOP_THEME.textGray};">Shipping</span>
                  <span style="color: ${SHOP_THEME.success}; font-weight: 500;">Free</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                  <span style="color: ${SHOP_THEME.textGray};">Tax</span>
                  <span style="font-weight: 500; color: ${SHOP_THEME.textDark};">$0.00</span>
                </div>
              </div>
              
              <div style="display: flex; justify-content: space-between; padding: 20px 0;">
                <span style="font-weight: 600; font-size: 1.1rem; color: ${SHOP_THEME.textDark};">Total</span>
                <span style="font-weight: 700; font-size: 1.4rem; color: ${SHOP_THEME.primary};" data-cart-total-final>$0.00</span>
              </div>
              
              <button style="width: 100%; background-color: ${SHOP_THEME.primary}; color: white; border: none; padding: 18px; border-radius: 50px; font-weight: 600; font-size: 1.1rem; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 10px;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                Place Order
              </button>
              
              <p style="text-align: center; font-size: 0.8rem; color: ${SHOP_THEME.textGray}; margin-top: 15px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align: middle; margin-right: 5px;"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
                Secure checkout powered by SSL encryption
              </p>
            </div>
          </div>
        </div>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },


  // ============================================
  // PRODUCT DETAIL PAGE
  // ============================================
  {
    id: 'shop-product-detail',
    label: 'Product Detail',
    category: 'Shop',
    content: `
      <section id="shop-product-detail-section" style="background-color: ${SHOP_THEME.bgBody}; padding: 40px 5%; min-height: 70vh; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1200px; margin: 0 auto;">
          
          <nav style="margin-bottom: 30px;">
            <a href="#" onclick="EcommerceStore.navigateToShop();return false;" style="color: ${SHOP_THEME.textGray}; text-decoration: none; font-size: 0.9rem;">Shop</a>
            <span style="color: ${SHOP_THEME.textGray}; margin: 0 10px;">/</span>
            <span style="color: ${SHOP_THEME.textDark}; font-size: 0.9rem;">Product Details</span>
          </nav>
          
          <div data-product-detail>
            <div style="text-align: center; padding: 100px 20px; color: ${SHOP_THEME.textGray};">
              <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" style="margin-bottom: 15px; animation: spin 1s linear infinite;"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>
              <p>Loading product details...</p>
            </div>
          </div>
        </div>
        
        <style>
          @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
        </style>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // CATEGORY FILTER SIDEBAR
  // ============================================
  {
    id: 'shop-filter-sidebar',
    label: 'Filter Sidebar',
    category: 'Shop',
    content: `
      <aside id="shop-filter-sidebar-section" style="background: linear-gradient(180deg, ${SHOP_THEME.bgCard} 0%, #fafafa 100%); border-radius: 20px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.06); border: 1px solid ${SHOP_THEME.border}; font-family: 'Inter', sans-serif; width: 280px;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        
        <!-- Search Section -->
        <div style="padding: 24px; background: linear-gradient(135deg, ${SHOP_THEME.primary} 0%, ${SHOP_THEME.primaryHover} 100%);">
          <div style="position: relative;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2" style="position: absolute; left: 14px; top: 50%; transform: translateY(-50%); opacity: 0.7;"><circle cx="11" cy="11" r="8"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            <input type="text" data-product-search placeholder="Search products..." style="width: 100%; padding: 14px 14px 14px 44px; border: none; border-radius: 12px; font-size: 0.9rem; background: rgba(255,255,255,0.95); color: ${SHOP_THEME.textDark}; box-sizing: border-box;">
          </div>
        </div>
        
        <!-- Categories Section -->
        <div style="padding: 24px; border-bottom: 1px solid ${SHOP_THEME.borderLight};">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px;">
            <div style="width: 36px; height: 36px; background: ${SHOP_THEME.primaryLight}; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><rect x="3" y="3" width="7" height="7"></rect><rect x="14" y="3" width="7" height="7"></rect><rect x="14" y="14" width="7" height="7"></rect><rect x="3" y="14" width="7" height="7"></rect></svg>
            </div>
            <h3 style="margin: 0; font-size: 1rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Categories</h3>
          </div>
          <div data-category-filter style="display: flex; flex-direction: column; gap: 6px;">
            <button style="text-align: left; padding: 12px 16px; border: none; background: linear-gradient(135deg, ${SHOP_THEME.primary} 0%, ${SHOP_THEME.primaryHover} 100%); color: white; border-radius: 10px; cursor: pointer; font-size: 0.9rem; font-weight: 500;">All Categories</button>
          </div>
        </div>
        
        <!-- Price Range Section -->
        <div style="padding: 24px; border-bottom: 1px solid ${SHOP_THEME.borderLight};">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px;">
            <div style="width: 36px; height: 36px; background: ${SHOP_THEME.primaryLight}; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><line x1="12" y1="1" x2="12" y2="23"></line><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
            </div>
            <h3 style="margin: 0; font-size: 1rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Price Range</h3>
          </div>
          <div style="background: ${SHOP_THEME.bgBody}; border-radius: 12px; padding: 16px;">
            <div style="display: flex; justify-content: space-between; margin-bottom: 12px;">
              <span style="font-size: 0.9rem; font-weight: 600; color: ${SHOP_THEME.primary};" data-price-min-display>$0</span>
              <span style="font-size: 0.85rem; color: ${SHOP_THEME.textGray};">to</span>
              <span style="font-size: 0.9rem; font-weight: 600; color: ${SHOP_THEME.primary};" data-price-max-display>$10000</span>
            </div>
            <input type="range" data-price-min min="0" max="10000" value="0" style="width: 100%; accent-color: ${SHOP_THEME.primary}; height: 6px; cursor: pointer;">
            <input type="range" data-price-max min="0" max="10000" value="10000" style="width: 100%; accent-color: ${SHOP_THEME.primary}; height: 6px; margin-top: 8px; cursor: pointer;">
          </div>
        </div>
        
        <!-- Quick Filters Section -->
        <div style="padding: 24px;">
          <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 18px;">
            <div style="width: 36px; height: 36px; background: ${SHOP_THEME.primaryLight}; border-radius: 10px; display: flex; align-items: center; justify-content: center;">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon></svg>
            </div>
            <h3 style="margin: 0; font-size: 1rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Availability</h3>
          </div>
          <div style="display: flex; flex-direction: column; gap: 10px;">
            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 12px 14px; background: ${SHOP_THEME.bgBody}; border-radius: 10px;">
              <input type="checkbox" checked style="accent-color: ${SHOP_THEME.primary}; width: 18px; height: 18px; cursor: pointer;">
              <span style="font-size: 0.9rem; color: ${SHOP_THEME.textDark}; font-weight: 500;">In Stock</span>
              <span style="margin-left: auto; background: ${SHOP_THEME.success}; color: white; font-size: 0.7rem; padding: 3px 8px; border-radius: 10px;">Available</span>
            </label>
            <label style="display: flex; align-items: center; gap: 12px; cursor: pointer; padding: 12px 14px; background: ${SHOP_THEME.bgBody}; border-radius: 10px;">
              <input type="checkbox" style="accent-color: ${SHOP_THEME.primary}; width: 18px; height: 18px; cursor: pointer;">
              <span style="font-size: 0.9rem; color: ${SHOP_THEME.textDark}; font-weight: 500;">Out of Stock</span>
            </label>
          </div>
        </div>
      </aside>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // PROMO BANNER
  // ============================================
  {
    id: 'shop-promo-banner',
    label: 'Promo Banner',
    category: 'Shop',
    content: `
      <section id="shop-promo-banner-section" style="background: linear-gradient(135deg, ${SHOP_THEME.primary} 0%, ${SHOP_THEME.primaryHover} 100%); padding: 60px 5%; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1200px; margin: 0 auto; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 30px;">
          
          <div style="flex: 1; min-width: 300px;">
            <span style="background-color: rgba(255,255,255,0.2); color: white; padding: 6px 16px; border-radius: 20px; font-size: 0.85rem; font-weight: 600; display: inline-block; margin-bottom: 15px;">LIMITED TIME OFFER</span>
            <h2 style="margin: 0 0 15px 0; font-size: 2.5rem; font-weight: 700; color: white; line-height: 1.2;">Summer Sale<br>Up to 50% Off</h2>
            <p style="margin: 0 0 25px 0; color: rgba(255,255,255,0.9); font-size: 1.1rem;">Don't miss out on our biggest sale of the year. Shop now and save big!</p>
            <button onclick="EcommerceStore.navigateToShop()" style="background-color: white; color: ${SHOP_THEME.primary}; border: none; padding: 15px 35px; border-radius: 50px; font-weight: 600; font-size: 1rem; cursor: pointer; display: inline-flex; align-items: center; gap: 10px;">
              Shop Now
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>
            </button>
          </div>
          
          <div style="display: flex; gap: 20px;">
            <div style="background-color: rgba(255,255,255,0.15); backdrop-filter: blur(10px); padding: 20px 30px; border-radius: 12px; text-align: center;">
              <div style="font-size: 2.5rem; font-weight: 700; color: white;">15</div>
              <div style="font-size: 0.85rem; color: rgba(255,255,255,0.8);">Days</div>
            </div>
            <div style="background-color: rgba(255,255,255,0.15); backdrop-filter: blur(10px); padding: 20px 30px; border-radius: 12px; text-align: center;">
              <div style="font-size: 2.5rem; font-weight: 700; color: white;">08</div>
              <div style="font-size: 0.85rem; color: rgba(255,255,255,0.8);">Hours</div>
            </div>
            <div style="background-color: rgba(255,255,255,0.15); backdrop-filter: blur(10px); padding: 20px 30px; border-radius: 12px; text-align: center;">
              <div style="font-size: 2.5rem; font-weight: 700; color: white;">45</div>
              <div style="font-size: 0.85rem; color: rgba(255,255,255,0.8);">Mins</div>
            </div>
          </div>
        </div>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // FEATURES/BENEFITS BAR
  // ============================================
  {
    id: 'shop-features-bar',
    label: 'Features Bar',
    category: 'Shop',
    content: `
      <section id="shop-features-bar-section" style="background-color: ${SHOP_THEME.bgCard}; border-top: 1px solid ${SHOP_THEME.border}; border-bottom: 1px solid ${SHOP_THEME.border}; padding: 30px 5%; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 1400px; margin: 0 auto; display: grid; grid-template-columns: repeat(4, 1fr); gap: 30px;">
          
          <div style="display: flex; align-items: center; gap: 15px;">
            <div style="width: 50px; height: 50px; background-color: ${SHOP_THEME.primaryLight}; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><rect x="1" y="3" width="15" height="13"></rect><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon><circle cx="5.5" cy="18.5" r="2.5"></circle><circle cx="18.5" cy="18.5" r="2.5"></circle></svg>
            </div>
            <div>
              <h4 style="margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Free Shipping</h4>
              <p style="margin: 0; font-size: 0.85rem; color: ${SHOP_THEME.textGray};">On orders over $50</p>
            </div>
          </div>
          
          <div style="display: flex; align-items: center; gap: 15px;">
            <div style="width: 50px; height: 50px; background-color: ${SHOP_THEME.primaryLight}; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
            </div>
            <div>
              <h4 style="margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 600; color: ${SHOP_THEME.textDark};">24/7 Support</h4>
              <p style="margin: 0; font-size: 0.85rem; color: ${SHOP_THEME.textGray};">Always here to help</p>
            </div>
          </div>
          
          <div style="display: flex; align-items: center; gap: 15px;">
            <div style="width: 50px; height: 50px; background-color: ${SHOP_THEME.primaryLight}; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><polyline points="23 4 23 10 17 10"></polyline><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path></svg>
            </div>
            <div>
              <h4 style="margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Easy Returns</h4>
              <p style="margin: 0; font-size: 0.85rem; color: ${SHOP_THEME.textGray};">30-day return policy</p>
            </div>
          </div>
          
          <div style="display: flex; align-items: center; gap: 15px;">
            <div style="width: 50px; height: 50px; background-color: ${SHOP_THEME.primaryLight}; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="${SHOP_THEME.primary}" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
            </div>
            <div>
              <h4 style="margin: 0 0 4px 0; font-size: 0.95rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Secure Payment</h4>
              <p style="margin: 0; font-size: 0.85rem; color: ${SHOP_THEME.textGray};">100% secure checkout</p>
            </div>
          </div>
        </div>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // NEWSLETTER SIGNUP
  // ============================================
  {
    id: 'shop-newsletter',
    label: 'Newsletter Signup',
    category: 'Shop',
    content: `
      <section id="shop-newsletter-section" style="background-color: ${SHOP_THEME.textDark}; padding: 60px 5%; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        <div style="max-width: 700px; margin: 0 auto; text-align: center;">
          
          <div style="width: 60px; height: 60px; background-color: ${SHOP_THEME.primary}; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 25px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>
          </div>
          
          <h2 style="margin: 0 0 15px 0; font-size: 2rem; font-weight: 700; color: white;">Subscribe to Our Newsletter</h2>
          <p style="margin: 0 0 30px 0; color: rgba(255,255,255,0.7); font-size: 1.1rem;">Get the latest updates on new products and upcoming sales</p>
          
          <form style="display: flex; gap: 12px; max-width: 500px; margin: 0 auto;">
            <input type="email" placeholder="Enter your email address" style="flex: 1; padding: 16px 24px; border: none; border-radius: 50px; font-size: 1rem; background-color: rgba(255,255,255,0.1); color: white;">
            <button type="submit" style="background-color: ${SHOP_THEME.primary}; color: white; border: none; padding: 16px 35px; border-radius: 50px; font-weight: 600; font-size: 1rem; cursor: pointer; white-space: nowrap;">
              Subscribe
            </button>
          </form>
          
          <p style="margin: 20px 0 0 0; font-size: 0.85rem; color: rgba(255,255,255,0.5);">By subscribing, you agree to our Privacy Policy</p>
        </div>
      </section>
      ${ECOMMERCE_SCRIPT}
    `
  },

  // ============================================
  // MINI CART DROPDOWN
  // ============================================
  {
    id: 'shop-mini-cart',
    label: 'Mini Cart',
    category: 'Shop',
    content: `
      <div id="shop-mini-cart-section" style="background-color: ${SHOP_THEME.bgCard}; border-radius: 16px; padding: 20px; width: 350px; box-shadow: 0 10px 40px rgba(0,0,0,0.15); border: 1px solid ${SHOP_THEME.border}; font-family: 'Inter', sans-serif;">
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid ${SHOP_THEME.borderLight};">
          <h3 style="margin: 0; font-size: 1.1rem; font-weight: 600; color: ${SHOP_THEME.textDark};">Shopping Cart</h3>
          <span style="background-color: ${SHOP_THEME.primaryLight}; color: ${SHOP_THEME.primary}; padding: 4px 12px; border-radius: 20px; font-size: 0.85rem; font-weight: 600;" data-cart-count>0</span>
        </div>
        
        <div data-mini-cart>
          <div style="padding: 30px; text-align: center; color: ${SHOP_THEME.textGray};">Your cart is empty</div>
        </div>
      </div>
      ${ECOMMERCE_SCRIPT}
    `
  },
]

// =============================================================================
// HELPER FUNCTIONS FOR DEFAULT PAGE CONTENT
// Used when auto-creating shop pages
// =============================================================================

/**
 * Get the default HTML content for a shop page type
 */
export function getShopPageDefaultContent(pageType: 'shop' | 'cart' | 'checkout' | 'product'): { html: string; css: string } {
  const preset = shopPresets.find(p => {
    switch (pageType) {
      case 'shop': return p.id === 'shop-store-page'
      case 'cart': return p.id === 'shop-cart-page'
      case 'checkout': return p.id === 'shop-checkout-page'
      case 'product': return p.id === 'shop-product-detail'
      default: return false
    }
  })
  
  if (!preset) {
    return { html: '', css: '' }
  }
  
  return {
    html: preset.content,
    css: ''
  }
}

export default shopPresets
