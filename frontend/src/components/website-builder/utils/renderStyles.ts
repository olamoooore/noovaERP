// Shared styles and scripts for rendering pages in preview and public view
// This ensures consistency between the canvas, preview, and public view

// Custom Tailwind CSS for features not in Tailwind 2.x CDN
export const customTailwindCSS = `
/* Slate colors */
.text-slate-50{color:rgb(248,250,252)}.text-slate-100{color:rgb(241,245,249)}.text-slate-200{color:rgb(226,232,240)}.text-slate-300{color:rgb(203,213,225)}.text-slate-400{color:rgb(148,163,184)}.text-slate-500{color:rgb(100,116,139)}.text-slate-600{color:rgb(71,85,105)}.text-slate-700{color:rgb(51,65,85)}.text-slate-800{color:rgb(30,41,59)}.text-slate-900{color:rgb(15,23,42)}.text-slate-950{color:rgb(2,6,23)}
.bg-slate-50{background-color:rgb(248,250,252)}.bg-slate-100{background-color:rgb(241,245,249)}.bg-slate-200{background-color:rgb(226,232,240)}.bg-slate-300{background-color:rgb(203,213,225)}.bg-slate-400{background-color:rgb(148,163,184)}.bg-slate-500{background-color:rgb(100,116,139)}.bg-slate-600{background-color:rgb(71,85,105)}.bg-slate-700{background-color:rgb(51,65,85)}.bg-slate-800{background-color:rgb(30,41,59)}.bg-slate-900{background-color:rgb(15,23,42)}.bg-slate-950{background-color:rgb(2,6,23)}
.border-slate-100{border-color:rgb(241,245,249)}.border-slate-200{border-color:rgb(226,232,240)}.border-slate-300{border-color:rgb(203,213,225)}.border-slate-700{border-color:rgb(51,65,85)}.border-slate-800{border-color:rgb(30,41,59)}
/* Teal colors */
.bg-teal-500{background-color:rgb(20,184,166)}.bg-teal-600{background-color:rgb(13,148,136)}.bg-teal-700{background-color:rgb(15,118,110)}.text-teal-100{color:rgb(204,251,241)}.text-teal-200{color:rgb(153,246,228)}.text-teal-500{color:rgb(20,184,166)}.text-teal-600{color:rgb(13,148,136)}.border-teal-700{border-color:rgb(15,118,110)}
.hover\\:bg-teal-500:hover{background-color:rgb(20,184,166)}.hover\\:bg-teal-600:hover{background-color:rgb(13,148,136)}.hover\\:bg-teal-700:hover{background-color:rgb(15,118,110)}.hover\\:text-teal-600:hover{color:rgb(13,148,136)}
/* Green colors */
.bg-green-500{background-color:rgb(34,197,94)}.bg-green-600{background-color:rgb(22,163,74)}.bg-green-100{background-color:rgb(220,252,231)}.bg-green-800{background-color:rgb(22,101,52)}
.text-green-100{color:rgb(220,252,231)}.text-green-200{color:rgb(187,247,208)}.text-green-300{color:rgb(134,239,172)}.text-green-400{color:rgb(74,222,128)}.text-green-500{color:rgb(34,197,94)}.text-green-600{color:rgb(22,163,74)}
.border-green-700{border-color:rgb(21,128,61)}.hover\\:bg-green-400:hover{background-color:rgb(74,222,128)}.hover\\:bg-green-600:hover{background-color:rgb(22,163,74)}
/* Purple/Violet colors */
.bg-purple-500{background-color:rgb(168,85,247)}.bg-purple-600{background-color:rgb(147,51,234)}.bg-violet-600{background-color:rgb(124,58,237)}.bg-violet-900{background-color:rgb(76,29,149)}
.text-purple-500{color:rgb(168,85,247)}.text-violet-600{color:rgb(124,58,237)}.hover\\:bg-purple-500:hover{background-color:rgb(168,85,247)}.hover\\:text-purple-600:hover{color:rgb(147,51,234)}
/* Pink colors */
.bg-pink-500{background-color:rgb(236,72,153)}.bg-pink-600{background-color:rgb(219,39,119)}.text-pink-500{color:rgb(236,72,153)}.hover\\:bg-pink-500:hover{background-color:rgb(236,72,153)}.hover\\:text-pink-600:hover{color:rgb(219,39,119)}
/* Red colors */
.bg-red-500{background-color:rgb(239,68,68)}.bg-red-600{background-color:rgb(220,38,38)}.text-red-500{color:rgb(239,68,68)}.hover\\:bg-red-500:hover{background-color:rgb(239,68,68)}
/* Amber/Orange colors */
.bg-amber-600{background-color:rgb(217,119,6)}.bg-amber-900{background-color:rgb(120,53,15)}.bg-amber-950{background-color:rgb(69,26,3)}.bg-orange-500{background-color:rgb(249,115,22)}
.text-amber-50{color:rgb(255,251,235)}.text-amber-200{color:rgb(253,230,138)}.text-amber-400{color:rgb(251,191,36)}.text-amber-500{color:rgb(245,158,11)}.text-orange-500{color:rgb(249,115,22)}
.border-amber-200{border-color:rgb(253,230,138)}.border-amber-400{border-color:rgb(251,191,36)}.hover\\:bg-amber-400:hover{background-color:rgb(251,191,36)}.hover\\:bg-amber-500:hover{background-color:rgb(245,158,11)}
/* Indigo/Blue colors */
.bg-indigo-900{background-color:rgb(49,46,129)}.bg-indigo-950{background-color:rgb(30,27,75)}.bg-blue-800{background-color:rgb(30,64,175)}.bg-blue-950{background-color:rgb(23,37,84)}.bg-sky-900{background-color:rgb(12,74,110)}
.text-indigo-600{color:rgb(79,70,229)}.text-blue-500{color:rgb(59,130,246)}.text-blue-400{color:rgb(96,165,250)}.border-indigo-900{border-color:rgb(49,46,129)}.hover\\:text-blue-400:hover{color:rgb(96,165,250)}.hover\\:text-blue-800:hover{color:rgb(30,64,175)}.hover\\:text-blue-950:hover{color:rgb(23,37,84)}
/* Stone colors */
.bg-stone-50{background-color:rgb(250,250,249)}.bg-stone-200{background-color:rgb(231,229,228)}.bg-stone-800{background-color:rgb(41,37,36)}.bg-stone-900{background-color:rgb(28,25,23)}
.text-stone-500{color:rgb(120,113,108)}.text-stone-600{color:rgb(87,83,78)}.text-stone-700{color:rgb(68,64,60)}.text-stone-800{color:rgb(41,37,36)}
.border-stone-200{border-color:rgb(231,229,228)}.border-stone-300{border-color:rgb(214,211,209)}.hover\\:bg-stone-800:hover{background-color:rgb(41,37,36)}.hover\\:bg-stone-900:hover{background-color:rgb(28,25,23)}.hover\\:text-stone-800:hover{color:rgb(41,37,36)}
/* Cyan/Emerald colors */
.text-cyan-400{color:rgb(34,211,238)}.text-emerald-400{color:rgb(52,211,153)}.bg-emerald-500{background-color:rgb(16,185,129)}.bg-emerald-600{background-color:rgb(5,150,105)}
/* Yellow colors */
.text-yellow-400{color:rgb(250,204,21)}.text-yellow-500{color:rgb(234,179,8)}.bg-yellow-400{background-color:rgb(250,204,21)}.bg-yellow-500{background-color:rgb(234,179,8)}
/* Rose colors */
.text-rose-600{color:rgb(225,29,72)}.bg-rose-500{background-color:rgb(244,63,94)}.bg-rose-600{background-color:rgb(225,29,72)}
/* Opacity variants */
.bg-white\\/5{background-color:rgba(255,255,255,0.05)}.bg-white\\/10{background-color:rgba(255,255,255,0.1)}.bg-white\\/20{background-color:rgba(255,255,255,0.2)}.bg-white\\/30{background-color:rgba(255,255,255,0.3)}.bg-white\\/50{background-color:rgba(255,255,255,0.5)}.bg-white\\/70{background-color:rgba(255,255,255,0.7)}.bg-white\\/80{background-color:rgba(255,255,255,0.8)}.bg-white\\/90{background-color:rgba(255,255,255,0.9)}
.bg-black\\/5{background-color:rgba(0,0,0,0.05)}.bg-black\\/10{background-color:rgba(0,0,0,0.1)}.bg-black\\/20{background-color:rgba(0,0,0,0.2)}.bg-black\\/30{background-color:rgba(0,0,0,0.3)}.bg-black\\/50{background-color:rgba(0,0,0,0.5)}.bg-black\\/60{background-color:rgba(0,0,0,0.6)}.bg-black\\/70{background-color:rgba(0,0,0,0.7)}
.bg-slate-900\\/50{background-color:rgba(15,23,42,0.5)}.bg-slate-900\\/60{background-color:rgba(15,23,42,0.6)}.bg-slate-900\\/80{background-color:rgba(15,23,42,0.8)}.bg-slate-900\\/95{background-color:rgba(15,23,42,0.95)}.bg-slate-950\\/95{background-color:rgba(2,6,23,0.95)}.bg-slate-800\\/50{background-color:rgba(30,41,59,0.5)}
.bg-violet-900\\/30{background-color:rgba(76,29,149,0.3)}.bg-amber-900\\/50{background-color:rgba(120,53,15,0.5)}.bg-green-500\\/20{background-color:rgba(34,197,94,0.2)}
.text-white\\/50{color:rgba(255,255,255,0.5)}.text-white\\/60{color:rgba(255,255,255,0.6)}.text-white\\/70{color:rgba(255,255,255,0.7)}.text-white\\/80{color:rgba(255,255,255,0.8)}.text-white\\/90{color:rgba(255,255,255,0.9)}.text-amber-200\\/80{color:rgba(253,230,138,0.8)}
.border-white\\/5{border-color:rgba(255,255,255,0.05)}.border-white\\/10{border-color:rgba(255,255,255,0.1)}.border-white\\/20{border-color:rgba(255,255,255,0.2)}.border-white\\/30{border-color:rgba(255,255,255,0.3)}.border-green-500\\/30{border-color:rgba(34,197,94,0.3)}.border-amber-200\\/20{border-color:rgba(253,230,138,0.2)}
.hover\\:bg-white\\/5:hover{background-color:rgba(255,255,255,0.05)}.hover\\:bg-white\\/10:hover{background-color:rgba(255,255,255,0.1)}.hover\\:bg-white\\/20:hover{background-color:rgba(255,255,255,0.2)}.hover\\:bg-white\\/50:hover{background-color:rgba(255,255,255,0.5)}.hover\\:bg-white\\/60:hover{background-color:rgba(255,255,255,0.6)}.hover\\:bg-white\\/80:hover{background-color:rgba(255,255,255,0.8)}
.hover\\:bg-black\\/10:hover{background-color:rgba(0,0,0,0.1)}.hover\\:bg-black\\/20:hover{background-color:rgba(0,0,0,0.2)}
/* Hover states */
.hover\\:text-slate-900:hover{color:rgb(15,23,42)}.hover\\:text-slate-600:hover{color:rgb(71,85,105)}.hover\\:bg-slate-50:hover{background-color:rgb(248,250,252)}.hover\\:bg-slate-100:hover{background-color:rgb(241,245,249)}.hover\\:bg-slate-800:hover{background-color:rgb(30,41,59)}.hover\\:text-amber-950:hover{color:rgb(69,26,3)}
/* Focus states */
.focus\\:ring-slate-500:focus{--tw-ring-color:rgb(100,116,139)}.focus\\:ring-teal-500:focus{--tw-ring-color:rgb(20,184,166)}.focus\\:ring-blue-500:focus{--tw-ring-color:rgb(59,130,246)}
/* Placeholder colors */
.placeholder-slate-400::placeholder{color:rgb(148,163,184)}.placeholder-gray-400::placeholder{color:rgb(156,163,175)}
/* Backdrop blur */
.backdrop-blur-sm{backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}.backdrop-blur-md{backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}.backdrop-blur-lg{backdrop-filter:blur(16px);-webkit-backdrop-filter:blur(16px)}.backdrop-blur-xl{backdrop-filter:blur(24px);-webkit-backdrop-filter:blur(24px)}.backdrop-blur-2xl{backdrop-filter:blur(40px);-webkit-backdrop-filter:blur(40px)}
/* Gradient text */
.bg-clip-text{-webkit-background-clip:text;background-clip:text}.text-transparent{color:transparent}
/* Animations */
@keyframes pulse{0%,100%{opacity:1}50%{opacity:0.5}}.animate-pulse{animation:pulse 2s cubic-bezier(0.4,0,0.6,1) infinite}
@keyframes spin{from{transform:rotate(0deg)}to{transform:rotate(360deg)}}.animate-spin{animation:spin 1s linear infinite}
@keyframes bounce{0%,100%{transform:translateY(-25%);animation-timing-function:cubic-bezier(0.8,0,1,1)}50%{transform:translateY(0);animation-timing-function:cubic-bezier(0,0,0.2,1)}}.animate-bounce{animation:bounce 1s infinite}
@keyframes marquee{0%{transform:translateX(0)}100%{transform:translateX(-50%)}}
@keyframes slideIn{from{transform:translateX(100%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes slideOut{from{transform:translateX(0);opacity:1}to{transform:translateX(100%);opacity:0}}
@keyframes addToCartPop{0%{transform:scale(1)}30%{transform:scale(0.95)}60%{transform:scale(1.05)}100%{transform:scale(1)}}
@keyframes cartBadgePop{0%{transform:scale(1)}50%{transform:scale(1.4)}100%{transform:scale(1)}}
@keyframes scaleBounce{0%,100%{transform:scale(1)}50%{transform:scale(1.1)}}
/* E-commerce styles */
.product-card{transition:all 0.3s ease}.product-card:hover{transform:translateY(-5px);box-shadow:0 10px 30px rgba(0,0,0,0.1)}
.add-to-cart-btn{transition:all 0.2s ease;position:relative;overflow:hidden}.add-to-cart-btn:hover:not(:disabled){transform:scale(1.05)}.add-to-cart-btn:active:not(:disabled){transform:scale(0.95)}.add-to-cart-btn.adding{animation:addToCartPop 0.4s ease;pointer-events:none}
.cart-badge.pop{animation:cartBadgePop 0.3s ease}
.heart-icon{transition:all 0.2s ease}.heart-icon:hover{transform:scale(1.1)}.heart-icon:active{transform:scale(0.9)}.heart-icon.active svg{animation:scaleBounce 0.3s ease}
[data-category-filter] button{transition:all 0.2s ease}[data-category-filter] button:hover{transform:translateX(4px)}
.shop-loading{animation:spin 1s linear infinite}
/* Font families */
.font-serif{font-family:'Playfair Display','Lora',Georgia,serif}
.font-mono{font-family:'JetBrains Mono',ui-monospace,monospace}
/* SVG styling */
svg{display:inline-block;vertical-align:middle;flex-shrink:0}
svg.w-3,svg.h-3{width:0.75rem;height:0.75rem}svg.w-4,svg.h-4{width:1rem;height:1rem}svg.w-5,svg.h-5{width:1.25rem;height:1.25rem}svg.w-6,svg.h-6{width:1.5rem;height:1.5rem}svg.w-7,svg.h-7{width:1.75rem;height:1.75rem}svg.w-8,svg.h-8{width:2rem;height:2rem}svg.w-10,svg.h-10{width:2.5rem;height:2.5rem}svg.w-12,svg.h-12{width:3rem;height:3rem}
/* Lucide icons */
svg.lucide{display:inline-block;vertical-align:middle;width:1em;height:1em;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round;fill:none}
i[data-lucide]{display:inline-block;width:1em;height:1em;vertical-align:middle}
/* Scrollbar hiding */
.no-scrollbar::-webkit-scrollbar{display:none}.no-scrollbar{-ms-overflow-style:none;scrollbar-width:none}
/* Pointer events */
.pointer-events-none{pointer-events:none}.pointer-events-auto{pointer-events:auto}
/* Group hover */
.group:hover .group-hover\\:text-slate-900{color:rgb(15,23,42)}.group:hover .group-hover\\:text-white{color:white}.group:hover .group-hover\\:rotate-180{transform:rotate(180deg)}.group:hover .group-hover\\:opacity-100{opacity:1}.group:hover .group-hover\\:visible{visibility:visible}.group:hover .group-hover\\:scale-105{transform:scale(1.05)}.group:hover .group-hover\\:translate-y-0{transform:translateY(0)}
/* Shadow colors */
.shadow-violet-500\\/30{--tw-shadow-color:rgba(139,92,246,0.3);box-shadow:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}
.shadow-blue-500\\/20{--tw-shadow-color:rgba(59,130,246,0.2);box-shadow:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}
.shadow-teal-500\\/20{--tw-shadow-color:rgba(20,184,166,0.2);box-shadow:0 10px 15px -3px var(--tw-shadow-color),0 4px 6px -4px var(--tw-shadow-color)}
/* Line clamp */
.line-clamp-1{overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:1}
.line-clamp-2{overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:2}
.line-clamp-3{overflow:hidden;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:3}
/* Aspect ratio */
.aspect-square{aspect-ratio:1/1}.aspect-video{aspect-ratio:16/9}.aspect-\\[4\\/3\\]{aspect-ratio:4/3}
/* Object fit */
.object-cover{object-fit:cover}.object-contain{object-fit:contain}.object-center{object-position:center}
/* Transition */
.transition-all{transition-property:all;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms}
.transition-colors{transition-property:color,background-color,border-color;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms}
.transition-transform{transition-property:transform;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms}
.transition-opacity{transition-property:opacity;transition-timing-function:cubic-bezier(0.4,0,0.2,1);transition-duration:150ms}
.duration-200{transition-duration:200ms}.duration-300{transition-duration:300ms}.duration-500{transition-duration:500ms}
/* Transform */
.scale-100{transform:scale(1)}.scale-105{transform:scale(1.05)}.scale-110{transform:scale(1.1)}
.hover\\:scale-105:hover{transform:scale(1.05)}.hover\\:scale-110:hover{transform:scale(1.1)}
.-translate-y-1{transform:translateY(-0.25rem)}.hover\\:-translate-y-1:hover{transform:translateY(-0.25rem)}
.translate-y-0{transform:translateY(0)}.translate-y-2{transform:translateY(0.5rem)}
.rotate-180{transform:rotate(180deg)}
`

