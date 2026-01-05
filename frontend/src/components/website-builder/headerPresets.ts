// Header Presets - 12 Modern Header Designs
// All styles are inline for GrapesJS compatibility
// SVGs have explicit stroke/fill colors

export interface Preset {
  id: string
  label: string
  category: string
  content: string
}

export const headerPresets: Preset[] = [
  // 1. Floating Pill (Astrolux Style)
  {
    id: 'header-floating-pill',
    label: 'Floating Pill',
    category: 'Headers',
    content: `
      <nav style="border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); background: rgba(15,23,42,0.9); backdrop-filter: blur(24px); box-shadow: 0 25px 50px -12px rgba(0,0,0,0.25); overflow: hidden;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 24px;">
          <!-- Logo -->
          <div style="display: flex; align-items: center; gap: 12px;">
            <div style="width: 40px; height: 40px; background: linear-gradient(to bottom right, #f97316, #ec4899); border-radius: 12px; display: flex; align-items: center; justify-content: center;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M12 2v20M2 12h20"/></svg>
            </div>
            <span style="font-size: 18px; font-weight: 600; color: #ffffff;">Astrolux</span>
          </div>
          
          <!-- Nav Links -->
          <ul style="display: flex; align-items: center; gap: 4px; list-style: none; margin: 0; padding: 0;">
            <li><a href="#" style="display: block; padding: 8px 16px; border-radius: 9999px; font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.6); text-decoration: none;">Missions</a></li>
            <li><a href="#" style="display: block; padding: 8px 16px; border-radius: 9999px; font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.6); text-decoration: none;">Technology</a></li>
            <li><a href="#" style="display: block; padding: 8px 16px; border-radius: 9999px; font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.6); text-decoration: none;">Training</a></li>
            <li><a href="#" style="display: block; padding: 8px 16px; border-radius: 9999px; font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.6); text-decoration: none;">Travelers</a></li>
          </ul>
          
          <!-- Actions -->
          <div style="display: flex; align-items: center; gap: 8px;">
            <button style="padding: 10px; border-radius: 9999px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.05); cursor: pointer;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </button>
            <button style="position: relative; padding: 10px; border-radius: 9999px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.05); cursor: pointer;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.6)" stroke-width="2"><rect width="8" height="4" x="8" y="2" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="M12 11h4"/><path d="M12 16h4"/></svg>
              <span style="position: absolute; top: -4px; right: -4px; width: 16px; height: 16px; background: #3b82f6; border-radius: 9999px; font-size: 10px; color: #ffffff; display: flex; align-items: center; justify-content: center;">1</span>
            </button>
          </div>
        </div>
      </nav>
    `
  },

  // 2. Fashion E-Commerce (CERENZA)
  {
    id: 'header-fashion-ecommerce',
    label: 'Fashion E-Commerce',
    category: 'Headers',
    content: `
      <header style="border-radius: 16px; border: 1px solid #e5e7eb; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; background: #ffffff;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 24px; border-bottom: 1px solid #f3f4f6;">
          <!-- Mobile Menu -->
          <button style="padding: 8px; color: #4b5563; background: transparent; border: none; cursor: pointer;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2"><path d="M4 5h16M4 12h16M4 19h16"/></svg>
          </button>
          
          <!-- Brand -->
          <a href="#" onclick="if(typeof EcommerceStore!=='undefined')EcommerceStore.navigateToShop();return false;" style="font-size: 24px; font-family: serif; letter-spacing: 0.1em; font-weight: 600; color: #111827; text-decoration: none;">CERENZA</a>
          
          <!-- Actions -->
          <div style="display: flex; align-items: center; gap: 8px;">
            <button style="padding: 8px; color: #4b5563; background: transparent; border: none; cursor: pointer;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>
            </button>
            <button onclick="if(typeof EcommerceStore!=='undefined')EcommerceStore.navigateToWishlist()" style="position: relative; padding: 8px; color: #4b5563; background: transparent; border: none; cursor: pointer;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              <span class="wishlist-badge" data-wishlist-count style="position: absolute; top: 0; right: 0; width: 16px; height: 16px; background: #ef4444; color: white; font-size: 10px; font-weight: 600; border-radius: 50%; display: none; align-items: center; justify-content: center;">0</span>
            </button>
            <button onclick="if(typeof EcommerceStore!=='undefined')EcommerceStore.navigateToCart()" style="position: relative; padding: 8px; color: #4b5563; background: transparent; border: none; cursor: pointer;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
              <span class="cart-badge" data-cart-count style="position: absolute; top: 0; right: 0; width: 16px; height: 16px; background: #111827; color: white; font-size: 10px; font-weight: 600; border-radius: 50%; display: none; align-items: center; justify-content: center;">0</span>
            </button>
          </div>
        </div>
        
        <!-- Category Nav -->
        <div style="padding: 12px 24px; border-bottom: 1px solid #f3f4f6;">
          <ul style="display: flex; gap: 24px; overflow-x: auto; list-style: none; margin: 0; padding: 0;">
            <li><a href="#" onclick="if(typeof EcommerceStore!=='undefined')EcommerceStore.navigateToShop();return false;" style="font-size: 14px; font-weight: 500; color: #4b5563; text-decoration: none; white-space: nowrap;">Shop</a></li>
            <li><a href="#" style="font-size: 14px; font-weight: 500; color: #4b5563; text-decoration: none; white-space: nowrap;">New Arrivals</a></li>
            <li><a href="#" style="font-size: 14px; font-weight: 500; color: #4b5563; text-decoration: none; white-space: nowrap;">Collections</a></li>
            <li><a href="#" style="font-size: 14px; font-weight: 500; color: #4b5563; text-decoration: none; white-space: nowrap;">Sale</a></li>
            <li><a href="#" style="font-size: 14px; font-weight: 500; color: #4b5563; text-decoration: none; white-space: nowrap;">About</a></li>
            <li><a href="#" style="font-size: 14px; font-weight: 500; color: #4b5563; text-decoration: none; white-space: nowrap;">Contact</a></li>
          </ul>
        </div>
      </header>
    `
  },

  // 3. SaaS Dashboard (Pinboard Style)
  {
    id: 'header-saas-dashboard',
    label: 'SaaS Dashboard',
    category: 'Headers',
    content: `
      <nav style="border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); background: rgba(29,32,62,0.8); backdrop-filter: blur(24px); overflow: hidden;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 24px;">
          <!-- Left: Brand + Nav -->
          <div style="display: flex; align-items: center; gap: 24px;">
            <a href="#" style="display: flex; align-items: center; gap: 8px; text-decoration: none;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#a78bfa" stroke-width="1.5"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
              <span style="font-size: 18px; font-weight: 500; color: #ffffff;">Pinboard</span>
            </a>
            <div style="display: flex; align-items: center; gap: 20px; font-size: 14px;">
              <a href="#" style="color: #e2e8f0; text-decoration: none;">Discover</a>
              <a href="#" style="color: #94a3b8; text-decoration: none;">Trending</a>
              <a href="#" style="color: #94a3b8; text-decoration: none;">Upload</a>
            </div>
          </div>
          
          <!-- Center: Search -->
          <div style="flex: 1; max-width: 512px; margin: 0 24px;">
            <div style="position: relative;">
              <input type="text" placeholder="Find creative inspiration" style="width: 100%; background: rgba(15,23,42,0.8); border: 1px solid rgba(255,255,255,0.1); border-radius: 9999px; padding: 10px 80px 10px 40px; font-size: 14px; color: #f1f5f9; outline: none;">
              <svg style="position: absolute; left: 12px; top: 50%; transform: translateY(-50%);" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>
              <div style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); display: flex; align-items: center; gap: 4px; padding: 4px 8px; border-radius: 6px; background: rgba(30,41,59,0.7); font-size: 11px; color: #cbd5e1;">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><path d="M15 6v12a3 3 0 1 0 3-3H6a3 3 0 1 0 3 3V6"/></svg>
                K
              </div>
            </div>
          </div>
          
          <!-- Right: Actions -->
          <div style="display: flex; align-items: center; gap: 12px;">
            <button style="padding: 8px; border-radius: 9999px; background: transparent; border: none; cursor: pointer;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="1.5"><path d="M10.268 21a2 2 0 0 0 3.464 0"/><path d="M3.262 15.326A1 1 0 0 0 4 17h16a1 1 0 0 0 .74-1.673C19.41 13.956 18 12.499 18 8A6 6 0 0 0 6 8c0 4.499-1.411 5.956-2.738 7.326"/></svg>
            </button>
            <img src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?w=100&h=100&fit=crop" alt="Profile" style="width: 32px; height: 32px; border-radius: 9999px; border: 1px solid rgba(255,255,255,0.1);">
          </div>
        </div>
      </nav>
    `
  },

  // 4. Gradient Border (Glow Style)
  {
    id: 'header-gradient-border',
    label: 'Gradient Border',
    category: 'Headers',
    content: `
      <div style="border-radius: 16px; background: linear-gradient(to right, #4ade80, #facc15, #a855f7, #22d3d8); padding: 4px;">
        <div style="background: rgba(255,255,255,0.95); backdrop-filter: blur(8px); border-radius: 12px;">
          <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 24px;">
            <!-- Logo -->
            <div style="display: flex; align-items: center; gap: 8px;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#f43f5e" stroke-width="1.5"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
              <span style="font-size: 24px; font-weight: 600; color: #111827;">Glow</span>
            </div>
            
            <!-- Nav -->
            <nav style="display: flex; align-items: center; gap: 32px; font-size: 14px; font-weight: 500; color: #4b5563;">
              <a href="#" style="color: #4b5563; text-decoration: none;">Rituals</a>
              <a href="#" style="color: #4b5563; text-decoration: none;">Journal</a>
              <a href="#" style="color: #4b5563; text-decoration: none;">Analytics</a>
              <a href="#" style="color: #4b5563; text-decoration: none;">Community</a>
            </nav>
            
            <!-- Actions -->
            <div style="display: flex; align-items: center; gap: 16px;">
              <button style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #4b5563; background: transparent; border: none; cursor: pointer;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>
                Search
              </button>
              <a href="#" style="padding: 10px 20px; font-size: 14px; font-weight: 600; color: #ffffff; background: #111827; border-radius: 12px; text-decoration: none;">Get Started</a>
            </div>
          </div>
        </div>
      </div>
    `
  },

  // 5. Split Gradient
  {
    id: 'header-split-gradient',
    label: 'Split Gradient',
    category: 'Headers',
    content: `
      <nav style="border-radius: 16px; background: linear-gradient(to right, #4f46e5, #7c3aed, #14b8a6); overflow: hidden;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 20px 24px;">
          <!-- Left: Logo Heavy -->
          <div style="display: flex; align-items: center; gap: 16px;">
            <div style="width: 48px; height: 48px; background: rgba(255,255,255,0.2); backdrop-filter: blur(8px); border-radius: 16px; display: flex; align-items: center; justify-content: center;">
              <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><polygon points="12 2 2 7 12 12 22 7 12 2"/><polyline points="2 17 12 22 22 17"/><polyline points="2 12 12 17 22 12"/></svg>
            </div>
            <div>
              <span style="font-size: 20px; font-weight: 700; color: #ffffff;">Nexus</span>
              <p style="font-size: 12px; color: rgba(255,255,255,0.7); margin: 0;">Platform</p>
            </div>
          </div>
          
          <!-- Center: Nav -->
          <nav style="display: flex; align-items: center; gap: 32px;">
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8); text-decoration: none;">Products</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8); text-decoration: none;">Solutions</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8); text-decoration: none;">Pricing</a>
          </nav>
          
          <!-- Right: CTA -->
          <div style="display: flex; align-items: center; gap: 16px;">
            <a href="#" style="font-size: 14px; font-weight: 500; color: #ffffff; text-decoration: none;">Sign In</a>
            <a href="#" style="padding: 10px 20px; font-size: 14px; font-weight: 600; color: #7c3aed; background: #ffffff; border-radius: 12px; text-decoration: none;">Start Free</a>
          </div>
        </div>
      </nav>
    `
  },

  // 6. Mega Menu
  {
    id: 'header-mega-menu',
    label: 'Mega Menu',
    category: 'Headers',
    content: `
      <nav style="border-radius: 16px; border: 1px solid #e5e7eb; background: #ffffff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 16px 24px;">
          <!-- Logo -->
          <a href="#" style="display: flex; align-items: center; gap: 12px; text-decoration: none;">
            <div style="width: 40px; height: 40px; background: #111827; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/></svg>
            </div>
            <span style="font-size: 20px; font-weight: 700; color: #111827;">GridPro</span>
          </a>
          
          <!-- Desktop Mega Nav -->
          <div style="display: flex; align-items: center; gap: 32px;">
            <div style="position: relative;">
              <button style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; background: transparent; border: none; cursor: pointer; padding: 24px 0;">
                Products
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
              </button>
            </div>
            <a href="#" style="font-size: 14px; font-weight: 500; color: #374151; text-decoration: none;">Pricing</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: #374151; text-decoration: none;">Enterprise</a>
          </div>
          
          <!-- Right Actions -->
          <div style="display: flex; align-items: center; gap: 16px;">
            <button style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #4b5563; background: transparent; border: none; cursor: pointer;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>
            </button>
            <a href="#" style="padding: 10px 20px; font-size: 14px; font-weight: 600; color: #ffffff; background: #111827; border-radius: 12px; text-decoration: none;">Sign In</a>
          </div>
        </div>
      </nav>
    `
  },


  // 7. Brand Statement
  {
    id: 'header-brand-statement',
    label: 'Brand Statement',
    category: 'Headers',
    content: `
      <nav style="border-radius: 16px; background: #ea580c; overflow: hidden;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 24px;">
          <!-- Big Bold Logo -->
          <a href="#" style="display: flex; align-items: center; gap: 16px; text-decoration: none;">
            <div style="width: 56px; height: 56px; background: rgba(255,255,255,0.2); backdrop-filter: blur(8px); border-radius: 16px; display: flex; align-items: center; justify-content: center;">
              <span style="font-size: 30px; font-weight: 700; color: #ffffff;">S</span>
            </div>
            <div>
              <span style="font-size: 30px; font-weight: 700; letter-spacing: -0.025em; color: #ffffff;">STORM</span>
              <p style="font-size: 14px; font-weight: 500; color: #fed7aa; margin: 0;">Creative Studio</p>
            </div>
          </a>
          
          <!-- Minimal Nav -->
          <nav style="display: flex; align-items: center; gap: 32px;">
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8); text-decoration: none;">Work</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8); text-decoration: none;">Services</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8); text-decoration: none;">Team</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.8); text-decoration: none;">Contact</a>
          </nav>
          
          <!-- CTA -->
          <a href="#" style="padding: 10px 20px; font-size: 14px; font-weight: 600; color: #ea580c; background: #ffffff; border-radius: 12px; text-decoration: none;">Let's Talk</a>
        </div>
      </nav>
    `
  },

  // 8. Mobile-First Compact
  {
    id: 'header-mobile-compact',
    label: 'Mobile Compact',
    category: 'Headers',
    content: `
      <nav style="border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); background: rgba(0,0,0,0.5); backdrop-filter: blur(24px); overflow: hidden;">
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 24px;">
          <!-- Logo -->
          <a href="#" style="display: flex; align-items: center; gap: 8px; text-decoration: none;">
            <div style="width: 32px; height: 32px; background: #ffffff; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#000000" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            </div>
            <span style="font-size: 14px; font-weight: 600; color: #ffffff;">Minimal</span>
          </a>
          
          <!-- Menu Button -->
          <button style="padding: 8px; border-radius: 8px; background: transparent; border: none; cursor: pointer;">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M4 5h16M4 12h16M4 19h16"/></svg>
          </button>
        </div>
      </nav>
    `
  },

  // 9. Glassmorphism Heavy
  {
    id: 'header-glassmorphism-heavy',
    label: 'Glassmorphism',
    category: 'Headers',
    content: `
      <nav style="position: relative; border-radius: 24px; border: 1px solid rgba(255,255,255,0.1); background: rgba(255,255,255,0.05); backdrop-filter: blur(24px); overflow: hidden;">
        <!-- Glow effects -->
        <div style="position: absolute; top: 0; left: 25%; width: 256px; height: 256px; background: rgba(168,85,247,0.2); border-radius: 9999px; filter: blur(48px); z-index: -1;"></div>
        <div style="position: absolute; bottom: 0; right: 25%; width: 256px; height: 256px; background: rgba(34,211,238,0.2); border-radius: 9999px; filter: blur(48px); z-index: -1;"></div>
        
        <div style="display: flex; align-items: center; justify-content: space-between; padding: 20px 24px; position: relative;">
          <!-- Logo -->
          <a href="#" style="display: flex; align-items: center; gap: 12px; text-decoration: none;">
            <div style="width: 40px; height: 40px; background: rgba(255,255,255,0.1); backdrop-filter: blur(8px); border-radius: 12px; border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.8)" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20"/></svg>
            </div>
            <span style="font-size: 18px; font-weight: 500; color: #ffffff;">Crystal</span>
          </a>
          
          <!-- Desktop Nav -->
          <nav style="display: flex; align-items: center; gap: 32px;">
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.7); text-decoration: none;">Explore</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.7); text-decoration: none;">Collections</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.7); text-decoration: none;">Creators</a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,0.7); text-decoration: none;">Marketplace</a>
          </nav>
          
          <!-- Actions -->
          <div style="display: flex; align-items: center; gap: 12px;">
            <button style="padding: 10px; border-radius: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>
            </button>
            <button style="padding: 10px; border-radius: 12px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); cursor: pointer;">
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="rgba(255,255,255,0.7)" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </button>
          </div>
        </div>
      </nav>
    `
  },

  // 10. Modern Marketplace (E-Commerce)
  {
    id: 'header-marketplace',
    label: 'Marketplace',
    category: 'Headers',
    content: `
      <header style="background: #ffffff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow: hidden; border: 1px solid #e5e7eb;">
        <!-- Top Utility Bar -->
        <div style="background: #1e293b; padding: 8px 16px; font-size: 12px;">
          <div style="display: flex; justify-content: flex-end; align-items: center; gap: 24px; color: #cbd5e1;">
            <a href="#" style="color: #cbd5e1; text-decoration: none;">Sell on Marketplace</a>
            <a href="#" style="color: #cbd5e1; text-decoration: none;">Help</a>
            <a href="#" style="color: #cbd5e1; text-decoration: none;">Safety & Privacy</a>
          </div>
        </div>
        
        <!-- Main Header Bar -->
        <div style="padding: 16px;">
          <div style="display: flex; align-items: center; gap: 24px;">
            <!-- Logo -->
            <a href="#" style="flex-shrink: 0; text-decoration: none;">
              <span style="font-size: 24px; font-weight: 700; color: #ffffff; background: #0f172a; padding: 6px 12px; border-radius: 4px;">M</span>
            </a>
            
            <!-- Search Bar -->
            <div style="flex: 1; max-width: 768px;">
              <div style="display: flex; border: 2px solid #0f172a; border-radius: 8px; overflow: hidden;">
                <button style="display: flex; align-items: center; gap: 8px; padding: 10px 16px; background: #f3f4f6; border: none; border-right: 1px solid #e5e7eb; font-size: 14px; color: #374151; cursor: pointer;">
                  All
                  <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <input type="text" placeholder="Search products, brands, and more" style="flex: 1; padding: 10px 16px; font-size: 14px; border: none; outline: none;">
                <button style="padding: 0 20px; background: #0f172a; border: none; cursor: pointer;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>
                </button>
              </div>
            </div>
            
            <!-- Right Actions -->
            <div style="display: flex; align-items: center; gap: 16px;">
              <!-- Account -->
              <button style="display: flex; align-items: center; gap: 8px; padding: 8px; background: transparent; border: none; cursor: pointer;">
                <div style="text-align: left;">
                  <div style="font-size: 12px; color: #6b7280;">Hello, sign in</div>
                  <div style="font-size: 14px; font-weight: 600; color: #111827;">Account</div>
                </div>
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </button>
              
              <!-- Returns -->
              <a href="#" style="text-align: left; text-decoration: none;">
                <div style="font-size: 12px; color: #6b7280;">Returns</div>
                <div style="font-size: 14px; font-weight: 600; color: #111827;">& Orders</div>
              </a>
              
              <!-- Cart -->
              <button style="position: relative; padding: 8px; background: transparent; border: none; cursor: pointer;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span style="position: absolute; top: -4px; right: -4px; width: 20px; height: 20px; background: #facc15; color: #0f172a; font-size: 12px; font-weight: 700; border-radius: 9999px; display: flex; align-items: center; justify-content: center;">3</span>
              </button>
            </div>
          </div>
        </div>
        
        <!-- Category Navigation -->
        <div style="border-top: 1px solid #e5e7eb; padding: 10px 16px; background: #f9fafb;">
          <div style="display: flex; align-items: center; gap: 24px; overflow-x: auto; font-size: 14px;">
            <a href="#" style="display: flex; align-items: center; gap: 6px; color: #1f2937; font-weight: 500; text-decoration: none; white-space: nowrap;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#1f2937" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
              Home
            </a>
            <a href="#" style="color: #374151; text-decoration: none; white-space: nowrap;">Today's Deals</a>
            <a href="#" style="color: #374151; text-decoration: none; white-space: nowrap;">Customer Service</a>
            <a href="#" style="color: #374151; text-decoration: none; white-space: nowrap;">Registry</a>
            <a href="#" style="color: #374151; text-decoration: none; white-space: nowrap;">Gift Cards</a>
            <a href="#" style="color: #374151; text-decoration: none; white-space: nowrap;">Sell</a>
            <a href="#" style="color: #374151; text-decoration: none; white-space: nowrap;">Electronics</a>
            <a href="#" style="color: #374151; text-decoration: none; white-space: nowrap;">Fashion</a>
          </div>
        </div>
      </header>
    `
  },

  // 11. Luxury Fashion
  {
    id: 'header-luxury-fashion',
    label: 'Luxury Fashion',
    category: 'Headers',
    content: `
      <header style="border-bottom: 1px solid #e5e7eb; background: #ffffff;">
        <!-- Promo Strip -->
        <div style="background: #111827; color: #ffffff; text-align: center; padding: 10px; font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase;">
          Free Express Shipping on Orders Over $300
        </div>
        
        <!-- Main Header -->
        <div style="background: rgba(255,255,255,0.95); backdrop-filter: blur(8px);">
          <div style="max-width: 1280px; margin: 0 auto; padding: 0 16px;">
            <div style="display: flex; align-items: center; justify-content: space-between; height: 80px;">
              
              <!-- Left Navigation -->
              <nav style="display: flex; align-items: center; gap: 32px;">
                <a href="#" style="font-size: 12px; font-weight: 500; color: #111827; letter-spacing: 0.1em; text-transform: uppercase; text-decoration: none;">Shop</a>
                <a href="#" style="font-size: 12px; font-weight: 500; color: #111827; letter-spacing: 0.1em; text-transform: uppercase; text-decoration: none;">New In</a>
                <a href="#" style="font-size: 12px; font-weight: 500; color: #111827; letter-spacing: 0.1em; text-transform: uppercase; text-decoration: none;">Clothing</a>
              </nav>
              
              <!-- Center Logo -->
              <a href="#" style="flex-shrink: 0; text-decoration: none;">
                <span style="font-size: 30px; font-family: serif; letter-spacing: 0.1em; font-weight: 600; color: #111827;">VELOUR</span>
              </a>
              
              <!-- Right Actions -->
              <div style="display: flex; align-items: center; gap: 24px;">
                <button style="padding: 8px; background: transparent; border: none; border-radius: 9999px; cursor: pointer;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="1.5"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>
                </button>
                <button style="padding: 8px; background: transparent; border: none; border-radius: 9999px; cursor: pointer;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="1.5"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </button>
                <button style="position: relative; padding: 8px; background: transparent; border: none; border-radius: 9999px; cursor: pointer;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="1.5"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                  <span style="position: absolute; top: 0; right: 0; width: 16px; height: 16px; background: #111827; color: #ffffff; font-size: 10px; font-weight: 500; border-radius: 9999px; display: flex; align-items: center; justify-content: center;">2</span>
                </button>
              </div>
            </div>
          </div>
          
          <!-- Secondary Nav -->
          <div style="border-top: 1px solid #f3f4f6;">
            <div style="max-width: 1280px; margin: 0 auto; padding: 0 16px;">
              <nav style="display: flex; align-items: center; justify-content: center; gap: 40px; padding: 12px 0; font-size: 12px; letter-spacing: 0.1em; text-transform: uppercase;">
                <a href="#" style="color: #6b7280; text-decoration: none;">Dresses</a>
                <a href="#" style="color: #6b7280; text-decoration: none;">Tops</a>
                <a href="#" style="color: #6b7280; text-decoration: none;">Denim</a>
                <a href="#" style="color: #6b7280; text-decoration: none;">Outerwear</a>
                <a href="#" style="color: #6b7280; text-decoration: none;">Accessories</a>
                <a href="#" style="color: #6b7280; text-decoration: none;">Sale</a>
              </nav>
            </div>
          </div>
        </div>
      </header>
    `
  },

  // 12. Boutique Shop
  {
    id: 'header-boutique-shop',
    label: 'Boutique Shop',
    category: 'Headers',
    content: `
      <header style="background: #ffffff; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); border: 1px solid #fed7aa; overflow: hidden;">
        <!-- Promo Banner -->
        <div style="background: linear-gradient(to right, #f97316, #f59e0b); color: #ffffff; text-align: center; padding: 10px; font-size: 14px; font-weight: 500;">
          <span>✨ Use code WELCOME15 for 15% off your first order ✨</span>
        </div>
        
        <!-- Main Header -->
        <div style="padding: 16px 24px;">
          <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px;">
            
            <!-- Mobile Menu -->
            <button style="padding: 8px; margin-left: -8px; color: #4b5563; background: transparent; border: none; border-radius: 9999px; cursor: pointer;">
              <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2"><path d="M4 5h16M4 12h16M4 19h16"/></svg>
            </button>
            
            <!-- Logo -->
            <a href="#" style="flex-shrink: 0; text-decoration: none;">
              <span style="font-size: 24px; font-weight: 700; color: #1f2937;">
                <span style="color: #f97316;">❤</span> Handmade Haven
              </span>
            </a>
            
            <!-- Search Bar -->
            <div style="flex: 1; max-width: 640px;">
              <div style="position: relative; width: 100%;">
                <button style="position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: #9ca3af; background: transparent; border: none;">
                  <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.34-4.34"/></svg>
                </button>
                <input type="text" placeholder="Search for unique gifts..." style="width: 100%; padding: 12px 16px 12px 48px; background: #f3f4f6; border-radius: 9999px; font-size: 14px; border: none; outline: none;">
              </div>
            </div>
            
            <!-- Right Actions -->
            <div style="display: flex; align-items: center; gap: 8px;">
              <button style="padding: 12px; background: transparent; border: none; border-radius: 9999px; cursor: pointer;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </button>
              <button style="position: relative; padding: 12px; background: transparent; border: none; border-radius: 9999px; cursor: pointer;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              </button>
              <button style="position: relative; padding: 12px; background: transparent; border: none; border-radius: 9999px; cursor: pointer;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span style="position: absolute; top: 0; right: 0; width: 20px; height: 20px; background: #f97316; color: #ffffff; font-size: 12px; font-weight: 700; border-radius: 9999px; display: flex; align-items: center; justify-content: center;">5</span>
              </button>
            </div>
          </div>
        </div>
        
        <!-- Category Navigation -->
        <div style="border-top: 1px solid #fed7aa; padding: 12px 24px; background: rgba(255,237,213,0.3);">
          <nav style="display: flex; align-items: center; gap: 32px; overflow-x: auto;">
            <a href="#" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; text-decoration: none; white-space: nowrap;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
              Jewelry
            </a>
            <a href="#" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; text-decoration: none; white-space: nowrap;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
              Home Decor
            </a>
            <a href="#" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; text-decoration: none; white-space: nowrap;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
              Art & Prints
            </a>
            <a href="#" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; text-decoration: none; white-space: nowrap;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>
              Ceramics
            </a>
            <a href="#" style="display: flex; align-items: center; gap: 8px; font-size: 14px; font-weight: 500; color: #374151; text-decoration: none; white-space: nowrap;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
              Wellness
            </a>
            <a href="#" style="font-size: 14px; font-weight: 500; color: #ea580c; text-decoration: none; white-space: nowrap;">View All →</a>
          </nav>
        </div>
      </header>
    `
  },
  // 13. The Dark Pill (Agency)
  {
    id: 'header-dark-pill',
    label: 'Dark Pill (Agency)',
    category: 'Headers',
    content: `
      <div style="padding: 20px; display: flex; justify-content: center; font-family: 'Inter', sans-serif;">
        <nav style="background: #111; color: #fff; padding: 8px 8px 8px 24px; border-radius: 100px; display: flex; align-items: center; gap: 40px; box-shadow: 0 20px 40px rgba(0,0,0,0.2);">
          <div style="font-weight: 700; font-size: 1.2rem; letter-spacing: -1px;">A.<span style="color:#666">Agency</span></div>
          <div style="display: flex; gap: 24px;">
            <a href="#" style="text-decoration: none; color: #999; font-size: 0.9rem; font-weight: 500; transition: 0.2s;">Work</a>
            <a href="#" style="text-decoration: none; color: #999; font-size: 0.9rem; font-weight: 500; transition: 0.2s;">About</a>
            <a href="#" style="text-decoration: none; color: #999; font-size: 0.9rem; font-weight: 500; transition: 0.2s;">Playground</a>
            <a href="#" style="text-decoration: none; color: #999; font-size: 0.9rem; font-weight: 500; transition: 0.2s;">Resource</a>
          </div>
          <a href="#" style="background: #fff; color: #000; padding: 10px 24px; border-radius: 50px; text-decoration: none; font-weight: 600; font-size: 0.9rem; transition: 0.2s;">Let's Talk</a>
        </nav>
      </div>
    `
  },
  // 14. The Light Pill (SaaS)
  {
    id: 'header-light-pill',
    label: 'Light Pill (SaaS)',
    category: 'Headers',
    content: `
      <div style="padding: 20px; display: flex; justify-content: center; font-family: 'Inter', sans-serif;">
        <nav style="background: #fff; color: #111; padding: 8px 8px 8px 24px; border-radius: 100px; display: flex; align-items: center; gap: 40px; box-shadow: 0 10px 30px rgba(0,0,0,0.05);">
          <div style="font-weight: 800; font-size: 1.2rem; display: flex; align-items: center; gap: 5px;"><i class="ri-triangle-fill"></i> Prism</div>
          <div style="display: flex; gap: 24px;">
            <a href="#" style="text-decoration: none; color: #555; font-size: 0.9rem; font-weight: 500; transition: 0.2s;">Features</a>
            <a href="#" style="text-decoration: none; color: #555; font-size: 0.9rem; font-weight: 500; transition: 0.2s;">Pricing</a>
            <a href="#" style="text-decoration: none; color: #555; font-size: 0.9rem; font-weight: 500; transition: 0.2s;">Blog</a>
          </div>
          <a href="#" style="background: #111; color: #fff; padding: 10px 24px; border-radius: 50px; text-decoration: none; font-weight: 600; font-size: 0.9rem;">Get Started</a>
        </nav>
      </div>
    `
  },
  // 15. The Dashboard Floater
  {
    id: 'header-dashboard-floater',
    label: 'Dashboard Floater',
    category: 'Headers',
    content: `
      <div style="padding: 20px; display: flex; justify-content: center; background: #0f172a; font-family: 'Inter', sans-serif;">
        <nav style="background: #fff; width: 100%; max-width: 900px; padding: 10px 20px; border-radius: 12px; display: flex; justify-content: space-between; align-items: center;">
          <div style="display: flex; align-items: center; gap: 30px;">
            <div style="width: 32px; height: 32px; background: #3b82f6; border-radius: 6px; display: grid; place-items: center; color: white;"><i class="ri-dashboard-fill"></i></div>
            <div style="display: flex; gap: 20px;">
              <a href="#" style="text-decoration: none; color: #0f172a; font-weight: 600; font-size: 0.9rem;">Overview</a>
              <a href="#" style="text-decoration: none; color: #64748b; font-weight: 500; font-size: 0.9rem;">Analytics</a>
              <a href="#" style="text-decoration: none; color: #64748b; font-weight: 500; font-size: 0.9rem;">Settings</a>
            </div>
          </div>
          <div style="display: flex; align-items: center; gap: 15px;">
            <i class="ri-search-line" style="color: #64748b; font-size: 1.2rem; cursor: pointer;"></i>
            <div style="width: 32px; height: 32px; border-radius: 50%; background: #ddd; overflow: hidden;"></div>
            <a href="#" style="background: #1e293b; color: white; padding: 8px 16px; border-radius: 6px; font-size: 0.85rem; font-weight: 600; text-decoration: none; display: flex; align-items: center; gap: 5px;"><i class="ri-flashlight-fill"></i> Upgrade</a>
          </div>
        </nav>
      </div>
    `
  },
  // 16. The Gradient Border (Web3)
  {
    id: 'header-web3-gradient',
    label: 'Web3 Gradient',
    category: 'Headers',
    content: `
      <div style="background: #000; padding: 30px; display: flex; justify-content: center; font-family: 'Inter', sans-serif;">
        <nav style="background: rgba(255,255,255,0.05); backdrop-filter: blur(10px); padding: 10px 10px 10px 30px; border-radius: 100px; border: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 40px;">
          <div style="font-weight: 700; color: #fff; font-size: 1.2rem; letter-spacing: 1px;">NEXUS</div>
          <div style="display: flex; gap: 24px;">
            <a href="#" style="text-decoration: none; color: #aaa; font-size: 0.9rem; transition: 0.3s;">Exchange</a>
            <a href="#" style="text-decoration: none; color: #aaa; font-size: 0.9rem; transition: 0.3s;">Marketplace</a>
            <a href="#" style="text-decoration: none; color: #aaa; font-size: 0.9rem; transition: 0.3s;">Wallet</a>
          </div>
          <a href="#" style="position: relative; background: #000; color: #fff; text-decoration: none; padding: 10px 24px; border-radius: 50px; font-weight: 600; font-size: 0.9rem; display: flex; align-items: center; justify-content: center; border: 2px solid transparent; background-image: linear-gradient(#000, #000), linear-gradient(45deg, #ff00cc, #3333ff); background-origin: border-box; background-clip: content-box, border-box;">Connect</a>
        </nav>
      </div>
    `
  },
  // 17. The Clean E-Commerce (Purillas)
  {
    id: 'header-clean-ecommerce',
    label: 'Clean E-Commerce',
    category: 'Headers',
    content: `
      <nav style="background: #fdfdfd; padding: 24px 40px; display: flex; justify-content: space-between; align-items: center; font-family: 'Space Grotesk', sans-serif;">
        <div style="font-weight: 700; font-size: 1.5rem; letter-spacing: -1px; color: #111;">Purillas®</div>
        <div style="display: flex; gap: 30px;">
          <a href="#" style="text-decoration: none; color: #333; font-weight: 500; font-size: 1rem; display: flex; align-items: center; gap: 4px;">Shop</a>
          <a href="#" style="text-decoration: none; color: #333; font-weight: 500; font-size: 1rem; display: flex; align-items: center; gap: 4px;">New Arrivals</a>
          <a href="#" style="text-decoration: none; color: #333; font-weight: 500; font-size: 1rem; display: flex; align-items: center; gap: 4px;">Sustainability</a>
        </div>
        <div style="background: #111; color: white; padding: 12px 24px; border-radius: 50px; display: flex; align-items: center; gap: 8px; font-weight: 600; cursor: pointer;">
          <i class="ri-shopping-bag-3-fill"></i> My bag (0)
        </div>
      </nav>
    `
  },
  // 18. The Transparent Minimal (Aerix)
  {
    id: 'header-transparent-minimal',
    label: 'Transparent Minimal',
    category: 'Headers',
    content: `
      <div style="background: url('https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?q=80&w=2000&auto=format&fit=crop') center/cover; padding: 20px; font-family: 'Inter', sans-serif;">
        <nav style="display: flex; justify-content: space-between; align-items: center; padding: 10px 20px; color: #fff;">
          <div style="font-size: 1.4rem; font-weight: 500; letter-spacing: -0.5px; opacity: 0.9;">Aerix</div>
          <div style="display: flex; gap: 40px;">
            <a href="#" style="text-decoration: none; color: rgba(255,255,255,0.7); font-size: 0.9rem; transition: 0.2s;">Overview</a>
            <a href="#" style="text-decoration: none; color: rgba(255,255,255,0.7); font-size: 0.9rem; transition: 0.2s;">Specs</a>
            <a href="#" style="text-decoration: none; color: rgba(255,255,255,0.7); font-size: 0.9rem; transition: 0.2s;">Support</a>
          </div>
          <div style="display: flex; gap: 20px; align-items: center;">
            <a href="#" style="background: rgba(255,255,255,0.2); backdrop-filter: blur(10px); color: #fff; padding: 8px 20px; border-radius: 30px; text-decoration: none; font-size: 0.85rem; font-weight: 500;">Log In</a>
            <a href="#" style="background: #fff; color: #000; padding: 8px 20px; border-radius: 30px; text-decoration: none; font-size: 0.85rem; font-weight: 600; display: flex; align-items: center; gap: 5px;">Add Device <i class="ri-add-line"></i></a>
          </div>
        </nav>
      </div>
    `
  },
  // 19. The Corporate Mega
  {
    id: 'header-corporate-mega',
    label: 'Corporate Mega',
    category: 'Headers',
    content: `
      <nav style="background: #fff; border-bottom: 2px solid #0044cc; font-family: 'Inter', sans-serif;">
        <div style="background: #f8f9fa; padding: 8px 5%; display: flex; justify-content: flex-end; gap: 20px; font-size: 0.75rem; color: #555;">
          <span>Investors</span>
          <span>Careers</span>
          <span>Global Locations</span>
        </div>
        <div style="padding: 0 5%; height: 70px; display: flex; justify-content: space-between; align-items: center;">
          <div style="font-weight: 800; font-size: 1.4rem; color: #0044cc; display: flex; align-items: center; gap: 5px;"><i class="ri-shield-star-fill"></i> STRATOS</div>
          <div style="display: flex; height: 100%;">
            <div style="padding: 0 24px; display: flex; align-items: center; font-weight: 600; color: #333; cursor: pointer; position: relative; height: 100%;">Solutions <i class="ri-arrow-down-s-line" style="margin-left: 5px; font-size: 0.8rem; color: #999;"></i></div>
            <div style="padding: 0 24px; display: flex; align-items: center; font-weight: 600; color: #333; cursor: pointer; position: relative; height: 100%;">Industries <i class="ri-arrow-down-s-line" style="margin-left: 5px; font-size: 0.8rem; color: #999;"></i></div>
            <div style="padding: 0 24px; display: flex; align-items: center; font-weight: 600; color: #333; cursor: pointer; position: relative; height: 100%;">Insights</div>
          </div>
          <a href="#" style="background: #0044cc; color: white; padding: 10px 24px; border-radius: 4px; text-decoration: none; font-weight: 600; font-size: 0.9rem;">Contact Sales</a>
        </div>
      </nav>
    `
  },
  // 20. The Glassmorphic Blur
  {
    id: 'header-glassmorphic-blur',
    label: 'Glassmorphic Blur',
    category: 'Headers',
    content: `
      <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 40px 20px; display: flex; justify-content: center; font-family: 'Inter', sans-serif;">
        <nav style="background: rgba(255, 255, 255, 0.1); backdrop-filter: blur(20px); border: 1px solid rgba(255, 255, 255, 0.2); padding: 15px 30px; border-radius: 20px; display: flex; justify-content: space-between; align-items: center; width: 100%; max-width: 1000px;">
          <div style="color: #fff; font-weight: 700; font-size: 1.2rem; display: flex; align-items: center; gap: 8px;"><i class="ri-bubble-chart-fill"></i> FLUID</div>
          <div style="display: flex; gap: 30px;">
            <a href="#" style="text-decoration: none; color: rgba(255,255,255,0.8); font-weight: 500; font-size: 0.95rem; transition: 0.2s;">Product</a>
            <a href="#" style="text-decoration: none; color: rgba(255,255,255,0.8); font-weight: 500; font-size: 0.95rem; transition: 0.2s;">Docs</a>
            <a href="#" style="text-decoration: none; color: rgba(255,255,255,0.8); font-weight: 500; font-size: 0.95rem; transition: 0.2s;">Community</a>
          </div>
          <a href="#" style="background: rgba(255,255,255,0.2); border: 1px solid rgba(255,255,255,0.4); color: #fff; padding: 10px 24px; border-radius: 12px; text-decoration: none; font-weight: 600; transition: 0.2s;">Launch App</a>
        </nav>
      </div>
    `
  },
  // 21. The Brutalist Grid
  {
    id: 'header-brutalist-grid',
    label: 'Brutalist Grid',
    category: 'Headers',
    content: `
      <nav style="background: #fff; border-top: 3px solid #000; border-bottom: 3px solid #000; display: flex; font-family: 'Syne', sans-serif;">
        <div style="padding: 20px 40px; border-right: 3px solid #000; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 1.5rem; background: #ffff00;">GRID.SYS</div>
        <div style="flex: 1; display: flex; align-items: center; gap: 40px; padding: 20px 40px; border-right: 3px solid #000;">
          <a href="#" style="text-decoration: none; color: #000; font-weight: 700; text-transform: uppercase; font-size: 1rem;">Index</a>
          <a href="#" style="text-decoration: none; color: #000; font-weight: 700; text-transform: uppercase; font-size: 1rem;">Archive</a>
          <a href="#" style="text-decoration: none; color: #000; font-weight: 700; text-transform: uppercase; font-size: 1rem;">Info</a>
        </div>
        <div style="padding: 20px 40px; background: #000; color: #fff; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; cursor: pointer; display: flex; align-items: center; justify-content: center;">GET TICKETS</div>
      </nav>
    `
  },
  // 22. The Search Focused
  {
    id: 'header-search-focused',
    label: 'Search Focused',
    category: 'Headers',
    content: `
      <nav style="background: #131921; color: #fff; padding: 10px 20px; display: flex; align-items: center; gap: 20px; font-family: 'Outfit', sans-serif;">
        <div style="font-weight: 700; font-size: 1.4rem; letter-spacing: -0.5px;">market.</div>
        <div style="display: flex; flex-direction: column; font-size: 0.75rem; color: #ccc; line-height: 1.2; cursor: pointer;">
          <span>Deliver to</span>
          <b style="color: #fff; font-size: 0.9rem;">New York 10001</b>
        </div>
        <div style="flex: 1; display: flex; height: 40px; border-radius: 4px; overflow: hidden;">
          <select style="background: #f3f3f3; border: none; padding: 0 10px; border-right: 1px solid #ddd; color: #555; font-size: 0.8rem;"><option>All</option></select>
          <input type="text" placeholder="Search essentials..." style="flex: 1; padding: 0 15px; border: none; outline: none;">
          <button style="background: #febd69; border: none; width: 45px; cursor: pointer; font-size: 1.2rem;"><i class="ri-search-line"></i></button>
        </div>
        <div style="display: flex; gap: 20px; align-items: center;">
          <div style="display: flex; flex-direction: column; font-size: 0.75rem; color: #fff; line-height: 1.2; cursor: pointer;">
            <span>Hello, Sign in</span>
            <b style="font-size: 0.9rem;">Account</b>
          </div>
          <div style="display: flex; flex-direction: column; font-size: 0.75rem; color: #fff; line-height: 1.2; cursor: pointer;">
            <span>Returns</span>
            <b style="font-size: 0.9rem;">& Orders</b>
          </div>
          <div style="display: flex; align-items: flex-end; gap: 5px; font-weight: 700;">
            <i class="ri-shopping-cart-2-line" style="font-size: 1.8rem;"></i> 0
          </div>
        </div>
      </nav>
    `
  },
  // 23. The Architect (Minimalist)
  {
    id: 'header-architect-minimalist',
    label: 'Architect Minimalist',
    category: 'Headers',
    content: `
      <nav style="background: #fff; padding: 30px 50px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #000; font-family: 'Inter', sans-serif;">
        <div style="font-weight: 400; font-size: 1.5rem; letter-spacing: 2px; text-transform: uppercase; border: 1px solid #000; padding: 5px 15px;">STUDIO_A</div>
        <div style="display: flex; gap: 60px;">
          <a href="#" style="text-decoration: none; color: #000; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px;">Selected Works</a>
          <a href="#" style="text-decoration: none; color: #000; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px;">Philosophy</a>
          <a href="#" style="text-decoration: none; color: #000; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 1px;">Contact</a>
        </div>
        <div style="font-size:1.5rem; cursor:pointer"><i class="ri-menu-4-line"></i></div>
      </nav>
    `
  },
  // 24. The Social Centric
  {
    id: 'header-social-centric',
    label: 'Social Centric',
    category: 'Headers',
    content: `
      <nav style="background: #fff; padding: 0 40px; height: 80px; display: grid; grid-template-columns: 1fr 1fr 1fr; align-items: center; border-bottom: 1px solid #eee; font-family: 'Inter', sans-serif;">
        <div style="display: flex; gap: 30px;">
          <a href="#" style="text-decoration: none; color: #111; font-weight: 600; font-size: 0.9rem;">Style</a>
          <a href="#" style="text-decoration: none; color: #111; font-weight: 600; font-size: 0.9rem;">Culture</a>
          <a href="#" style="text-decoration: none; color: #111; font-weight: 600; font-size: 0.9rem;">Living</a>
        </div>
        <div style="text-align: center; font-family: 'Playfair Display', serif; font-size: 2rem; font-style: italic; font-weight: 700;">The Edit.</div>
        <div style="display: flex; justify-content: flex-end; gap: 20px;">
          <a href="#" style="color: #111; font-size: 1.2rem; text-decoration: none;"><i class="ri-instagram-line"></i></a>
          <a href="#" style="color: #111; font-size: 1.2rem; text-decoration: none;"><i class="ri-twitter-x-line"></i></a>
          <a href="#" style="color: #111; font-size: 1.2rem; text-decoration: none;"><i class="ri-pinterest-line"></i></a>
        </div>
      </nav>
    `
  },
  // 25. The Island Dynamic
  {
    id: 'header-island-dynamic',
    label: 'Island Dynamic',
    category: 'Headers',
    content: `
      <div style="padding: 20px; display: flex; justify-content: center; font-family: 'Inter', sans-serif;">
        <nav style="background: rgba(0,0,0,0.8); backdrop-filter: blur(10px); padding: 8px 10px; border-radius: 16px; display: flex; gap: 5px;">
          <a href="#" style="background: #fff; color: #000; font-weight: 600; text-decoration: none; padding: 8px 16px; border-radius: 12px; font-size: 0.85rem;">Home</a>
          <a href="#" style="color: #ccc; text-decoration: none; padding: 8px 16px; border-radius: 12px; font-size: 0.85rem;">Work</a>
          <a href="#" style="color: #ccc; text-decoration: none; padding: 8px 16px; border-radius: 12px; font-size: 0.85rem;">Notes</a>
          <a href="#" style="color: #ccc; text-decoration: none; padding: 8px 16px; border-radius: 12px; font-size: 0.85rem;">About</a>
        </nav>
      </div>
    `
  },
  // 26. The Retro Pop
  {
    id: 'header-retro-pop',
    label: 'Retro Pop',
    category: 'Headers',
    content: `
      <nav style="background: #fffbf0; border-bottom: 3px solid #000; padding: 20px 40px; display: flex; justify-content: space-between; align-items: center; font-family: 'Inter', sans-serif;">
        <div style="font-family: 'Syne', sans-serif; font-size: 1.8rem; font-weight: 800; color: #ff6b6b; text-shadow: 2px 2px 0px #000;">WOOP!</div>
        <div style="display: flex; gap: 20px;">
          <a href="#" style="background: #4ecdc4; border: 2px solid #000; padding: 10px 20px; border-radius: 8px; color: #000; text-decoration: none; font-weight: 700; box-shadow: 4px 4px 0px #000;">Pricing</a>
          <a href="#" style="background: #ffe66d; border: 2px solid #000; padding: 10px 20px; border-radius: 8px; color: #000; text-decoration: none; font-weight: 700; box-shadow: 4px 4px 0px #000;">Book Demo</a>
        </div>
      </nav>
    `
  },
  // 27. The Luxury Stacked
  {
    id: 'header-luxury-stacked',
    label: 'Luxury Stacked',
    category: 'Headers',
    content: `
      <nav style="background: #0c0c0c; color: #d4af37; padding: 30px 0; text-align: center; font-family: 'Cinzel', serif;">
        <div style="font-size: 2.5rem; letter-spacing: 2px; margin-bottom: 20px; display: inline-block; border-bottom: 1px solid #d4af37; padding-bottom: 10px;">REGALIA</div>
        <div style="display: flex; justify-content: center; gap: 50px; font-family: 'Inter', sans-serif; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 2px;">
          <a href="#" style="text-decoration: none; color: #999;">Haute Couture</a>
          <a href="#" style="text-decoration: none; color: #999;">Fine Jewelry</a>
          <a href="#" style="text-decoration: none; color: #999;">Maison</a>
        </div>
      </nav>
    `
  },
  // 28. The Split Screen
  {
    id: 'header-split-screen',
    label: 'Split Screen',
    category: 'Headers',
    content: `
      <nav style="padding: 40px 60px; display: flex; justify-content: space-between; align-items: center; font-family: 'Inter', sans-serif;">
        <div style="font-weight: 800; font-size: 1.5rem; letter-spacing: -1px; color: #111;">BOLD.</div>
        <div style="width: 50px; height: 50px; background: #111; border-radius: 50%; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 4px; cursor: pointer;">
          <div style="width: 20px; height: 2px; background: #fff;"></div>
          <div style="width: 20px; height: 2px; background: #fff;"></div>
          <div style="width: 20px; height: 2px; background: #fff;"></div>
        </div>
      </nav>
    `
  },
  // 29. The Cyber Glitch
  {
    id: 'header-cyber-glitch',
    label: 'Cyber Glitch',
    category: 'Headers',
    content: `
      <nav style="background: #000; border-bottom: 1px solid #333; padding: 20px 40px; display: flex; justify-content: space-between; align-items: center; font-family: 'Space Grotesk', monospace;">
        <div style="color: #0f0; font-size: 1.5rem; font-weight: 700; text-transform: uppercase; letter-spacing: 2px; text-shadow: 2px 2px 0px rgba(0,255,0,0.3);">PROTOCOL_</div>
        <div style="display: flex; gap: 30px;">
          <a href="#" style="color: #fff; text-decoration: none; text-transform: uppercase; font-size: 0.9rem;">Mission</a>
          <a href="#" style="color: #fff; text-decoration: none; text-transform: uppercase; font-size: 0.9rem;">Operatives</a>
          <a href="#" style="color: #fff; text-decoration: none; text-transform: uppercase; font-size: 0.9rem;">Database</a>
        </div>
        <button style="background: transparent; color: #0f0; border: 1px solid #0f0; padding: 10px 20px; text-transform: uppercase; font-weight: 700; cursor: pointer; box-shadow: 0 0 10px rgba(0,255,0,0.2);">Access</button>
      </nav>
    `
  },
  // 30. The Vertical Sidebar
  {
    id: 'header-vertical-sidebar',
    label: 'Vertical Sidebar',
    category: 'Headers',
    content: `
      <div style="height: 400px; position: relative; background: #fff; display: flex; font-family: 'Inter', sans-serif; border: 1px solid #eee; border-radius: 12px; overflow: hidden;">
        <div style="width: 260px; background: #111; color: #fff; padding: 30px; display: flex; flex-direction: column; justify-content: space-between; height: 100%;">
          <div>
            <div style="font-weight: 700; font-size: 1.2rem; letter-spacing: -0.5px; margin-bottom: 40px;">Linear</div>
            <ul style="list-style: none; display: flex; flex-direction: column; gap: 15px; padding: 0;">
              <li><a href="#" style="text-decoration: none; color: #fff; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;"><i class="ri-inbox-line"></i> Inbox</a></li>
              <li><a href="#" style="text-decoration: none; color: #888; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;"><i class="ri-task-line"></i> My Issues</a></li>
              <li><a href="#" style="text-decoration: none; color: #888; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;"><i class="ri-group-line"></i> Team</a></li>
              <li><a href="#" style="text-decoration: none; color: #888; display: flex; align-items: center; gap: 10px; font-size: 0.9rem;"><i class="ri-bar-chart-line"></i> Insights</a></li>
            </ul>
          </div>
          <div style="display: flex; align-items: center; gap: 10px; margin-top: auto; padding-top: 20px; border-top: 1px solid #333;">
            <div style="width: 32px; height: 32px; background: #333; border-radius: 50%;"></div>
            <span style="font-size: 0.85rem">Alex Morgan</span>
          </div>
        </div>
        <div style="flex: 1; padding: 40px; background: #f4f4f5;">
          <h2 style="margin-bottom:10px; color: #111;">Dashboard</h2>
          <p style="color:#666; font-size:0.9rem;">Content area goes here...</p>
        </div>
      </div>
    `
  },
]
