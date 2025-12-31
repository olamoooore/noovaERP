// Canvas Configuration - Styles and Scripts for the GrapesJS canvas

// Custom CSS for Tailwind 3.x features not in Tailwind 2.x CDN
const customTailwindCSS = `
/* ============================================
   SVG Icon Styling - Basic visibility
   ============================================ */

/* Base SVG styling - ensure visibility */
svg {
  display: inline-block;
  vertical-align: middle;
  flex-shrink: 0;
}

/* SVG size classes */
svg.w-4 { width: 1rem; height: 1rem; }
svg.h-4 { height: 1rem; }
svg.w-5 { width: 1.25rem; height: 1.25rem; }
svg.h-5 { height: 1.25rem; }
svg.w-6 { width: 1.5rem; height: 1.5rem; }
svg.h-6 { height: 1.5rem; }
svg.w-7 { width: 1.75rem; height: 1.75rem; }
svg.h-7 { height: 1.75rem; }
svg.w-8 { width: 2rem; height: 2rem; }
svg.h-8 { height: 2rem; }

/* Slate colors (not in Tailwind 2.x) */
.text-slate-50 { color: rgb(248, 250, 252); }
.text-slate-100 { color: rgb(241, 245, 249); }
.text-slate-200 { color: rgb(226, 232, 240); }
.text-slate-300 { color: rgb(203, 213, 225); }
.text-slate-400 { color: rgb(148, 163, 184); }
.text-slate-500 { color: rgb(100, 116, 139); }
.text-slate-600 { color: rgb(71, 85, 105); }
.text-slate-700 { color: rgb(51, 65, 85); }
.text-slate-800 { color: rgb(30, 41, 59); }
.text-slate-900 { color: rgb(15, 23, 42); }
.text-slate-950 { color: rgb(2, 6, 23); }

.bg-slate-50 { background-color: rgb(248, 250, 252); }
.bg-slate-100 { background-color: rgb(241, 245, 249); }
.bg-slate-200 { background-color: rgb(226, 232, 240); }
.bg-slate-300 { background-color: rgb(203, 213, 225); }
.bg-slate-400 { background-color: rgb(148, 163, 184); }
.bg-slate-500 { background-color: rgb(100, 116, 139); }
.bg-slate-600 { background-color: rgb(71, 85, 105); }
.bg-slate-700 { background-color: rgb(51, 65, 85); }
.bg-slate-800 { background-color: rgb(30, 41, 59); }
.bg-slate-900 { background-color: rgb(15, 23, 42); }
.bg-slate-950 { background-color: rgb(2, 6, 23); }

.border-slate-100 { border-color: rgb(241, 245, 249); }
.border-slate-200 { border-color: rgb(226, 232, 240); }
.border-slate-300 { border-color: rgb(203, 213, 225); }
.border-slate-700 { border-color: rgb(51, 65, 85); }
.border-slate-800 { border-color: rgb(30, 41, 59); }

/* Hover states for slate */
.hover\\:text-slate-900:hover { color: rgb(15, 23, 42); }
.hover\\:text-slate-600:hover { color: rgb(71, 85, 105); }
.hover\\:bg-slate-50:hover { background-color: rgb(248, 250, 252); }
.hover\\:bg-slate-100:hover { background-color: rgb(241, 245, 249); }
.hover\\:bg-slate-800:hover { background-color: rgb(30, 41, 59); }

/* Placeholder colors */
.placeholder-slate-400::placeholder { color: rgb(148, 163, 184); }

/* Focus ring for slate */
.focus\\:ring-slate-500:focus { --tw-ring-color: rgb(100, 116, 139); }

/* Gray fallbacks */
.text-gray-500 { color: rgb(107, 114, 128); }
.text-gray-400 { color: rgb(156, 163, 175); }
.text-gray-600 { color: rgb(75, 85, 99); }
.text-gray-900 { color: rgb(17, 24, 39); }
.text-white { color: white; }
.text-yellow-400 { color: rgb(250, 204, 21); }
.text-indigo-600 { color: rgb(79, 70, 229); }
.text-emerald-400 { color: rgb(52, 211, 153); }
.text-orange-500 { color: rgb(249, 115, 22); }
.text-violet-600 { color: rgb(124, 58, 237); }
.text-rose-600 { color: rgb(225, 29, 72); }
.text-red-500 { color: rgb(239, 68, 68); }
.text-blue-500 { color: rgb(59, 130, 246); }
.text-cyan-400 { color: rgb(34, 211, 238); }

/* Backdrop blur */
.backdrop-blur-sm { backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px); }
.backdrop-blur-md { backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px); }
.backdrop-blur-lg { backdrop-filter: blur(16px); -webkit-backdrop-filter: blur(16px); }
.backdrop-blur-xl { backdrop-filter: blur(24px); -webkit-backdrop-filter: blur(24px); }
.backdrop-blur-2xl { backdrop-filter: blur(40px); -webkit-backdrop-filter: blur(40px); }

/* Background opacity variants */
.bg-white\\/70 { background-color: rgba(255,255,255,0.7); }
.bg-white\\/80 { background-color: rgba(255,255,255,0.8); }
.bg-white\\/90 { background-color: rgba(255,255,255,0.9); }
.bg-slate-900\\/50 { background-color: rgba(15,23,42,0.5); }
.bg-slate-900\\/60 { background-color: rgba(15,23,42,0.6); }
.bg-slate-900\\/95 { background-color: rgba(15,23,42,0.95); }
.bg-slate-950\\/95 { background-color: rgba(2,6,23,0.95); }
.bg-violet-900\\/30 { background-color: rgba(76,29,149,0.3); }
.bg-black\\/5 { background-color: rgba(0,0,0,0.05); }
.bg-slate-800\\/50 { background-color: rgba(30,41,59,0.5); }

/* Text opacity variants */
.text-white\\/90 { color: rgba(255,255,255,0.9); }

/* Border opacity variants */
.border-white\\/30 { border-color: rgba(255,255,255,0.3); }

/* Shadow color variants */
.shadow-violet-500\\/30 { --tw-shadow-color: rgba(139,92,246,0.3); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-violet-500\\/20 { --tw-shadow-color: rgba(139,92,246,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-blue-500\\/20 { --tw-shadow-color: rgba(59,130,246,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-blue-500\\/25 { --tw-shadow-color: rgba(59,130,246,0.25); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-blue-500\\/40 { --tw-shadow-color: rgba(59,130,246,0.4); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-yellow-500\\/20 { --tw-shadow-color: rgba(234,179,8,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-yellow-500\\/30 { --tw-shadow-color: rgba(234,179,8,0.3); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-orange-500\\/20 { --tw-shadow-color: rgba(249,115,22,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-emerald-600\\/20 { --tw-shadow-color: rgba(5,150,105,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-indigo-500\\/20 { --tw-shadow-color: rgba(99,102,241,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-slate-900\\/20 { --tw-shadow-color: rgba(15,23,42,0.2); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }
.shadow-slate-900\\/30 { --tw-shadow-color: rgba(15,23,42,0.3); box-shadow: var(--tw-ring-offset-shadow, 0 0 #0000), var(--tw-ring-shadow, 0 0 #0000), 0 10px 15px -3px var(--tw-shadow-color), 0 4px 6px -4px var(--tw-shadow-color); }

/* Hover background opacity */
.hover\\:bg-white\\/20:hover { background-color: rgba(255,255,255,0.2); }
.hover\\:bg-white\\/50:hover { background-color: rgba(255,255,255,0.5); }
.hover\\:bg-white\\/60:hover { background-color: rgba(255,255,255,0.6); }
.hover\\:bg-white\\/80:hover { background-color: rgba(255,255,255,0.8); }

/* Group hover states */
.group:hover .group-hover\\:text-slate-900 { color: rgb(15,23,42); }
.group:hover .group-hover\\:hover\\:bg-slate-100:hover { background-color: rgb(241,245,249); }
.group:hover .group-hover\\:rotate-180 { transform: rotate(180deg); }
.group:hover .group-hover\\:opacity-100 { opacity: 1; }
.group:hover .group-hover\\:visible { visibility: visible; }

/* Scrollbar hiding */
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* Pointer events */
.pointer-events-none { pointer-events: none; }
.pointer-events-auto { pointer-events: auto; }

/* Focus within */
.group:focus-within .group-focus-within\\:text-yellow-400 { color: rgb(250,204,21); }

/* Gradient text */
.bg-clip-text { -webkit-background-clip: text; background-clip: text; }
.text-transparent { color: transparent; }

/* Additional colors for footer presets */
.bg-indigo-950 { background-color: rgb(30, 27, 75); }
.bg-indigo-900 { background-color: rgb(49, 46, 129); }
.bg-amber-950 { background-color: rgb(69, 26, 3); }
.bg-amber-900 { background-color: rgb(120, 53, 15); }
.bg-amber-600 { background-color: rgb(217, 119, 6); }
.bg-sky-900 { background-color: rgb(12, 74, 110); }
.bg-blue-950 { background-color: rgb(23, 37, 84); }
.bg-blue-800 { background-color: rgb(30, 64, 175); }
.bg-green-800 { background-color: rgb(22, 101, 52); }
.bg-green-100 { background-color: rgb(220, 252, 231); }
.bg-stone-50 { background-color: rgb(250, 250, 249); }
.bg-stone-200 { background-color: rgb(231, 229, 228); }
.bg-stone-800 { background-color: rgb(41, 37, 36); }

.text-stone-500 { color: rgb(120, 113, 108); }
.text-stone-600 { color: rgb(87, 83, 78); }
.text-stone-700 { color: rgb(68, 64, 60); }
.text-stone-800 { color: rgb(41, 37, 36); }
.text-amber-50 { color: rgb(255, 251, 235); }
.text-amber-200 { color: rgb(253, 230, 138); }
.text-amber-400 { color: rgb(251, 191, 36); }
.text-amber-500 { color: rgb(245, 158, 11); }
.text-teal-100 { color: rgb(204, 251, 241); }
.text-teal-200 { color: rgb(153, 246, 228); }
.text-green-100 { color: rgb(220, 252, 231); }
.text-green-200 { color: rgb(187, 247, 208); }
.text-green-300 { color: rgb(134, 239, 172); }
.text-green-400 { color: rgb(74, 222, 128); }
.text-purple-500 { color: rgb(168, 85, 247); }
.text-pink-500 { color: rgb(236, 72, 153); }

.border-stone-200 { border-color: rgb(231, 229, 228); }
.border-stone-300 { border-color: rgb(214, 211, 209); }
.border-teal-700 { border-color: rgb(15, 118, 110); }
.border-green-700 { border-color: rgb(21, 128, 61); }
.border-amber-200 { border-color: rgb(253, 230, 138); }
.border-amber-400 { border-color: rgb(251, 191, 36); }
.border-indigo-900 { border-color: rgb(49, 46, 129); }

.bg-teal-500 { background-color: rgb(20, 184, 166); }
.bg-teal-600 { background-color: rgb(13, 148, 136); }
.bg-green-500 { background-color: rgb(34, 197, 94); }
.bg-purple-600 { background-color: rgb(147, 51, 234); }
.bg-purple-500 { background-color: rgb(168, 85, 247); }
.bg-pink-500 { background-color: rgb(236, 72, 153); }
.bg-pink-600 { background-color: rgb(219, 39, 119); }
.bg-red-600 { background-color: rgb(220, 38, 38); }
.bg-red-500 { background-color: rgb(239, 68, 68); }

.hover\\:bg-teal-500:hover { background-color: rgb(20, 184, 166); }
.hover\\:bg-teal-600:hover { background-color: rgb(13, 148, 136); }
.hover\\:bg-amber-400:hover { background-color: rgb(251, 191, 36); }
.hover\\:bg-amber-500:hover { background-color: rgb(245, 158, 11); }
.hover\\:bg-stone-800:hover { background-color: rgb(41, 37, 36); }
.hover\\:bg-stone-900:hover { background-color: rgb(28, 25, 23); }
.hover\\:bg-purple-500:hover { background-color: rgb(168, 85, 247); }
.hover\\:bg-pink-500:hover { background-color: rgb(236, 72, 153); }
.hover\\:bg-red-500:hover { background-color: rgb(239, 68, 68); }
.hover\\:bg-green-400:hover { background-color: rgb(74, 222, 128); }

.hover\\:text-stone-800:hover { color: rgb(41, 37, 36); }
.hover\\:text-amber-950:hover { color: rgb(69, 26, 3); }
.hover\\:text-blue-950:hover { color: rgb(23, 37, 84); }
.hover\\:text-blue-800:hover { color: rgb(30, 64, 175); }
.hover\\:text-purple-600:hover { color: rgb(147, 51, 234); }
.hover\\:text-pink-600:hover { color: rgb(219, 39, 119); }
.hover\\:text-blue-400:hover { color: rgb(96, 165, 250); }

/* Opacity variants for footer presets */
.bg-white\\/10 { background-color: rgba(255,255,255,0.1); }
.bg-white\\/20 { background-color: rgba(255,255,255,0.2); }
.bg-amber-900\\/50 { background-color: rgba(120,53,15,0.5); }
.bg-green-500\\/20 { background-color: rgba(34,197,94,0.2); }
.border-white\\/10 { border-color: rgba(255,255,255,0.1); }
.border-white\\/20 { border-color: rgba(255,255,255,0.2); }
.border-green-500\\/30 { border-color: rgba(34,197,94,0.3); }
.border-amber-200\\/20 { border-color: rgba(253,230,138,0.2); }
.text-white\\/70 { color: rgba(255,255,255,0.7); }
.text-white\\/80 { color: rgba(255,255,255,0.8); }
.text-white\\/50 { color: rgba(255,255,255,0.5); }
.text-amber-200\\/80 { color: rgba(253,230,138,0.8); }

/* Marquee animation for entertainment footer */
@keyframes marquee {
  0% { transform: translateX(0); }
  100% { transform: translateX(-50%); }
}

/* Pulse animation for status indicator */
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
.animate-pulse { animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite; }

/* Font families for footer presets */
.font-serif { font-family: 'Playfair Display', 'Lora', Georgia, serif; }
`

