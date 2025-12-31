// Header presets using inline SVG icons with explicit stroke/fill colors
// SVGs are directly embedded for visibility and editability in GrapesJS canvas

export const headerPresets = [
  // 1. Minimal Sticky Header
  {
    id: 'header-minimal-sticky',
    label: 'Minimal Sticky',
    category: 'Headers',
    content: `
      <header class="sticky top-0 z-50 bg-white shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex justify-between items-center h-16">
            <div class="flex-shrink-0 flex items-center">
              <a href="#" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-600 to-purple-600 flex items-center justify-center">
                  <span class="text-white font-bold text-lg">L</span>
                </div>
                <span class="text-xl font-bold text-gray-900">LUMINA</span>
              </a>
            </div>
            <nav class="hidden md:flex items-center gap-1">
              <a href="#" class="px-4 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-100 text-sm font-medium">Home</a>
              <a href="#" class="px-4 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-100 text-sm font-medium">Products</a>
              <a href="#" class="px-4 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-100 text-sm font-medium">Solutions</a>
              <a href="#" class="px-4 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-100 text-sm font-medium">Pricing</a>
              <a href="#" class="px-4 py-2 rounded-lg text-gray-600 hover:text-gray-900 hover:bg-gray-100 text-sm font-medium">Contact</a>
            </nav>
            <div class="flex items-center gap-2">
              <button type="button" class="p-2 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-100 flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              </button>
              <button type="button" class="p-2 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-100 relative flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span class="absolute -top-1 -right-1 h-5 w-5 rounded-full bg-indigo-600 text-white text-xs flex items-center justify-center font-bold">3</span>
              </button>
              <button type="button" class="p-2 rounded-lg text-gray-500 hover:text-gray-900 hover:bg-gray-100 flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </button>
              <button type="button" class="md:hidden p-2 rounded-lg text-gray-600 hover:bg-gray-100 flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              </button>
            </div>
          </div>
        </div>
      </header>
    `
  },

  // 2. Transparent Split Header
  {
    id: 'header-transparent-split',
    label: 'Transparent Split',
    category: 'Headers',
    content: `
      <header class="absolute top-0 w-full z-50 bg-gray-900">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex justify-between items-center h-24">
            <nav class="hidden lg:flex items-center gap-8 flex-1">
              <a href="#" class="text-white font-medium text-sm tracking-wide hover:text-gray-300 transition-colors">Men</a>
              <a href="#" class="text-white font-medium text-sm tracking-wide hover:text-gray-300 transition-colors">Women</a>
              <a href="#" class="text-white font-medium text-sm tracking-wide hover:text-gray-300 transition-colors">Accessories</a>
            </nav>
            <div class="flex-shrink-0 flex items-center justify-center flex-1">
              <a href="#" class="text-2xl font-bold text-white tracking-widest">LUXE</a>
            </div>
            <div class="flex items-center justify-end gap-4 flex-1">
              <div class="hidden lg:flex items-center gap-6">
                <a href="#" class="text-white font-medium text-sm tracking-wide hover:text-gray-300 transition-colors">About</a>
                <a href="#" class="text-white font-medium text-sm tracking-wide hover:text-gray-300 transition-colors">Journal</a>
              </div>
              <div class="flex items-center gap-2">
                <button type="button" class="p-2.5 rounded-full text-white hover:bg-white hover:bg-opacity-20 transition-all duration-200 flex items-center justify-center">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
                <button type="button" class="p-2.5 rounded-full text-white hover:bg-white hover:bg-opacity-20 transition-all duration-200 flex items-center justify-center">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </button>
                <button type="button" class="p-2.5 rounded-full text-white hover:bg-white hover:bg-opacity-20 transition-all duration-200 relative flex items-center justify-center">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                  <span class="absolute -top-0.5 -right-0.5 h-5 w-5 rounded-full bg-indigo-500 text-white text-xs flex items-center justify-center font-bold">0</span>
                </button>
              </div>
              <button type="button" class="lg:hidden p-2.5 rounded-full text-white flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              </button>
            </div>
          </div>
        </div>
      </header>
    `
  },

  // 3. Mega Menu Header
  {
    id: 'header-mega-menu',
    label: 'Mega Menu',
    category: 'Headers',
    content: `
      <header class="bg-white border-b border-gray-100 relative" id="header-mega">
        <div class="bg-gray-900 text-white text-xs py-2.5">
          <div class="max-w-7xl mx-auto px-4 flex justify-between items-center">
            <p class="flex items-center gap-2">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
              Free shipping on orders over $100
            </p>
            <div class="flex items-center gap-6">
              <a href="#" class="hover:text-gray-300 transition-colors flex items-center gap-1">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                Store Locator
              </a>
              <a href="#" class="hover:text-gray-300 transition-colors flex items-center gap-1">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                Help
              </a>
              <a href="#" class="hover:text-gray-300 transition-colors flex items-center gap-1">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                Sign In
              </a>
            </div>
          </div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex justify-between items-center h-20">
            <div class="flex-shrink-0">
              <a href="#" class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg">
                  <span class="text-white font-bold text-lg">M</span>
                </div>
                <span class="text-2xl font-bold bg-gradient-to-r from-indigo-600 to-purple-600 bg-clip-text text-transparent">MARKET</span>
              </a>
            </div>
            <div class="hidden md:flex flex-1 max-w-xl mx-8">
              <div class="relative w-full group">
                <input type="text" class="w-full bg-gray-50 border border-gray-200 rounded-2xl py-3 pl-12 pr-4 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:bg-white transition-all" placeholder="Search products, brands, categories...">
                <span class="absolute left-4 inset-y-0 flex items-center text-gray-400">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </span>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <button type="button" class="p-2.5 rounded-full text-gray-500 hover:text-indigo-600 hover:bg-indigo-50 transition-all duration-200 relative flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <span class="absolute -top-0.5 -right-0.5 h-4 w-4 rounded-full bg-red-500 text-white text-xs flex items-center justify-center font-bold">2</span>
              </button>
              <button type="button" class="p-2.5 rounded-full text-gray-500 hover:text-indigo-600 hover:bg-indigo-50 transition-all duration-200 relative flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                <span class="absolute -top-0.5 -right-0.5 h-4 w-4 rounded-full bg-indigo-600 text-white text-xs flex items-center justify-center font-bold">3</span>
              </button>
              <button type="button" class="lg:hidden p-2.5 rounded-full text-gray-600 hover:bg-gray-100 flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              </button>
            </div>
          </div>
          <nav class="hidden lg:flex items-center border-t border-gray-100">
            <a href="#" class="px-5 py-4 text-sm font-semibold text-gray-700 hover:text-indigo-600 flex items-center gap-1.5 transition-colors">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
              Shop All
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </a>
            <a href="#" class="px-5 py-4 text-sm font-semibold text-gray-700 hover:text-indigo-600 transition-colors">New Arrivals</a>
            <a href="#" class="px-5 py-4 text-sm font-semibold text-gray-700 hover:text-indigo-600 transition-colors">Best Sellers</a>
            <a href="#" class="px-5 py-4 text-sm font-semibold text-rose-600 hover:text-rose-700 transition-colors flex items-center gap-1">
              <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#e11d48" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.072-2.143-.224-4.054 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.153.433-2.294 1-3a2.5 2.5 0 0 0 2.5 2.5z"/></svg>
              Sale
            </a>
          </nav>
        </div>
      </header>
    `
  },

  // 4. Glassmorphism Blur Header
  {
    id: 'header-glassmorphism',
    label: 'Glassmorphism Blur',
    category: 'Headers',
    content: `
      <div class="fixed top-4 left-0 w-full z-50 pointer-events-none">
        <header class="max-w-5xl mx-auto bg-white bg-opacity-70 border border-white border-opacity-30 shadow-2xl rounded-full pointer-events-auto transition-all duration-300" style="backdrop-filter: blur(16px);">
          <div class="px-6 py-3 flex justify-between items-center">
            <div class="lg:hidden">
              <button type="button" class="p-2 text-gray-700 hover:bg-white hover:bg-opacity-50 rounded-full transition-colors flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              </button>
            </div>
            <a href="#" class="flex items-center gap-3 group">
              <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-violet-500 to-fuchsia-500 flex items-center justify-center shadow-lg">
                <span class="text-white font-bold text-lg">A</span>
              </div>
              <span class="text-xl font-bold text-gray-800 tracking-tight group-hover:text-violet-600 transition-colors">AERO</span>
            </a>
            <nav class="hidden lg:flex items-center gap-1">
              <a href="#" class="px-4 py-2 rounded-full text-sm font-medium text-gray-700 hover:text-violet-600 hover:bg-white hover:bg-opacity-60 transition-all">Home</a>
              <a href="#" class="px-4 py-2 rounded-full text-sm font-medium text-gray-700 hover:text-violet-600 hover:bg-white hover:bg-opacity-60 transition-all">Features</a>
              <a href="#" class="px-4 py-2 rounded-full text-sm font-medium text-gray-700 hover:text-violet-600 hover:bg-white hover:bg-opacity-60 transition-all">Pricing</a>
              <a href="#" class="px-4 py-2 rounded-full text-sm font-medium text-gray-700 hover:text-violet-600 hover:bg-white hover:bg-opacity-60 transition-all">Blog</a>
            </nav>
            <div class="flex items-center gap-2">
              <button type="button" class="p-2 text-gray-500 hover:text-violet-600 hover:bg-white hover:bg-opacity-50 rounded-full transition-all flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              </button>
              <a href="#" class="hidden sm:flex items-center gap-2 px-5 py-2.5 rounded-full bg-gradient-to-r from-violet-500 to-fuchsia-500 text-white text-sm font-semibold shadow-lg hover:shadow-xl hover:scale-105 transition-all">
                Get Started
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              </a>
            </div>
          </div>
        </header>
      </div>
    `
  },

  // 5. Dark Mode Tech Header
  {
    id: 'header-dark-mode',
    label: 'Dark Mode Tech',
    category: 'Headers',
    content: `
      <header class="bg-gray-950 text-white border-b border-gray-800" id="header-dark">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex justify-between h-16">
            <div class="flex-shrink-0 flex items-center">
              <a href="#" class="flex items-center gap-3 group">
                <div class="h-9 w-9 bg-gradient-to-br from-blue-500 to-cyan-400 rounded-lg flex items-center justify-center shadow-lg">
                  <span class="text-white font-bold">T</span>
                </div>
                <span class="font-bold text-xl tracking-wide group-hover:text-blue-400 transition-colors">TECHNO</span>
              </a>
            </div>
            <div class="hidden md:flex items-center gap-1">
              <a href="#" class="px-4 py-2 rounded-lg text-gray-300 hover:text-white hover:bg-gray-800 transition-all text-sm font-mono">Platform</a>
              <a href="#" class="px-4 py-2 rounded-lg text-gray-300 hover:text-white hover:bg-gray-800 transition-all text-sm font-mono">Solutions</a>
              <a href="#" class="px-4 py-2 rounded-lg text-gray-300 hover:text-white hover:bg-gray-800 transition-all text-sm font-mono">Developers</a>
              <a href="#" class="px-4 py-2 rounded-lg text-gray-300 hover:text-white hover:bg-gray-800 transition-all text-sm font-mono">Resources</a>
            </div>
            <div class="flex items-center gap-3">
              <button type="button" class="p-2 text-gray-400 hover:text-white transition-colors hidden sm:flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
              </button>
              <a href="#" class="hidden sm:flex items-center gap-2 px-4 py-2 text-gray-300 hover:text-white transition-colors text-sm font-mono">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 19c-5 1.5-5-2.5-7-3m14 6v-3.87a3.37 3.37 0 0 0-.94-2.61c3.14-.35 6.44-1.54 6.44-7A5.44 5.44 0 0 0 20 4.77 5.07 5.07 0 0 0 19.91 1S18.73.65 16 2.48a13.38 13.38 0 0 0-7 0C6.27.65 5.09 1 5.09 1A5.07 5.07 0 0 0 5 4.77a5.44 5.44 0 0 0-1.5 3.78c0 5.42 3.3 6.61 6.44 7A3.37 3.37 0 0 0 9 18.13V22"/></svg>
                Star us
              </a>
              <a href="#" class="px-5 py-2 bg-gradient-to-r from-blue-600 to-blue-500 hover:from-blue-500 hover:to-blue-400 text-white text-sm font-semibold rounded-lg shadow-lg transition-all flex items-center gap-2">
                Start Building
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              </a>
              <button type="button" class="md:hidden p-2 text-gray-300 hover:text-white flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              </button>
            </div>
          </div>
        </div>
      </header>
    `
  },

  // 6. Corporate Pro Header
  {
    id: 'header-corporate',
    label: 'Corporate Pro',
    category: 'Headers',
    content: `
      <header class="bg-white shadow-md relative z-40" id="header-corporate">
        <div class="bg-gray-900 text-white py-2.5">
          <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row justify-between items-center text-sm">
            <div class="flex items-center gap-6 mb-2 sm:mb-0">
              <span class="flex items-center gap-2 text-gray-300">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                +1 (555) 123-4567
              </span>
              <span class="flex items-center gap-2 text-gray-300">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                contact@corp-era.com
              </span>
            </div>
            <div class="flex items-center gap-6">
              <a href="#" class="text-gray-300 hover:text-white transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#d1d5db" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Mon-Fri: 9AM-6PM
              </a>
              <div class="flex items-center gap-3 pl-4 border-l border-gray-700">
                <a href="#" class="text-gray-400 hover:text-white transition-colors">
                  <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-2-2 2 2 0 0 0-2 2v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/></svg>
                </a>
                <a href="#" class="text-gray-400 hover:text-white transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg>
                </a>
                <a href="#" class="text-gray-400 hover:text-white transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                </a>
              </div>
            </div>
          </div>
        </div>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex justify-between items-center h-20">
            <a href="#" class="flex items-center gap-4 group">
              <div class="w-12 h-12 rounded-xl bg-gray-900 flex items-center justify-center shadow-lg overflow-hidden">
                <span class="text-white font-bold text-xl">C</span>
              </div>
              <div>
                <span class="text-2xl font-bold text-gray-800">CORP<span class="text-emerald-600">ERA</span></span>
                <p class="text-xs text-gray-500 uppercase tracking-widest">Solutions</p>
              </div>
            </a>
            <nav class="hidden lg:flex items-center gap-1">
              <a href="#" class="flex items-center gap-1 px-4 py-2 text-gray-700 hover:text-emerald-600 font-medium transition-colors">
                Services
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
              </a>
              <a href="#" class="px-4 py-2 text-gray-700 hover:text-emerald-600 font-medium transition-colors">Portfolio</a>
              <a href="#" class="px-4 py-2 text-gray-700 hover:text-emerald-600 font-medium transition-colors">Company</a>
              <a href="#" class="px-4 py-2 text-gray-700 hover:text-emerald-600 font-medium transition-colors">News</a>
              <a href="#" class="px-4 py-2 text-gray-700 hover:text-emerald-600 font-medium transition-colors">Careers</a>
            </nav>
            <div class="hidden lg:flex items-center gap-4">
              <button type="button" class="p-2.5 text-gray-500 hover:text-emerald-600 transition-colors flex items-center justify-center">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
              </button>
              <a href="#" class="px-6 py-2.5 bg-emerald-600 text-white font-semibold rounded-lg hover:bg-emerald-700 transition-all shadow-lg flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Request Quote
              </a>
            </div>
            <button type="button" class="lg:hidden p-2.5 text-gray-700 hover:bg-gray-100 rounded-lg flex items-center justify-center">
              <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#374151" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
            </button>
          </div>
        </div>
      </header>
    `
  },

  // 7. Center Stacked Header
  {
    id: 'header-center-stacked',
    label: 'Center Stacked',
    category: 'Headers',
    content: `
      <header class="bg-white" id="header-center">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-5">
          <div class="flex flex-col items-center">
            <div class="mb-5">
              <a href="#" class="group text-center">
                <div class="w-16 h-16 rounded-full bg-gradient-to-br from-amber-400 to-orange-500 flex items-center justify-center shadow-lg mb-3 mx-auto overflow-hidden">
                  <span class="text-white font-bold text-2xl">E</span>
                </div>
                <span class="text-3xl font-serif font-bold text-gray-900 tracking-wider group-hover:text-orange-500 transition-colors">ELEGANCE</span>
              </a>
            </div>
            <div class="w-full flex justify-between items-center border-t border-gray-100 pt-4">
              <div class="hidden md:flex items-center gap-4">
                <a href="#" class="text-gray-400 hover:text-orange-500 transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="20" rx="5" ry="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg>
                </a>
                <a href="#" class="text-gray-400 hover:text-orange-500 transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                </a>
                <a href="#" class="text-gray-400 hover:text-orange-500 transition-colors">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 3a10.9 10.9 0 0 1-3.14 1.53 4.48 4.48 0 0 0-7.86 3v1A10.66 10.66 0 0 1 3 4s-4 9 5 13a11.64 11.64 0 0 1-7 2c9 5 20 0 20-11.5a4.5 4.5 0 0 0-.08-.83A7.72 7.72 0 0 0 23 3z"/></svg>
                </a>
              </div>
              <nav class="flex-1 flex justify-center gap-8">
                <a href="#" class="text-gray-600 hover:text-orange-500 font-medium uppercase text-xs tracking-widest transition-colors">New</a>
                <a href="#" class="text-gray-600 hover:text-orange-500 font-medium uppercase text-xs tracking-widest transition-colors">Catalog</a>
                <a href="#" class="text-gray-600 hover:text-orange-500 font-medium uppercase text-xs tracking-widest transition-colors">Collections</a>
                <a href="#" class="text-gray-600 hover:text-orange-500 font-medium uppercase text-xs tracking-widest transition-colors">Sale</a>
              </nav>
              <div class="flex items-center gap-3">
                <button type="button" class="p-2.5 text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-full transition-all flex items-center justify-center">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </button>
                <button type="button" class="p-2.5 text-gray-500 hover:text-gray-900 hover:bg-gray-50 rounded-full transition-all relative flex items-center justify-center">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#6b7280" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>
                  <span class="absolute -top-0.5 -right-0.5 h-5 w-5 rounded-full bg-orange-500 text-white text-xs flex items-center justify-center font-bold">0</span>
                </button>
                <button type="button" class="lg:hidden p-2.5 text-gray-600 hover:bg-gray-50 rounded-full transition-all flex items-center justify-center">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
                </button>
              </div>
            </div>
          </div>
        </div>
      </header>
    `
  },

  // 8. Search Focused Header
  {
    id: 'header-search-focused',
    label: 'Search Focused',
    category: 'Headers',
    content: `
      <header class="bg-gray-900 text-white" id="header-search">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex items-center h-20 gap-6">
            <div class="flex-shrink-0">
              <a href="#" class="flex items-center gap-3 group">
                <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-yellow-400 to-amber-500 flex items-center justify-center shadow-lg">
                  <span class="text-gray-900 font-bold text-xl">P</span>
                </div>
                <span class="text-2xl font-bold tracking-tight">POWER<span class="text-yellow-400">SHOP</span></span>
              </a>
            </div>
            <div class="flex-1 max-w-2xl">
              <div class="relative group">
                <input type="text" class="w-full bg-gray-800 border border-gray-700 text-white placeholder-gray-400 rounded-xl py-3.5 pl-14 pr-28 focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:bg-gray-900 transition-all" placeholder="Search for anything...">
                <span class="absolute left-5 inset-y-0 flex items-center text-gray-400">
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                </span>
                <button type="button" class="absolute right-3 inset-y-0 my-auto h-fit px-4 py-2 bg-yellow-500 hover:bg-yellow-400 text-gray-900 text-sm font-bold rounded-lg transition-colors">Search</button>
              </div>
            </div>
            <div class="flex items-center gap-4 ml-auto">
              <button type="button" class="hidden md:flex flex-col items-center justify-center hover:text-yellow-400 transition-colors group">
                <svg class="w-6 h-6 mb-1" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <span class="text-xs font-medium">Account</span>
              </button>
              <button type="button" class="hidden md:flex flex-col items-center justify-center hover:text-yellow-400 transition-colors group">
                <svg class="w-6 h-6 mb-1" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                <span class="text-xs font-medium">Saved</span>
              </button>
              <button type="button" class="flex flex-col items-center justify-center hover:text-yellow-400 transition-colors relative">
                <svg class="w-6 h-6 mb-1" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
                <span class="text-xs font-medium">Cart</span>
                <span class="absolute -top-1 left-1/2 transform -translate-x-1/2 bg-yellow-500 text-gray-900 text-xs font-bold px-1.5 py-0.5 rounded-full">3</span>
              </button>
              <button type="button" class="lg:hidden p-2 text-white hover:text-yellow-400 flex items-center justify-center">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              </button>
            </div>
          </div>
        </div>
        <div class="bg-gray-800 bg-opacity-50 border-t border-gray-700">
          <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center h-12 gap-1 text-sm overflow-x-auto">
              <a href="#" class="px-4 py-2 font-semibold hover:text-white text-yellow-400 whitespace-nowrap transition-colors flex items-center gap-2">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#facc15" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                All Departments
              </a>
              <div class="h-4 w-px bg-gray-600"></div>
              <a href="#" class="px-3 py-2 hover:text-white text-gray-300 whitespace-nowrap transition-colors">Today's Deals</a>
              <a href="#" class="px-3 py-2 hover:text-white text-gray-300 whitespace-nowrap transition-colors">Customer Service</a>
              <a href="#" class="px-3 py-2 hover:text-white text-gray-300 whitespace-nowrap transition-colors">Registry</a>
              <a href="#" class="px-3 py-2 hover:text-white text-gray-300 whitespace-nowrap transition-colors">Gift Cards</a>
            </div>
          </div>
        </div>
      </header>
    `
  },

  // 9. Minimal Left Header
  {
    id: 'header-minimal-left',
    label: 'Minimal Left',
    category: 'Headers',
    content: `
      <header class="bg-white border-b border-gray-100" id="header-left">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
          <div class="flex items-center justify-between h-20">
            <div class="flex items-center gap-4">
              <button type="button" class="p-2 -ml-2 text-gray-600 hover:bg-gray-100 rounded-xl transition-colors flex items-center justify-center">
                <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
              </button>
              <a href="#" class="flex items-center gap-3 group">
                <div class="w-10 h-10 rounded-xl bg-gray-900 flex items-center justify-center shadow-lg overflow-hidden">
                  <span class="text-white font-bold">S</span>
                </div>
                <span class="text-2xl font-bold text-gray-900 tracking-tight">STUDIO</span>
              </a>
            </div>
            <nav class="hidden lg:flex items-center gap-8">
              <a href="#" class="text-sm font-medium text-gray-500 hover:text-gray-900 transition-colors">Work</a>
              <a href="#" class="text-sm font-medium text-gray-500 hover:text-gray-900 transition-colors">Services</a>
              <a href="#" class="text-sm font-medium text-gray-500 hover:text-gray-900 transition-colors">About</a>
              <a href="#" class="text-sm font-medium text-gray-500 hover:text-gray-900 transition-colors">Journal</a>
              <a href="#" class="text-sm font-medium text-gray-500 hover:text-gray-900 transition-colors">Contact</a>
            </nav>
            <div class="flex items-center gap-4">
              <a href="#" class="hidden md:flex items-center gap-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition-colors">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#4b5563" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/></svg>
                Log in
              </a>
              <a href="#" class="px-5 py-2.5 bg-gray-900 text-white text-sm font-semibold rounded-xl hover:bg-gray-800 transition-all shadow-lg flex items-center gap-2">
                Get Started
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
              </a>
            </div>
          </div>
        </div>
      </header>
    `
  }
];