// External stylesheets to load
export const externalStyles = [
  'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css',
  'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Roboto:wght@300;400;500;700&family=Open+Sans:wght@300;400;500;600;700&family=Lato:wght@300;400;700&family=Montserrat:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700&family=Raleway:wght@300;400;500;600;700&family=Nunito:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&family=Merriweather:wght@300;400;700&family=Source+Sans+Pro:wght@300;400;600;700&family=Ubuntu:wght@300;400;500;700&family=Oswald:wght@300;400;500;600;700&family=Quicksand:wght@300;400;500;600;700&family=Work+Sans:wght@300;400;500;600;700&family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@300;400;500;600;700;800&family=Manrope:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Lora:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap',
  'https://cdn.jsdelivr.net/npm/remixicon@3.5.0/fonts/remixicon.css',
]

// External scripts to load
export const externalScripts = [
  'https://cdn.tailwindcss.com',
  'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/gsap.min.js',
  'https://cdnjs.cloudflare.com/ajax/libs/gsap/3.12.2/ScrollTrigger.min.js',
  'https://unpkg.com/@studio-freight/lenis@1.0.29/dist/lenis.min.js',
]

// Initialization script that runs after all scripts are loaded
export const initScript = `
// Initialize Lucide icons
if (typeof lucide !== 'undefined') {
  lucide.createIcons();
}

// Initialize GSAP ScrollTrigger if available
if (typeof gsap !== 'undefined' && typeof ScrollTrigger !== 'undefined') {
  gsap.registerPlugin(ScrollTrigger);
}

// Initialize Lenis smooth scroll if available
if (typeof Lenis !== 'undefined') {
  const lenis = new Lenis({
    duration: 1.2,
    easing: (t) => Math.min(1, 1.001 - Math.pow(2, -10 * t)),
    direction: 'vertical',
    gestureDirection: 'vertical',
    smooth: true,
    mouseMultiplier: 1,
    smoothTouch: false,
    touchMultiplier: 2,
    infinite: false,
  });
  
  function raf(time) {
    lenis.raf(time);
    requestAnimationFrame(raf);
  }
  requestAnimationFrame(raf);
}
`