// Lucide icons CSS - only Lucide-specific styles
const lucideCSS = `
/* Style i[data-lucide] elements - Lucide will replace them with SVGs */
i[data-lucide] {
  display: inline-block;
  width: 1em;
  height: 1em;
  vertical-align: middle;
  background-color: currentColor;
  -webkit-mask-size: 100% 100%;
  mask-size: 100% 100%;
  -webkit-mask-repeat: no-repeat;
  mask-repeat: no-repeat;
  min-width: 16px;
  min-height: 16px;
}

/* Lucide SVGs styling */
svg.lucide {
  display: inline-block;
  vertical-align: middle;
  width: 1em;
  height: 1em;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
  fill: none;
  min-width: 16px;
  min-height: 16px;
  pointer-events: all !important;
}

/* Make icons selectable in editor */
i[data-lucide], svg.lucide {
  cursor: pointer;
  position: relative;
}

/* Icon wrapper for better selectability */
.lucide-icon-wrapper {
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  min-height: 24px;
  cursor: pointer;
  position: relative;
}
.lucide-icon-wrapper:hover {
  outline: 2px dashed rgba(42, 157, 143, 0.5);
  outline-offset: 2px;
}

/* ============================================
   SVG Selectability - Ensure SVGs inside buttons/links are clickable
   ============================================ */

/* Make all SVGs selectable and clickable */
svg {
  pointer-events: all !important;
  cursor: pointer;
  position: relative;
  z-index: 1;
}

/* Ensure SVGs inside buttons and links are selectable */
button svg,
a svg,
[type="button"] svg,
[type="submit"] svg {
  pointer-events: all !important;
  cursor: pointer;
  position: relative;
  z-index: 2;
}

/* GrapesJS SVG selectable class */
svg.gjs-svg-selectable {
  pointer-events: all !important;
  cursor: pointer;
}

/* General size classes */
.w-4 { width: 1rem; }
.h-4 { height: 1rem; }
.w-5 { width: 1.25rem; }
.h-5 { height: 1.25rem; }
.w-6 { width: 1.5rem; }
.h-6 { height: 1.5rem; }
.w-7 { width: 1.75rem; }
.h-7 { height: 1.75rem; }
.w-8 { width: 2rem; }
.h-8 { height: 2rem; }
`

