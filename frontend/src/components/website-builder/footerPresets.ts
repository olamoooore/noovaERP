// Footer presets - 12 industry-specific designs
// Uses inline SVG icons with explicit stroke/fill colors for visibility in GrapesJS canvas

export const footerPresets = [
  // 1. Ecommerce Footer (ShopZone)
  {
    id: 'footer-ecommerce',
    label: 'Ecommerce',
    category: 'Footers',
    content: `
      <footer class="bg-slate-900 text-slate-300 py-16" id="footer-ecommerce">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid grid-cols-2 md:grid-cols-5 gap-8 mb-12">
            <div class="col-span-2 md:col-span-1">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-xl flex items-center justify-center">
                  <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                </div>
                <span class="text-2xl font-bold text-white">ShopZone</span>
              </div>
              <p class="text-slate-400 mb-6">Your one-stop destination for premium products. Quality guaranteed with fast shipping worldwide.</p>
              <div class="flex gap-4">
                <a href="#" class="w-10 h-10 bg-slate-800 rounded-full flex items-center justify-center hover:bg-indigo-600 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-slate-800 rounded-full flex items-center justify-center hover:bg-sky-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-slate-800 rounded-full flex items-center justify-center hover:bg-pink-600 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
              </div>
            </div>
            <div>
              <h4 class="text-white font-semibold mb-4">Shop</h4>
              <ul class="space-y-3">
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">New Arrivals</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Best Sellers</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Men's Fashion</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Women's Fashion</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Accessories</a></li>
              </ul>
            </div>
            <div>
              <h4 class="text-white font-semibold mb-4">Support</h4>
              <ul class="space-y-3">
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Help Center</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Track Order</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Shipping Info</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Returns</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Contact Us</a></li>
              </ul>
            </div>
            <div>
              <h4 class="text-white font-semibold mb-4">Company</h4>
              <ul class="space-y-3">
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">About Us</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Careers</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Press</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Sustainability</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Affiliate Program</a></li>
              </ul>
            </div>
            <div>
              <h4 class="text-white font-semibold mb-4">Newsletter</h4>
              <p class="text-slate-400 mb-4">Subscribe for 10% off your first order</p>
              <div class="flex gap-2">
                <input type="email" placeholder="Email address" class="flex-1 px-4 py-3 bg-slate-800 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                <button class="px-4 py-3 bg-indigo-600 text-white rounded-xl hover:bg-indigo-700 transition-colors"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
              </div>
            </div>
          </div>
          <div class="border-t border-slate-800 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
              <div class="flex flex-wrap items-center justify-center gap-6 text-sm">
                <span class="text-slate-500">Payment Methods:</span>
                <span class="px-3 py-1 bg-slate-800 rounded text-slate-300 text-xs">VISA</span>
                <span class="px-3 py-1 bg-slate-800 rounded text-slate-300 text-xs">MC</span>
                <span class="px-3 py-1 bg-slate-800 rounded text-slate-300 text-xs">PayPal</span>
                <span class="px-3 py-1 bg-slate-800 rounded text-slate-300 text-xs">Apple Pay</span>
              </div>
              <div class="flex flex-wrap items-center justify-center gap-6 text-sm">
                <span class="flex items-center gap-2 text-slate-400"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#22c55e" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Secure Checkout</span>
                <span class="flex items-center gap-2 text-slate-400"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#60a5fa" stroke-width="2"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg> Fast Shipping</span>
              </div>
            </div>
            <div class="flex flex-wrap justify-center gap-6 mt-6 text-sm text-slate-500">
              <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
              <a href="#" class="hover:text-white transition-colors">Terms of Service</a>
              <a href="#" class="hover:text-white transition-colors">Cookie Policy</a>
              <a href="#" class="hover:text-white transition-colors">Accessibility</a>
            </div>
            <p class="text-center text-slate-600 text-sm mt-6">© 2025 ShopZone. All rights reserved.</p>
          </div>
        </div>
      </footer>
    `
  },

  // 2. SaaS Footer (CloudFlow)
  {
    id: 'footer-saas',
    label: 'SaaS',
    category: 'Footers',
    content: `
      <footer class="bg-indigo-950 text-slate-300 py-16" id="footer-saas">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid md:grid-cols-2 gap-12 mb-12">
            <div>
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-gradient-to-br from-indigo-500 to-purple-500 rounded-xl flex items-center justify-center">
                  <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                </div>
                <span class="text-2xl font-bold text-white">CloudFlow</span>
              </div>
              <p class="text-slate-400 mb-6 max-w-md">Empowering teams to build better software, faster. Join 50,000+ companies delivering at scale.</p>
              <div class="flex items-center gap-4 mb-6">
                <div class="flex items-center gap-2 px-4 py-2 bg-green-500/20 border border-green-500/30 rounded-full">
                  <span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
                  <span class="text-green-400 text-sm">All Systems Operational</span>
                </div>
              </div>
              <div class="flex gap-4">
                <a href="#" class="px-6 py-3 bg-indigo-600 text-white rounded-xl flex items-center gap-2 hover:bg-indigo-700 transition-colors"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="#ffffff"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09l.01-.01zM12.03 7.25c-.15-2.23 1.66-4.07 3.74-4.25.29 2.58-2.34 4.5-3.74 4.25z"/></svg> App Store</a>
                <a href="#" class="px-6 py-3 bg-slate-800 border border-slate-700 text-white rounded-xl flex items-center gap-2 hover:bg-slate-700 transition-colors"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="#ffffff"><path d="M3.609 1.814L13.792 12 3.61 22.186a.996.996 0 01-.619-.236V2.05a1 1 0 01.609-.92zm10.89 10.893l2.302 2.302-10.937 6.333 8.635-8.635zm3.199-3.198l2.807 1.626a1 1 0 010 1.73l-2.808 1.626L15.206 12l2.492-2.491zM5.864 2.658L16.8 9.49l-2.302 2.302-8.634-8.634z"/></svg> Google Play</a>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-8">
              <div>
                <h4 class="text-white font-semibold mb-4">Product</h4>
                <ul class="space-y-3">
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Features</a></li>
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Integrations</a></li>
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Pricing</a></li>
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Changelog</a></li>
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Documentation</a></li>
                </ul>
              </div>
              <div>
                <h4 class="text-white font-semibold mb-4">Company</h4>
                <ul class="space-y-3">
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">About</a></li>
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Blog</a></li>
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Careers</a></li>
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Press</a></li>
                  <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Contact</a></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="border-t border-indigo-900/50 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
              <p class="text-slate-500 text-sm">© 2025 CloudFlow Inc. All rights reserved.</p>
              <div class="flex gap-6 text-sm">
                <a href="#" class="text-slate-400 hover:text-white transition-colors">Privacy</a>
                <a href="#" class="text-slate-400 hover:text-white transition-colors">Terms</a>
                <a href="#" class="text-slate-400 hover:text-white transition-colors">Cookies</a>
                <a href="#" class="text-slate-400 hover:text-white transition-colors">Security</a>
              </div>
            </div>
          </div>
        </div>
      </footer>
    `
  },

  // 3. Corporate Footer (Nexus Enterprises)
  {
    id: 'footer-corporate',
    label: 'Corporate',
    category: 'Footers',
    content: `
      <footer class="bg-slate-50 text-slate-800 py-16" id="footer-corporate">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid grid-cols-2 md:grid-cols-6 gap-8 mb-12">
            <div class="col-span-2 md:col-span-1">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-slate-900 rounded-xl flex items-center justify-center">
                  <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                </div>
                <div>
                  <span class="text-xl font-bold text-slate-900">Nexus</span>
                  <p class="text-xs text-slate-500">Enterprises</p>
                </div>
              </div>
              <p class="text-slate-600 mb-6 text-sm">Global leader in innovative business solutions since 1995.</p>
              <div class="flex gap-3">
                <a href="#" class="w-9 h-9 bg-slate-200 rounded-full flex items-center justify-center hover:bg-slate-900 transition-all"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg></a>
                <a href="#" class="w-9 h-9 bg-slate-200 rounded-full flex items-center justify-center hover:bg-slate-900 transition-all"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg></a>
                <a href="#" class="w-9 h-9 bg-slate-200 rounded-full flex items-center justify-center hover:bg-slate-900 transition-all"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
              </div>
            </div>
            <div>
              <h4 class="text-slate-900 font-semibold mb-4">Solutions</h4>
              <ul class="space-y-2 text-sm">
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Enterprise</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Small Business</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Government</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Healthcare</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Finance</a></li>
              </ul>
            </div>
            <div>
              <h4 class="text-slate-900 font-semibold mb-4">Products</h4>
              <ul class="space-y-2 text-sm">
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Analytics</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Cloud Services</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Security</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">AI Solutions</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">IoT Platform</a></li>
              </ul>
            </div>
            <div>
              <h4 class="text-slate-900 font-semibold mb-4">Resources</h4>
              <ul class="space-y-2 text-sm">
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Documentation</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Help Center</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">API Reference</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Developer Portal</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Community</a></li>
              </ul>
            </div>
            <div>
              <h4 class="text-slate-900 font-semibold mb-4">Company</h4>
              <ul class="space-y-2 text-sm">
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">About Us</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Investor Relations</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Leadership</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Newsroom</a></li>
                <li><a href="#" class="text-slate-600 hover:text-slate-900 transition-colors">Partners</a></li>
              </ul>
            </div>
            <div>
              <h4 class="text-slate-900 font-semibold mb-4">Contact</h4>
              <ul class="space-y-2 text-sm">
                <li class="flex items-center gap-2 text-slate-600"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> 1-800-NEXUS</li>
                <li class="flex items-center gap-2 text-slate-600"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> info@nexus.com</li>
                <li class="flex items-center gap-2 text-slate-600"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> Global HQ, New York</li>
              </ul>
            </div>
          </div>
          <div class="border-t border-slate-200 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
              <div class="flex flex-wrap items-center justify-center gap-6 text-sm text-slate-500">
                <span>English</span>
                <span class="w-1 h-1 bg-slate-300 rounded-full"></span>
                <span class="flex items-center gap-2"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="#475569"><path d="M12 22C6.477 22 2 17.523 2 12S6.477 2 12 2s10 4.477 10 10-4.477 10-10 10zm-1-7v2h2v-2h-2zm0-8v6h2V7h-2z"/></svg> ISO 27001 Certified</span>
                <span class="w-1 h-1 bg-slate-300 rounded-full"></span>
                <span class="flex items-center gap-2"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> SOC 2 Compliant</span>
              </div>
              <div class="flex gap-6 text-sm text-slate-500">
                <a href="#" class="hover:text-slate-900 transition-colors">Privacy Policy</a>
                <a href="#" class="hover:text-slate-900 transition-colors">Terms of Service</a>
                <a href="#" class="hover:text-slate-900 transition-colors">Cookie Settings</a>
              </div>
            </div>
            <p class="text-center text-slate-500 text-sm mt-6">© 2025 Nexus Enterprises. All rights reserved.</p>
          </div>
        </div>
      </footer>
    `
  },

  // 4. Blog Footer (The Journal)
  {
    id: 'footer-blog',
    label: 'Blog',
    category: 'Footers',
    content: `
      <footer class="bg-stone-50 text-stone-700 py-16" id="footer-blog">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid md:grid-cols-3 gap-12 mb-12">
            <div class="md:col-span-1">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-stone-800 rounded-xl flex items-center justify-center">
                  <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg>
                </div>
                <div>
                  <span class="text-2xl font-serif font-bold text-stone-800">The Journal</span>
                  <p class="text-xs text-stone-500">Est. 2018</p>
                </div>
              </div>
              <p class="text-stone-600 mb-6">Stories that matter. Perspectives that inspire. Join our community of curious minds.</p>
              <div class="p-4 bg-white rounded-xl border border-stone-200">
                <p class="font-serif italic text-stone-600 text-sm mb-3">"The Journal has become my morning ritual. Quality content that respects my time."</p>
                <div class="flex items-center gap-3">
                  <div class="w-10 h-10 bg-gradient-to-br from-amber-400 to-orange-500 rounded-full"></div>
                  <div>
                    <p class="font-medium text-stone-800 text-sm">Sarah Mitchell</p>
                    <p class="text-stone-500 text-xs">Reader since 2020</p>
                  </div>
                </div>
              </div>
            </div>
            <div>
              <h4 class="font-serif font-semibold text-stone-800 mb-4">Popular Categories</h4>
              <div class="flex flex-wrap gap-2 mb-6">
                <a href="#" class="px-3 py-1 bg-white border border-stone-300 rounded-full text-stone-600 text-sm hover:bg-stone-800 hover:text-white transition-colors">Technology</a>
                <a href="#" class="px-3 py-1 bg-white border border-stone-300 rounded-full text-stone-600 text-sm hover:bg-stone-800 hover:text-white transition-colors">Culture</a>
                <a href="#" class="px-3 py-1 bg-white border border-stone-300 rounded-full text-stone-600 text-sm hover:bg-stone-800 hover:text-white transition-colors">Design</a>
                <a href="#" class="px-3 py-1 bg-white border border-stone-300 rounded-full text-stone-600 text-sm hover:bg-stone-800 hover:text-white transition-colors">Science</a>
                <a href="#" class="px-3 py-1 bg-white border border-stone-300 rounded-full text-stone-600 text-sm hover:bg-stone-800 hover:text-white transition-colors">Business</a>
                <a href="#" class="px-3 py-1 bg-white border border-stone-300 rounded-full text-stone-600 text-sm hover:bg-stone-800 hover:text-white transition-colors">Travel</a>
              </div>
              <h4 class="font-serif font-semibold text-stone-800 mb-4">Editor's Picks</h4>
              <ul class="space-y-3">
                <li><a href="#" class="text-stone-600 hover:text-stone-800 text-sm transition-colors">The Future of Artificial Intelligence</a></li>
                <li><a href="#" class="text-stone-600 hover:text-stone-800 text-sm transition-colors">Sustainable Living in Urban Spaces</a></li>
                <li><a href="#" class="text-stone-600 hover:text-stone-800 text-sm transition-colors">The Art of Slow Living</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-serif font-semibold text-stone-800 mb-4">Stay Connected</h4>
              <p class="text-stone-600 mb-4 text-sm">Get the best stories delivered to your inbox every morning.</p>
              <div class="flex gap-2 mb-6">
                <input type="email" placeholder="Your email" class="flex-1 px-4 py-3 bg-white border border-stone-300 rounded-xl text-stone-800 placeholder-stone-400 focus:outline-none focus:ring-2 focus:ring-stone-500">
                <button class="px-6 py-3 bg-stone-800 text-white rounded-xl hover:bg-stone-900 transition-colors">Subscribe</button>
              </div>
              <h4 class="font-serif font-semibold text-stone-800 mb-4">Follow Us</h4>
              <div class="flex gap-3">
                <a href="#" class="w-10 h-10 bg-stone-200 rounded-full flex items-center justify-center hover:bg-stone-800 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#57534e" stroke-width="2"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-stone-200 rounded-full flex items-center justify-center hover:bg-stone-800 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#57534e" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
                <a href="#" class="w-10 h-10 bg-stone-200 rounded-full flex items-center justify-center hover:bg-stone-800 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#57534e" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-stone-200 rounded-full flex items-center justify-center hover:bg-stone-800 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#57534e" stroke-width="2"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg></a>
              </div>
            </div>
          </div>
          <div class="border-t border-stone-200 pt-8 text-center text-sm text-stone-500">
            <p>© 2025 The Journal. All rights reserved. | <a href="#" class="hover:text-stone-800 transition-colors">Privacy Policy</a> | <a href="#" class="hover:text-stone-800 transition-colors">Terms of Service</a> | <a href="#" class="hover:text-stone-800 transition-colors">Contact Us</a></p>
          </div>
        </div>
      </footer>
    `
  },

  // 5. Social/Community Footer
  {
    id: 'footer-social',
    label: 'Social/Community',
    category: 'Footers',
    content: `
      <footer class="bg-gradient-to-br from-purple-600 to-pink-500 text-white py-16" id="footer-social">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid md:grid-cols-2 gap-12 mb-12">
            <div>
              <div class="flex items-center gap-3 mb-6">
                <div class="w-14 h-14 bg-white/20 rounded-2xl flex items-center justify-center" style="backdrop-filter: blur(8px);">
                  <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                </div>
                <div>
                  <span class="text-3xl font-bold">Community</span>
                  <p class="text-white/70">Join 100,000+ members</p>
                </div>
              </div>
              <p class="text-white/80 mb-8 max-w-md text-lg">Connect, share, and grow with a community that cares. Your voice matters here.</p>
              <div class="flex flex-wrap gap-4">
                <a href="#" class="px-8 py-4 bg-white text-purple-600 rounded-full font-semibold flex items-center gap-2 hover:shadow-xl transition-all">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="#9333ea"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg>
                  Join Discord
                </a>
                <a href="#" class="px-8 py-4 bg-white/10 border border-white/30 text-white rounded-full font-semibold hover:bg-white/20 transition-all" style="backdrop-filter: blur(8px);">
                  Become a Member
                </a>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-8">
              <div>
                <h4 class="font-semibold mb-4">Explore</h4>
                <ul class="space-y-3">
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Discussions</a></li>
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Events</a></li>
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Resources</a></li>
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Showcase</a></li>
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Jobs Board</a></li>
                </ul>
              </div>
              <div>
                <h4 class="font-semibold mb-4">Connect</h4>
                <ul class="space-y-3">
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">About Us</a></li>
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Contact</a></li>
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Sponsorship</a></li>
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Press Kit</a></li>
                  <li><a href="#" class="text-white/70 hover:text-white transition-colors">Newsletter</a></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="border-t border-white/20 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
              <div class="flex items-center gap-4">
                <div class="flex -space-x-3">
                  <div class="w-10 h-10 rounded-full bg-gradient-to-br from-yellow-400 to-orange-500 border-2 border-white"></div>
                  <div class="w-10 h-10 rounded-full bg-gradient-to-br from-blue-400 to-purple-500 border-2 border-white"></div>
                  <div class="w-10 h-10 rounded-full bg-gradient-to-br from-green-400 to-teal-500 border-2 border-white"></div>
                  <div class="w-10 h-10 rounded-full bg-gradient-to-br from-pink-400 to-rose-500 border-2 border-white flex items-center justify-center text-xs font-bold">+98</div>
                </div>
                <span class="text-white/70 text-sm">Join our active community</span>
              </div>
              <div class="flex gap-6">
                <a href="#" class="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center hover:bg-white hover:text-purple-600 transition-all" style="backdrop-filter: blur(8px);"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="#ffffff"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028 14.09 14.09 0 0 0 1.226-1.994.076.076 0 0 0-.041-.106 13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127c-.6.3-1.2.54-1.8.72z"/></svg></a>
                <a href="#" class="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center hover:bg-white hover:text-pink-600 transition-all" style="backdrop-filter: blur(8px);"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="#ffffff"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073zm0 5.838c-3.403 0-6.162 2.759-6.162 6.162s2.759 6.163 6.162 6.163 6.162-2.759 6.162-6.163c0-3.403-2.759-6.162-6.162-6.162zm0 10.162c-2.209 0-4-1.79-4-4 0-2.209 1.791-4 4-4s4 1.791 4 4c0 2.21-1.791 4-4 4zm6.406-11.845c-.796 0-1.441.645-1.441 1.44s.645 1.44 1.441 1.44c.795 0 1.439-.645 1.439-1.44s-.644-1.44-1.439-1.44z"/></svg></a>
                <a href="#" class="w-12 h-12 bg-white/10 rounded-full flex items-center justify-center hover:bg-white hover:text-blue-400 transition-all" style="backdrop-filter: blur(8px);"><svg class="w-6 h-6" viewBox="0 0 24 24" fill="#ffffff"><path d="M23.953 4.57a10 10 0 01-2.825.775 4.958 4.958 0 002.163-2.723c-.951.555-2.005.959-3.127 1.184a4.92 4.92 0 00-8.384 4.482C7.69 8.095 4.067 6.13 1.64 3.162a4.822 4.822 0 00-.666 2.475c0 1.71.87 3.213 2.188 4.096a4.904 4.904 0 01-2.228-.616v.06a4.923 4.923 0 003.946 4.827 4.996 4.996 0 01-2.212.085 4.936 4.936 0 004.604 3.417 9.867 9.867 0 01-6.102 2.105c-.39 0-.779-.023-1.17-.067a13.995 13.995 0 007.557 2.209c9.053 0 13.998-7.496 13.998-13.985 0-.21 0-.42-.015-.63A9.935 9.935 0 0024 4.59z"/></svg></a>
              </div>
            </div>
            <p class="text-center text-white/50 text-sm mt-8">© 2025 Community. Made with ❤️ for our members.</p>
          </div>
        </div>
      </footer>
    `
  },

  // 6. Portfolio Footer (John Designer)
  {
    id: 'footer-portfolio',
    label: 'Portfolio',
    category: 'Footers',
    content: `
      <footer class="bg-black text-white py-16" id="footer-portfolio">
        <div class="max-w-7xl mx-auto px-6">
          <div class="mb-16">
            <p class="text-slate-500 text-sm uppercase tracking-widest mb-4">Available for new projects</p>
            <h2 class="text-5xl md:text-7xl font-bold mb-8 leading-tight">
              Let's create<br>
              <span class="text-slate-600">something amazing</span>
            </h2>
            <a href="mailto:hello@johndesigner.com" class="inline-flex items-center gap-4 text-2xl md:text-3xl font-semibold hover:text-slate-300 transition-colors">
              hello@johndesigner.com
              <span class="w-12 h-12 border border-slate-600 rounded-full flex items-center justify-center"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M5 12h14M12 5l7 7-7 7"/></svg></span>
            </a>
          </div>
          <div class="grid md:grid-cols-4 gap-12 mb-12">
            <div class="md:col-span-2">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-slate-800 rounded-xl flex items-center justify-center">
                  <span class="text-xl font-bold">JD</span>
                </div>
                <span class="text-xl font-bold">John Designer</span>
              </div>
              <p class="text-slate-400 mb-6 max-w-sm">Creative director and designer based in New York. Specializing in brand identity, digital design, and art direction.</p>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-slate-300">Social</h4>
              <ul class="space-y-3">
                <li><a href="#" class="text-slate-400 hover:text-white flex items-center gap-2 transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg> LinkedIn</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white flex items-center gap-2 transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg> Instagram</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white flex items-center gap-2 transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="#94a3b8"><path d="M12 0C5.374 0 0 5.373 0 12c0 5.302 3.438 9.8 8.207 11.387.599.111.793-.261.793-.577v-2.234c-3.338.726-4.033-1.416-4.033-1.416-.546-1.387-1.333-1.756-1.333-1.756-1.089-.745.083-.729.083-.729 1.205.084 1.839 1.237 1.839 1.237 1.07 1.834 2.807 1.304 3.492.997.107-.775.418-1.305.762-1.604-2.665-.305-5.467-1.334-5.467-5.931 0-1.311.469-2.381 1.236-3.221-.124-.303-.535-1.524.117-3.176 0 0 1.008-.322 3.301 1.23A11.509 11.509 0 0112 5.803c1.02.005 2.047.138 3.006.404 2.291-1.552 3.297-1.23 3.297-1.23.653 1.653.242 2.874.118 3.176.77.84 1.235 1.911 1.235 3.221 0 4.609-2.807 5.624-5.479 5.921.43.372.823 1.102.823 2.222v3.293c0 .319.192.694.801.576C20.566 21.797 24 17.3 24 12c0-6.627-5.373-12-12-12z"/></svg> GitHub</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white flex items-center gap-2 transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg> Twitter</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-slate-300">Menu</h4>
              <ul class="space-y-3">
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Work</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">About</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Services</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Blog</a></li>
                <li><a href="#" class="text-slate-400 hover:text-white transition-colors">Contact</a></li>
              </ul>
            </div>
          </div>
          <div class="border-t border-slate-800 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 text-sm text-slate-500">
              <p>© 2025 John Designer. All rights reserved.</p>
              <div class="flex gap-6">
                <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
                <a href="#" class="hover:text-white transition-colors">Terms of Service</a>
              </div>
            </div>
          </div>
        </div>
      </footer>
    `
  },

  // 7. Restaurant Footer (La Bella)
  {
    id: 'footer-restaurant',
    label: 'Restaurant',
    category: 'Footers',
    content: `
      <footer class="bg-amber-950 text-amber-50 py-16" id="footer-restaurant">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid md:grid-cols-3 gap-12 mb-12">
            <div>
              <div class="flex items-center gap-3 mb-6">
                <div class="w-14 h-14 bg-amber-600 rounded-xl flex items-center justify-center">
                  <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M18 8h1a4 4 0 0 1 0 8h-1M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" y1="1" x2="6" y2="4"/><line x1="10" y1="1" x2="10" y2="4"/><line x1="14" y1="1" x2="14" y2="4"/></svg>
                </div>
                <div>
                  <span class="text-3xl font-bold">La Bella</span>
                  <p class="text-amber-200 text-sm">Italian Kitchen</p>
                </div>
              </div>
              <p class="text-amber-200/80 mb-6">Authentic Italian cuisine in the heart of downtown. Family recipes passed down for three generations.</p>
              <a href="#" class="inline-flex items-center gap-2 px-6 py-3 border-2 border-amber-400 text-amber-400 rounded-xl hover:bg-amber-400 hover:text-amber-950 transition-colors">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#fbbf24" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Get Directions
              </a>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-white">Opening Hours</h4>
              <ul class="space-y-3 text-amber-200/80">
                <li class="flex justify-between"><span>Monday - Thursday</span><span>11:00 AM - 10:00 PM</span></li>
                <li class="flex justify-between"><span>Friday - Saturday</span><span>11:00 AM - 11:00 PM</span></li>
                <li class="flex justify-between"><span>Sunday</span><span>10:00 AM - 9:00 PM</span></li>
              </ul>
              <div class="mt-6 pt-6 border-t border-amber-200/20">
                <h4 class="font-semibold mb-3 text-white">Location</h4>
                <p class="text-amber-200/80 text-sm">123 Gourmet Street<br>New York, NY 10001</p>
              </div>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-white">Quick Links</h4>
              <ul class="space-y-3 mb-6">
                <li><a href="#" class="text-amber-200/80 hover:text-white transition-colors">Our Menu</a></li>
                <li><a href="#" class="text-amber-200/80 hover:text-white transition-colors">Make a Reservation</a></li>
                <li><a href="#" class="text-amber-200/80 hover:text-white transition-colors">Private Events</a></li>
                <li><a href="#" class="text-amber-200/80 hover:text-white transition-colors">Catering Services</a></li>
                <li><a href="#" class="text-amber-200/80 hover:text-white transition-colors">Gift Cards</a></li>
              </ul>
              <h4 class="font-semibold mb-3 text-white">Connect</h4>
              <div class="flex gap-3">
                <a href="#" class="w-10 h-10 bg-amber-900/50 rounded-full flex items-center justify-center hover:bg-amber-400 hover:text-amber-950 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#fde68a" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-amber-900/50 rounded-full flex items-center justify-center hover:bg-amber-400 hover:text-amber-950 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#fde68a" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
                <a href="#" class="w-10 h-10 bg-amber-900/50 rounded-full flex items-center justify-center hover:bg-amber-400 hover:text-amber-950 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#fde68a" stroke-width="2"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg></a>
              </div>
            </div>
          </div>
          <div class="border-t border-amber-200/20 pt-8 text-center text-amber-200/60 text-sm">
            <p>© 2025 La Bella Italian Kitchen. All rights reserved. | <a href="#" class="hover:text-white transition-colors">Privacy Policy</a> | <a href="#" class="hover:text-white transition-colors">Terms of Service</a></p>
          </div>
        </div>
      </footer>
    `
  },

  // 8. Travel Footer (Wanderlust)
  {
    id: 'footer-travel',
    label: 'Travel',
    category: 'Footers',
    content: `
      <footer class="bg-sky-900 text-white py-16" id="footer-travel">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid md:grid-cols-4 gap-8 mb-12">
            <div class="md:col-span-1">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-teal-500 rounded-xl flex items-center justify-center">
                  <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                </div>
                <span class="text-2xl font-bold">Wanderlust</span>
              </div>
              <p class="text-teal-100 mb-6">Your journey begins here. Discover the world's most breathtaking destinations.</p>
              <div class="flex gap-3">
                <select class="px-3 py-2 bg-white/10 border border-white/20 rounded-lg text-sm text-white">
                  <option>USD ($)</option>
                  <option>EUR (€)</option>
                  <option>GBP (£)</option>
                </select>
                <select class="px-3 py-2 bg-white/10 border border-white/20 rounded-lg text-sm text-white">
                  <option>English</option>
                  <option>Español</option>
                  <option>Français</option>
                </select>
              </div>
            </div>
            <div>
              <h4 class="font-semibold mb-4">Destinations</h4>
              <ul class="space-y-3 text-teal-100">
                <li><a href="#" class="hover:text-white transition-colors">Europe Tours</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Asia Adventures</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Caribbean Escapes</a></li>
                <li><a href="#" class="hover:text-white transition-colors">African Safari</a></li>
                <li><a href="#" class="hover:text-white transition-colors">South America</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Oceania Wonders</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4">Travel Styles</h4>
              <ul class="space-y-3 text-teal-100">
                <li><a href="#" class="hover:text-white transition-colors">Luxury Retreats</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Budget Backpacking</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Family Vacations</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Solo Travel</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Honeymoons</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Group Tours</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4">Stay Updated</h4>
              <p class="text-teal-100 mb-4 text-sm">Get exclusive travel deals and inspiration.</p>
              <div class="flex gap-2 mb-6">
                <input type="email" placeholder="Your email" class="flex-1 px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-teal-200 focus:outline-none focus:ring-2 focus:ring-teal-400">
                <button class="px-4 py-3 bg-teal-500 text-white rounded-xl hover:bg-teal-600 transition-colors"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg></button>
              </div>
              <h4 class="font-semibold mb-3">Follow Us</h4>
              <div class="flex gap-3">
                <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex items-center justify-center hover:bg-teal-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex items-center justify-center hover:bg-teal-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
                <a href="#" class="w-10 h-10 bg-white/10 rounded-full flex items-center justify-center hover:bg-teal-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
              </div>
            </div>
          </div>
          <div class="border-t border-teal-700/50 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6 text-sm text-teal-200">
              <p>© 2025 Wanderlust Travel. All rights reserved.</p>
              <div class="flex gap-6">
                <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
                <a href="#" class="hover:text-white transition-colors">Terms of Service</a>
                <a href="#" class="hover:text-white transition-colors">Travel Insurance</a>
                <a href="#" class="hover:text-white transition-colors">FAQs</a>
              </div>
            </div>
          </div>
        </div>
      </footer>
    `
  },

  // 9. Real Estate Footer (Luxury Estates)
  {
    id: 'footer-realestate',
    label: 'Real Estate',
    category: 'Footers',
    content: `
      <footer class="bg-blue-950 text-white py-16" id="footer-realestate">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid md:grid-cols-4 gap-8 mb-12">
            <div class="md:col-span-1">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-12 h-12 bg-amber-600 rounded-xl flex items-center justify-center">
                  <svg class="w-7 h-7" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                </div>
                <div>
                  <span class="text-2xl font-bold">Luxury</span>
                  <p class="text-amber-500 text-sm">Estates</p>
                </div>
              </div>
              <p class="text-slate-300 mb-6">Your trusted partner in finding the perfect property. Excellence in real estate since 1985.</p>
              <div class="flex gap-3">
                <a href="#" class="w-10 h-10 bg-slate-800 rounded-full flex items-center justify-center hover:bg-amber-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg></a>
                <a href="#" class="w-10 h-10 bg-slate-800 rounded-full flex items-center justify-center hover:bg-amber-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-slate-800 rounded-full flex items-center justify-center hover:bg-amber-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
              </div>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-amber-500">Properties</h4>
              <ul class="space-y-3">
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Luxury Homes</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Penthouses</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Villas</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Apartments</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Commercial</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Land</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-amber-500">Locations</h4>
              <ul class="space-y-3">
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">New York</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Los Angeles</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Miami</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">San Francisco</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">London</a></li>
                <li><a href="#" class="text-slate-300 hover:text-white transition-colors">Dubai</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-amber-500">Contact</h4>
              <ul class="space-y-3 text-slate-300">
                <li class="flex items-center gap-2"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg> 1-800-LUXURY</li>
                <li class="flex items-center gap-2"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg> info@luxuryestates.com</li>
                <li class="flex items-center gap-2"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg> 100 Park Avenue, NY</li>
              </ul>
              <a href="#" class="mt-6 inline-block px-6 py-3 bg-amber-600 text-white font-semibold rounded-xl hover:bg-amber-500 transition-colors">Find Your Home</a>
            </div>
          </div>
          <div class="border-t border-slate-700 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6 text-sm text-slate-400">
              <div class="flex flex-wrap items-center justify-center gap-4">
                <span class="text-amber-500">Licensed & Insured</span>
                <span class="w-1 h-1 bg-slate-600 rounded-full"></span>
                <span>Each Office Independently Owned & Operated</span>
              </div>
              <div class="flex gap-6">
                <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
                <a href="#" class="hover:text-white transition-colors">Terms of Service</a>
                <a href="#" class="hover:text-white transition-colors">Equal Housing</a>
              </div>
            </div>
            <p class="text-center text-slate-500 text-sm mt-6">© 2025 Luxury Estates. All rights reserved.</p>
          </div>
        </div>
      </footer>
    `
  },

  // 10. Non-Profit Footer (Hope Foundation)
  {
    id: 'footer-nonprofit',
    label: 'Non-Profit',
    category: 'Footers',
    content: `
      <footer class="bg-green-800 text-white py-16" id="footer-nonprofit">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid md:grid-cols-2 gap-12 mb-12">
            <div>
              <div class="flex items-center gap-3 mb-6">
                <div class="w-14 h-14 bg-green-100 rounded-2xl flex items-center justify-center">
                  <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </div>
                <div>
                  <span class="text-3xl font-bold">Hope</span>
                  <p class="text-green-200 text-sm">Foundation</p>
                </div>
              </div>
              <p class="text-green-100 mb-8 max-w-md">Empowering communities worldwide through education, healthcare, and sustainable development. Together, we can make a difference.</p>
              <a href="#" class="inline-flex items-center gap-2 px-8 py-4 bg-white text-green-800 font-semibold rounded-full hover:shadow-xl transition-all">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#166534" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                Donate Now
              </a>
            </div>
            <div class="grid grid-cols-2 gap-8">
              <div>
                <h4 class="font-semibold mb-4">Our Work</h4>
                <ul class="space-y-3 text-green-100">
                  <li><a href="#" class="hover:text-white transition-colors">Education Programs</a></li>
                  <li><a href="#" class="hover:text-white transition-colors">Healthcare Initiatives</a></li>
                  <li><a href="#" class="hover:text-white transition-colors">Clean Water</a></li>
                  <li><a href="#" class="hover:text-white transition-colors">Sustainable Farming</a></li>
                  <li><a href="#" class="hover:text-white transition-colors">Emergency Relief</a></li>
                </ul>
              </div>
              <div>
                <h4 class="font-semibold mb-4">Get Involved</h4>
                <ul class="space-y-3 text-green-100">
                  <li><a href="#" class="hover:text-white transition-colors">Volunteer</a></li>
                  <li><a href="#" class="hover:text-white transition-colors">Careers</a></li>
                  <li><a href="#" class="hover:text-white transition-colors">Partner With Us</a></li>
                  <li><a href="#" class="hover:text-white transition-colors">Fundraise</a></li>
                  <li><a href="#" class="hover:text-white transition-colors">Newsletter</a></li>
                </ul>
              </div>
            </div>
          </div>
          <div class="border-t border-green-700/50 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6">
              <div class="flex flex-wrap items-center justify-center gap-6">
                <div class="flex items-center gap-2 px-4 py-2 bg-white/10 rounded-full">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#86efac" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                  <span class="text-green-100 text-sm">501(c)(3) Non-Profit</span>
                </div>
                <span class="flex items-center gap-2 text-green-300 text-sm"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="#fbbf24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg> Charity Navigator 4-Star Rating</span>
              </div>
              <div class="flex gap-6 text-sm text-green-200">
                <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
                <a href="#" class="hover:text-white transition-colors">Terms of Service</a>
                <a href="#" class="hover:text-white transition-colors">Transparency</a>
                <a href="#" class="hover:text-white transition-colors">Contact</a>
              </div>
            </div>
            <p class="text-center text-green-400 text-sm mt-6">© 2025 Hope Foundation. All rights reserved.</p>
          </div>
        </div>
      </footer>
    `
  },

  // 11. Government Footer (Digital Services)
  {
    id: 'footer-government',
    label: 'Government',
    category: 'Footers',
    content: `
      <footer class="bg-slate-100 text-blue-900 py-16" id="footer-government">
        <div class="max-w-7xl mx-auto px-6">
          <div class="grid md:grid-cols-4 gap-8 mb-12">
            <div class="md:col-span-1">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-14 h-14 bg-blue-800 rounded-lg flex items-center justify-center">
                  <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
                </div>
                <div>
                  <span class="text-xl font-bold">Department of</span>
                  <p class="text-blue-800/70 text-sm">Digital Services</p>
                </div>
              </div>
              <p class="text-slate-600 mb-6 text-sm">Serving the public with excellence, integrity, and accountability.</p>
              <div class="flex flex-col gap-2 text-sm">
                <div class="flex items-center gap-2 text-red-600 font-medium">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#dc2626" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                  Emergency: 911
                </div>
                <div class="flex items-center gap-2 text-slate-600">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                  1-800-GOV-HELP
                </div>
              </div>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-slate-900">Services</h4>
              <ul class="space-y-2 text-sm text-slate-600">
                <li><a href="#" class="hover:text-blue-800 transition-colors">Online Services</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Permits & Licenses</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Records & Forms</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Public Notices</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">FAQs</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-slate-900">Resources</h4>
              <ul class="space-y-2 text-sm text-slate-600">
                <li><a href="#" class="hover:text-blue-800 transition-colors">Agency Directory</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Laws & Regulations</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Data & Statistics</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Jobs & Careers</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Media & Press</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-slate-900">Connect</h4>
              <ul class="space-y-2 text-sm text-slate-600">
                <li><a href="#" class="hover:text-blue-800 transition-colors">Contact Us</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Office Locations</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Subscribe</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Report an Issue</a></li>
                <li><a href="#" class="hover:text-blue-800 transition-colors">Feedback</a></li>
              </ul>
            </div>
          </div>
          <div class="bg-white border-t border-slate-200 rounded-xl p-6 mb-8">
            <div class="grid md:grid-cols-3 gap-6 text-center md:text-left">
              <div class="flex items-center justify-center md:justify-start gap-3">
                <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg>
                <div>
                  <p class="text-xs text-slate-500">Official Seal</p>
                  <p class="font-medium text-slate-900">Verified Government</p>
                </div>
              </div>
              <div class="flex items-center justify-center md:justify-start gap-3">
                <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/></svg>
                <div>
                  <p class="text-xs text-slate-500">Accessibility</p>
                  <p class="font-medium text-slate-900">WCAG 2.1 Compliant</p>
                </div>
              </div>
              <div class="flex items-center justify-center md:justify-start gap-3">
                <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="#1e40af" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                <div>
                  <p class="text-xs text-slate-500">Security</p>
                  <p class="font-medium text-slate-900">Secure & Encrypted</p>
                </div>
              </div>
            </div>
          </div>
          <div class="border-t border-slate-200 pt-6">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4 text-sm text-slate-500">
              <div class="flex flex-wrap items-center justify-center gap-4">
                <span>© 2025 Government Department</span>
                <span class="w-1 h-1 bg-slate-300 rounded-full"></span>
                <span>All Rights Reserved</span>
              </div>
              <div class="flex flex-wrap items-center justify-center gap-4">
                <a href="#" class="hover:text-blue-800 flex items-center gap-1 transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#475569" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Accessibility Statement</a>
                <a href="#" class="hover:text-blue-800 transition-colors">Privacy Policy</a>
                <a href="#" class="hover:text-blue-800 transition-colors">Terms of Service</a>
              </div>
            </div>
          </div>
        </div>
      </footer>
    `
  },

  // 12. Entertainment/Gaming Footer (STREAM)
  {
    id: 'footer-entertainment',
    label: 'Entertainment',
    category: 'Footers',
    content: `
      <footer class="bg-slate-950 text-white py-16" id="footer-entertainment">
        <div class="max-w-7xl mx-auto px-6">
          <div class="overflow-hidden mb-12 py-4 border-y border-white/10">
            <div class="flex whitespace-nowrap" style="animation: marquee 20s linear infinite;">
              <span class="text-2xl font-bold mx-8 text-purple-500">NEW EPISODE FRIDAY</span>
              <span class="text-2xl font-bold mx-8 text-pink-500">LIVE EVENTS</span>
              <span class="text-2xl font-bold mx-8 text-cyan-400">MERCH DROP</span>
              <span class="text-2xl font-bold mx-8 text-purple-500">SEASON 5</span>
              <span class="text-2xl font-bold mx-8 text-pink-500">COMMUNITY CHALLENGE</span>
              <span class="text-2xl font-bold mx-8 text-cyan-400">EXCLUSIVE CONTENT</span>
              <span class="text-2xl font-bold mx-8 text-purple-500">NEW EPISODE FRIDAY</span>
              <span class="text-2xl font-bold mx-8 text-pink-500">LIVE EVENTS</span>
            </div>
          </div>
          <div class="grid md:grid-cols-4 gap-8 mb-12">
            <div class="md:col-span-1">
              <div class="flex items-center gap-3 mb-6">
                <div class="w-14 h-14 bg-gradient-to-br from-purple-500 to-pink-500 rounded-2xl flex items-center justify-center">
                  <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>
                </div>
                <span class="text-2xl font-bold">STREAM</span>
              </div>
              <p class="text-slate-400 mb-6">Your ultimate destination for entertainment, gaming, and live events.</p>
              <div class="flex gap-3">
                <a href="#" class="w-10 h-10 bg-purple-600 rounded-full flex items-center justify-center hover:bg-purple-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="#ffffff"><path d="M20.317 4.37a19.791 19.791 0 0 0-4.885-1.515.074.074 0 0 0-.079.037c-.21.375-.444.864-.608 1.25a18.27 18.27 0 0 0-5.487 0 12.64 12.64 0 0 0-.617-1.25.077.077 0 0 0-.079-.037A19.736 19.736 0 0 0 3.677 4.37a.07.07 0 0 0-.032.027C.533 9.046-.32 13.58.099 18.057a.082.082 0 0 0 .031.057 19.9 19.9 0 0 0 5.993 3.03.078.078 0 0 0 .084-.028c.462-.63.874-1.295 1.226-1.994.021-.041.001-.09-.041-.106a13.107 13.107 0 0 1-1.872-.892.077.077 0 0 1-.008-.128 10.2 10.2 0 0 0 .372-.292.074.074 0 0 1 .077-.01c3.928 1.793 8.18 1.793 12.062 0a.074.074 0 0 1 .078.01c.12.098.246.198.373.292a.077.077 0 0 1-.006.127 12.299 12.299 0 0 1-1.873.892.077.077 0 0 0-.041.107c.36.698.772 1.362 1.225 1.993a.076.076 0 0 0 .084.028 19.839 19.839 0 0 0 6.002-3.03.077.077 0 0 0 .032-.054c.5-5.177-.838-9.674-3.549-13.66a.061.061 0 0 0-.031-.03zM8.02 15.33c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.956-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.956 2.418-2.157 2.418zm7.975 0c-1.183 0-2.157-1.085-2.157-2.419 0-1.333.955-2.419 2.157-2.419 1.21 0 2.176 1.096 2.157 2.42 0 1.333-.946 2.418-2.157 2.418z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-red-600 rounded-full flex items-center justify-center hover:bg-red-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="#ffffff"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center hover:bg-green-400 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="#ffffff"><path d="M12 0C5.374 0 0 5.373 0 12s5.374 12 12 12 12-5.373 12-12S18.626 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02zm1.44-3.3c-.301.42-.841.6-1.262.3-3.239-1.98-8.159-2.58-11.939-1.38-.479.12-1.02-.12-1.14-.6-.12-.48.12-1.021.6-1.141C9.6 9.9 15 10.561 18.72 12.84c.361.181.54.78.241 1.2zm.12-3.36C15.24 8.4 8.82 8.16 5.16 9.301c-.6.179-1.2-.181-1.38-.721-.18-.601.18-1.2.72-1.381 4.26-1.26 11.28-1.02 15.721 1.621.539.3.719 1.02.419 1.56-.299.421-1.02.599-1.559.3z"/></svg></a>
                <a href="#" class="w-10 h-10 bg-pink-600 rounded-full flex items-center justify-center hover:bg-pink-500 transition-all"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="#ffffff"><path d="M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zm0-2.163c-3.259 0-3.667.014-4.947.072-4.358.2-6.78 2.618-6.98 6.98-.059 1.281-.073 1.689-.073 4.948 0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98 1.281.058 1.689.072 4.948.072 3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98-1.281-.059-1.69-.073-4.949-.073z"/></svg></a>
              </div>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-purple-500">Shows</h4>
              <ul class="space-y-3 text-slate-400">
                <li><a href="#" class="hover:text-white transition-colors">Original Series</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Live Events</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Podcasts</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Documentaries</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Behind the Scenes</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-pink-500">Community</h4>
              <ul class="space-y-3 text-slate-400">
                <li><a href="#" class="hover:text-white transition-colors">Fan Club</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Forums</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Events</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Challenges</a></li>
                <li><a href="#" class="hover:text-white transition-colors">Discord</a></li>
              </ul>
            </div>
            <div>
              <h4 class="font-semibold mb-4 text-cyan-400">Stream On</h4>
              <div class="grid grid-cols-2 gap-3">
                <a href="#" class="flex items-center gap-2 px-4 py-2 bg-slate-800 rounded-lg text-sm hover:bg-slate-700 transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="#ef4444"><path d="M23.498 6.186a3.016 3.016 0 0 0-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 0 0 .502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 0 0 2.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 0 0 2.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814z"/></svg>
                  YouTube
                </a>
                <a href="#" class="flex items-center gap-2 px-4 py-2 bg-slate-800 rounded-lg text-sm hover:bg-slate-700 transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="#a855f7"><path d="M11.571 4.714h1.715v5.143H11.57zm4.715 0H18v5.143h-1.714zM6 0L1.714 4.286v15.428h5.143V24l4.286-4.286h3.428L22.286 12V0zm14.571 11.143l-3.428 3.428h-3.429l-3 3v-3H6.857V1.714h13.714z"/></svg>
                  Twitch
                </a>
                <a href="#" class="flex items-center gap-2 px-4 py-2 bg-slate-800 rounded-lg text-sm hover:bg-slate-700 transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="#22c55e"><path d="M12 0C5.374 0 0 5.373 0 12s5.374 12 12 12 12-5.373 12-12S18.626 0 12 0zm5.521 17.34c-.24.359-.66.48-1.021.24-2.82-1.74-6.36-2.101-10.561-1.141-.418.122-.779-.179-.899-.539-.12-.421.18-.78.54-.9 4.56-1.021 8.52-.6 11.64 1.32.42.18.479.659.301 1.02z"/></svg>
                  Spotify
                </a>
                <a href="#" class="flex items-center gap-2 px-4 py-2 bg-slate-800 rounded-lg text-sm hover:bg-slate-700 transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="#3b82f6"><path d="M17.05 20.28c-.98.95-2.05.8-3.08.35-1.09-.46-2.09-.48-3.24 0-1.44.62-2.2.44-3.06-.35C2.79 15.25 3.51 7.59 9.05 7.31c1.35.07 2.29.74 3.08.8 1.18-.24 2.31-.93 3.57-.84 1.51.12 2.65.72 3.4 1.8-3.12 1.87-2.38 5.98.48 7.13-.57 1.5-1.31 2.99-2.54 4.09l.01-.01z"/></svg>
                  Apple
                </a>
              </div>
            </div>
          </div>
          <div class="border-t border-white/10 pt-8">
            <div class="flex flex-col md:flex-row justify-between items-center gap-6 text-sm text-slate-400">
              <p>© 2025 STREAM Entertainment. All rights reserved.</p>
              <div class="flex gap-6">
                <a href="#" class="hover:text-white transition-colors">Privacy Policy</a>
                <a href="#" class="hover:text-white transition-colors">Terms of Service</a>
                <a href="#" class="hover:text-white transition-colors">Content Guidelines</a>
                <a href="#" class="hover:text-white transition-colors">Cookie Settings</a>
              </div>
            </div>
          </div>
        </div>
      </footer>
    `
  }
]