// Generate the full HTML head section
export function generateHeadContent(options: {
  title?: string
  description?: string
  customCss?: string
  headerJs?: string
}) {
  const { title = 'Page', description = '', customCss = '', headerJs = '' } = options
  
  const styleLinks = externalStyles.map(url => `<link href="${url}" rel="stylesheet">`).join('\n  ')
  
  return `
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${title}</title>
  ${description ? `<meta name="description" content="${description}">` : ''}
  ${styleLinks}
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; padding: 0; font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
    ${customTailwindCSS}
    ${customCss}
  </style>
  ${headerJs ? `<script>${headerJs}</script>` : ''}`
}

// Generate the full HTML body scripts section
export function generateBodyScripts(options: {
  bodyJs?: string
}) {
  const { bodyJs = '' } = options
  
  const scriptTags = externalScripts.map(url => `<script src="${url}"></script>`).join('\n  ')
  
  return `
  ${scriptTags}
  <script>${initScript}</script>
  ${bodyJs ? `<script>${bodyJs}</script>` : ''}`
}

// Generate a complete HTML document
export function generateFullHtml(options: {
  title?: string
  description?: string
  html: string
  css?: string
  headerJs?: string
  bodyJs?: string
  apiBaseUrl?: string
  shopSettings?: {
    enabled?: boolean
    primaryColor?: string
    primaryHoverColor?: string
    primaryLightColor?: string
    showSidebar?: boolean
    productsPerPage?: number
    showWishlist?: boolean
    showSearch?: boolean
    cartPageSlug?: string
    checkoutPageSlug?: string
    shopPageSlug?: string
  }
}) {
  const { title = 'Page', description = '', html, css = '', headerJs = '', bodyJs = '', apiBaseUrl, shopSettings } = options
  
  // Script to set API base URL for e-commerce functionality
  const apiConfigScript = apiBaseUrl ? `
  <script>
    // Configure API base URL for e-commerce
    window.__API_BASE_URL__ = '${apiBaseUrl}';
  </script>` : ''
  
  // Script to set shop settings for e-commerce functionality
  const shopConfigScript = shopSettings ? `
  <script>
    // Configure shop settings for e-commerce
    window.__SHOP_SETTINGS__ = ${JSON.stringify(shopSettings)};
  </script>` : ''
  
  return `<!DOCTYPE html>
<html lang="en">
<head>${generateHeadContent({ title, description, customCss: css, headerJs })}${apiConfigScript}${shopConfigScript}</head>
<body>
  ${html}
  ${generateBodyScripts({ bodyJs })}
</body>
</html>`
}