export const canvasConfig = {
  styles: [
    'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css',
    // Google Fonts
    'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Roboto:wght@300;400;500;700&family=Open+Sans:wght@300;400;500;600;700&family=Lato:wght@300;400;700&family=Montserrat:wght@300;400;500;600;700&family=Poppins:wght@300;400;500;600;700&family=Raleway:wght@300;400;500;600;700&family=Nunito:wght@300;400;500;600;700&family=Playfair+Display:wght@400;500;600;700&family=Merriweather:wght@300;400;700&family=Source+Sans+Pro:wght@300;400;600;700&family=Ubuntu:wght@300;400;500;700&family=Oswald:wght@300;400;500;600;700&family=Quicksand:wght@300;400;500;600;700&family=Work+Sans:wght@300;400;500;600;700&family=DM+Sans:wght@400;500;700&family=Space+Grotesk:wght@300;400;500;600;700&family=Manrope:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=Lora:wght@400;500;600;700&display=swap',
    // Lucide icons CSS
    'data:text/css,' + encodeURIComponent(lucideCSS),
    // Custom Tailwind CSS
    'data:text/css,' + encodeURIComponent(customTailwindCSS),
  ],
  scripts: [
    'https://unpkg.com/lucide@latest/dist/umd/lucide.min.js',
  ],
}
