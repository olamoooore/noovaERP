// Presets Panel for GrapesJS Editor
import { Editor } from 'grapesjs'
import { headerPresets } from '../headerPresets'
import { footerPresets } from '../footerPresets'
import { heroPresets } from '../heroPresets'
import { pricingPresets } from '../pricingPresets'
import { cardPresets, CardPreset } from '../cardPresets'
import { sectionPresets, SectionPreset } from '../sectionPresets'
import { themePresets, loadThemeContent, ThemePreset } from '../themePresets'
import { testimonialPresets } from '../testimonialPresets'
import { faqPresets } from '../faqPresets'
import { ctaPresets } from '../ctaPresets'
import { tabPresets } from '../tabPresets'
import { bannerPresets } from '../bannerPresets'
import { productPresets } from '../productPresets'
import { initLucideIcons } from '../utils/lucide'
import { Preset } from '../types'

// Helper to parse inline style string to object
function parseInlineStyle(styleStr: string): Record<string, string> {
  const result: Record<string, string> = {}
  if (!styleStr) return result
  
  styleStr.split(';').forEach(rule => {
    const [prop, val] = rule.split(':').map(s => s.trim())
    if (prop && val) {
      // Convert kebab-case to camelCase
      const camelProp = prop.replace(/-([a-z])/g, (_, c) => c.toUpperCase())
      result[camelProp] = val
    }
  })
  return result
}

// Category icons for presets
const CATEGORY_ICONS = {
  header: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 4h18v4H3V4zm0 6h18v10H3V10z" opacity="0.3"/><path d="M3 4h18v4H3z"/></svg>',
    search: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /><path d="M3 4h18" stroke-width="1" opacity="0.3"/></svg>',
    corporate: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" /><path d="M3 4h18" stroke-width="1" opacity="0.3"/></svg>',
    mega: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 6h16v2H4zm0 5h16v2H4zm0 5h16v2H4z"/></svg>',
    minimal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 12h18v2H3zm0-7h18v2H3zm0 14h18v2H3z"/></svg>',
  },
  footer: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M19 2H5a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V4a2 2 0 0 0-2-2zm0 18H5v-4h14v4z"/></svg>',
    minimal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M5 15h14v4H5v-4z"/></svg>',
    columns: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 14h4v6H4v-6zm6 0h4v6h-4v-6zm6 0h4v6h-4v-6z"/></svg>',
    newsletter: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
    social: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M8.684 13.342C8.886 12.938 9 12.482 9 12c0-.482-.114-.938-.316-1.342m0 2.684a3 3 0 110-2.684m0 2.684l6.632 3.316m-6.632-6l6.632-3.316m0 0a3 3 0 105.367-2.684 3 3 0 00-5.367 2.684zm0 9.316a3 3 0 105.368 2.684 3 3 0 00-5.368-2.684z"/></svg>',
    map: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>',
    app: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18l9-9m-9 9l-9-9m9 9v-16"/></svg>',
  },
  hero: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>',
    saas: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="2" y="3" width="20" height="14" rx="2" /><path d="M8 21h8" /><path d="M12 17v4" /></svg>',
    split: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 4h7v16H4V4zm9 0h7v16h-7V4z"/></svg>',
    dark: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2" fill="currentColor"/><path d="M12 7a5 5 0 1 0 0 10 5 5 0 0 0 0-10z" fill="white"/></svg>',
    agency: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" /></svg>',
    app: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="5" y="2" width="14" height="20" rx="2" /><path d="M12 18h.01" /></svg>',
    video: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M10 9v6l5-3-5-3z"/><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2"/></svg>',
    minimal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><path d="M4 9h16v2H4V9zm4 4h8v2H8v-2z"/></svg>',
    fashion: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>',
  },
  pricing: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M12 2v20M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>',
    linear: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="4" width="5" height="16" rx="1"/><rect x="10" y="8" width="5" height="12" rx="1"/><rect x="17" y="2" width="5" height="18" rx="1"/></svg>',
    bento: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg>',
    stack: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
    spotlight: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="12" cy="12" r="3"/><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>',
  },
  cards: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2"/></svg>',
    stats: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M18 20V10M12 20V4M6 20v-6"/></svg>',
    profile: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="12" cy="8" r="4"/><path d="M20 21a8 8 0 10-16 0"/></svg>',
    task: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"/></svg>',
    feature: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
    social: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
    media: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="12" cy="12" r="10"/><polygon points="10 8 16 12 10 16 10 8"/></svg>',
    ecommerce: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>',
    alert: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>',
    schedule: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>',
    settings: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
    gamification: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
    widget: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
  },
  sections: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
    glass: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="8" height="8" rx="2" opacity="0.3"/><rect x="13" y="3" width="8" height="8" rx="2" opacity="0.5"/><rect x="3" y="13" width="8" height="8" rx="2" opacity="0.5"/><rect x="13" y="13" width="8" height="8" rx="2" opacity="0.3"/></svg>',
    timeline: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="12" cy="4" r="2"/><circle cx="12" cy="12" r="2"/><circle cx="12" cy="20" r="2"/><path d="M12 6v4M12 14v4"/></svg>',
    bento: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="10" height="10" rx="2"/><rect x="15" y="3" width="6" height="6" rx="1"/><rect x="15" y="11" width="6" height="10" rx="1"/><rect x="3" y="15" width="10" height="6" rx="1"/></svg>',
    accordion: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="18" height="4" rx="1"/><rect x="3" y="10" width="18" height="4" rx="1"/><rect x="3" y="17" width="18" height="4" rx="1"/></svg>',
  },
  testimonials: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M8 9h8"/><path d="M8 13h6"/></svg>',
    grid: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg>',
    spotlight: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="12" cy="12" r="10"/><path d="M12 8v4l2 2"/></svg>',
    marquee: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M3 12h18"/><path d="M7 8l-4 4 4 4"/><path d="M17 8l4 4-4 4"/></svg>',
  },
  faqs: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    grid: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg>',
    split: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><line x1="12" y1="3" x2="12" y2="21"/><line x1="3" y1="9" x2="9" y2="9"/><line x1="3" y1="15" x2="9" y2="15"/><line x1="15" y1="7" x2="21" y2="7"/><line x1="15" y1="12" x2="21" y2="12"/><line x1="15" y1="17" x2="21" y2="17"/></svg>',
    terminal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/></svg>',
    glass: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="4" y="4" width="16" height="16" rx="4" fill="none" stroke="currentColor" stroke-width="2"/><rect x="6" y="8" width="12" height="2" rx="1" opacity="0.5"/><rect x="6" y="12" width="12" height="2" rx="1" opacity="0.3"/></svg>',
    minimal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/><text x="20" y="8" font-size="6" fill="currentColor">+</text></svg>',
    pop: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="4" width="18" height="6" fill="#FFD23F" stroke="#000" stroke-width="2"/><rect x="3" y="12" width="18" height="6" fill="#fff" stroke="#000" stroke-width="2"/></svg>',
    dark: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="4" fill="#1a1a2e"/><circle cx="12" cy="12" r="4" fill="#4f46e5" opacity="0.6"/></svg>',
  },
  tabs: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
    capsule: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="4" y="8" width="16" height="8" rx="4"/></svg>',
    neon: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M4 12h16" stroke="#0ff"/><path d="M4 6h6M14 6h6"/></svg>',
    vertical: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="6" height="18" rx="1"/><path d="M12 6h9M12 12h9M12 18h9"/></svg>',
    brutalist: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="6" height="4"/><rect x="10" y="3" width="6" height="4"/><rect x="3" y="9" width="18" height="12"/></svg>',
    minimal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M8 6h8M12 10v2"/><circle cx="12" cy="16" r="2"/></svg>',
    gradient: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="18" height="6" rx="1" opacity="0.3"/><rect x="3" y="11" width="18" height="10" rx="1"/></svg>',
    terminal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M7 8l4 4-4 4"/><path d="M13 16h4"/></svg>',
    floating: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="4" y="4" width="5" height="5" rx="2"/><rect x="10" y="4" width="5" height="5" rx="2"/><rect x="16" y="4" width="5" height="5" rx="2"/><rect x="3" y="12" width="18" height="9" rx="2" opacity="0.5"/></svg>',
    mesh: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M4 12h16" stroke="url(#grad)"/><defs><linearGradient id="grad"><stop offset="0%" stop-color="#ec4899"/><stop offset="100%" stop-color="#8b5cf6"/></linearGradient></defs></svg>',
  },
  products: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 002 1.61h9.72a2 2 0 002-1.61L23 6H6"/></svg>',
    hero: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M9 21V9"/></svg>',
    feature: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>',
    carousel: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="2" y="6" width="8" height="12" rx="1" opacity="0.3"/><rect x="6" y="4" width="12" height="14" rx="1" opacity="0.6"/><rect x="10" y="2" width="8" height="16" rx="1"/></svg>',
    gallery: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>',
    specs: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/></svg>',
    cta: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M8 12h8M12 9v6"/></svg>',
  },
  banners: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="2" y="4" width="20" height="16" rx="2"/><polygon points="10 9 15 12 10 15 10 9" fill="currentColor"/></svg>',
    cinematic: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 8h20M2 16h20" stroke="currentColor" stroke-width="1" opacity="0.3"/><polygon points="10 10 15 12 10 14 10 10" fill="white"/></svg>',
    glass: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="2" y="4" width="20" height="16" rx="2" opacity="0.3"/><rect x="4" y="8" width="8" height="8" rx="2" fill="currentColor" opacity="0.5"/></svg>',
    split: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="2" y="4" width="10" height="16" fill="white" stroke="currentColor" stroke-width="1"/><rect x="12" y="4" width="10" height="16" opacity="0.5"/></svg>',
    tint: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="2" y="4" width="20" height="16" rx="2" fill="#6366f1" opacity="0.7"/><circle cx="12" cy="12" r="4" fill="white" opacity="0.8"/></svg>',
    fashion: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="2" y="2" width="20" height="20" rx="2"/><path d="M4 18h8v2H4z" fill="currentColor"/></svg>',
    app: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="2" y="4" width="20" height="12" rx="2" opacity="0.3"/><rect x="4" y="14" width="16" height="6" rx="1"/></svg>',
    // Image-based banner icons
    editorial: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="2" y="4" width="10" height="16" rx="2"/><rect x="8" y="8" width="14" height="10" rx="2" fill="currentColor" opacity="0.3"/></svg>',
    typography: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="2" y="4" width="20" height="16" rx="2" fill="#000"/><text x="12" y="15" text-anchor="middle" font-size="10" font-weight="bold" fill="url(#imgGrad)">AB</text><defs><linearGradient id="imgGrad"><stop offset="0%" stop-color="#22c55e"/><stop offset="100%" stop-color="#3b82f6"/></linearGradient></defs></svg>',
    blob: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><ellipse cx="14" cy="12" rx="8" ry="7" fill="#f8fafc" stroke="currentColor" stroke-width="1"/><path d="M4 8h6v8H4z" fill="currentColor" opacity="0.3"/></svg>',
    monochrome: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="2" y="4" width="20" height="16" rx="2" fill="#666"/><rect x="6" y="8" width="12" height="8" rx="1" fill="none" stroke="white" stroke-width="1"/></svg>',
    perspective: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><path d="M4 6l16 2v10l-16 2z" fill="currentColor" opacity="0.3"/><circle cx="6" cy="6" r="3" opacity="0.2"/></svg>',
    diagonal: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="M2 4l10 16H2z" fill="#7c3aed" opacity="0.8"/></svg>',
  },
  ctas: {
    default: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.7"><rect x="3" y="6" width="18" height="12" rx="2"/><path d="M8 12h8M12 9v6"/></svg>',
    orange: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="6" width="18" height="12" rx="6" fill="#ff5500"/><circle cx="16" cy="12" r="4" fill="white" opacity="0.3"/></svg>',
    stats: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="8" height="18" rx="2" fill="#0a2e18"/><rect x="13" y="8" width="8" height="8" rx="100" fill="#10b981" opacity="0.5"/></svg>',
    glass: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="4" y="4" width="16" height="16" rx="4" fill="#7b2cbf" opacity="0.3"/><rect x="6" y="6" width="12" height="12" rx="2" fill="white" opacity="0.1" stroke="white" stroke-width="1"/></svg>',
    cyber: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="#00f3ff" stroke-width="2" style="opacity: 0.7"><rect x="3" y="6" width="18" height="12"/><path d="M3 6l3-3h12l3 3" stroke="#ff00ff"/></svg>',
    bento: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="8" height="18" rx="2" fill="#111"/><rect x="13" y="3" width="8" height="8" rx="2" fill="#ff4757"/><rect x="13" y="13" width="8" height="8" rx="2" fill="#e0e0e0"/></svg>',
    gradient: '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" style="opacity: 0.7"><rect x="3" y="6" width="18" height="12" rx="4" stroke="url(#ctaGrad)" stroke-width="2"/><defs><linearGradient id="ctaGrad"><stop offset="0%" stop-color="#ff00cc"/><stop offset="100%" stop-color="#3333ff"/></linearGradient></defs></svg>',
    newsletter: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="8" width="18" height="8" rx="4" fill="#2b2b36"/><rect x="14" y="10" width="5" height="4" rx="2" fill="#6c63ff"/></svg>',
    marquee: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="0" y="8" width="24" height="8" fill="#ffc300"/><text x="12" y="14" text-anchor="middle" font-size="6" font-weight="bold" fill="#000">ABC</text></svg>',
    shapes: '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" style="opacity: 0.7"><rect x="3" y="3" width="18" height="18" rx="2" fill="#0f172a"/><circle cx="8" cy="8" r="4" fill="#3b82f6" opacity="0.6"/><circle cx="16" cy="16" r="5" fill="#ec4899" opacity="0.6"/></svg>',
  },
}

function getIconForPreset(presetId: string, category: 'header' | 'footer' | 'hero' | 'pricing' | 'cards' | 'products'): string {
  if (category === 'header') {
    const icons = CATEGORY_ICONS.header
    if (presetId.includes('search')) return icons.search
    if (presetId.includes('corporate')) return icons.corporate
    if (presetId.includes('mega') || presetId.includes('stacked')) return icons.mega
    if (presetId.includes('minimal')) return icons.minimal
    return icons.default
  } else if (category === 'footer') {
    const icons = CATEGORY_ICONS.footer
    if (presetId.includes('minimal')) return icons.minimal
    if (presetId.includes('4-columns') || presetId.includes('complex')) return icons.columns
    if (presetId.includes('newsletter')) return icons.newsletter
    if (presetId.includes('social')) return icons.social
    if (presetId.includes('map')) return icons.map
    if (presetId.includes('app')) return icons.app
    return icons.default
  } else if (category === 'pricing') {
    const icons = CATEGORY_ICONS.pricing
    if (presetId.includes('linear')) return icons.linear
    if (presetId.includes('bento') || presetId.includes('glass')) return icons.bento
    if (presetId.includes('stack')) return icons.stack
    if (presetId.includes('spotlight')) return icons.spotlight
    return icons.default
  } else if (category === 'cards') {
    const icons = CATEGORY_ICONS.cards
    if (presetId.includes('analytics') || presetId.includes('stats')) return icons.stats
    if (presetId.includes('profile') || presetId.includes('team')) return icons.profile
    if (presetId.includes('project') || presetId.includes('task')) return icons.task
    if (presetId.includes('lifestyle') || presetId.includes('feature')) return icons.feature
    if (presetId.includes('testimonial') || presetId.includes('chat') || presetId.includes('social')) return icons.social
    if (presetId.includes('music') || presetId.includes('media')) return icons.media
    if (presetId.includes('product') || presetId.includes('checkout') || presetId.includes('ecommerce')) return icons.ecommerce
    if (presetId.includes('notification') || presetId.includes('alert')) return icons.alert
    if (presetId.includes('calendar') || presetId.includes('schedule')) return icons.schedule
    if (presetId.includes('settings')) return icons.settings
    if (presetId.includes('achievement') || presetId.includes('badge')) return icons.gamification
    if (presetId.includes('weather') || presetId.includes('widget')) return icons.widget
    if (presetId.includes('pricing')) return CATEGORY_ICONS.pricing.default
    return icons.default
  } else if (category === 'products') {
    return getProductsIcon(presetId)
  } else {
    const icons = CATEGORY_ICONS.hero
    if (presetId.includes('saas') || presetId.includes('product')) return icons.saas
    if (presetId.includes('split')) return icons.split
    if (presetId.includes('dark')) return icons.dark
    if (presetId.includes('agency') || presetId.includes('creative')) return icons.agency
    if (presetId.includes('app')) return icons.app
    if (presetId.includes('video')) return icons.video
    if (presetId.includes('minimal')) return icons.minimal
    if (presetId.includes('fashion') || presetId.includes('ecommerce')) return icons.fashion
    return icons.default
  }
}

function getTestimonialIcon(presetId: string): string {
  const icons = CATEGORY_ICONS.testimonials
  if (presetId.includes('masonry') || presetId.includes('grid')) return icons.grid
  if (presetId.includes('spotlight') || presetId.includes('glass')) return icons.spotlight
  if (presetId.includes('stream') || presetId.includes('marquee')) return icons.marquee
  return icons.default
}

function getFAQIcon(presetId: string): string {
  const icons = CATEGORY_ICONS.faqs
  if (presetId.includes('bento') || presetId.includes('grid')) return icons.grid
  if (presetId.includes('split') || presetId.includes('nexus')) return icons.split
  if (presetId.includes('estate') || presetId.includes('tabs') || presetId.includes('vertical')) return icons.split
  if (presetId.includes('terminal') || presetId.includes('neon')) return icons.terminal
  if (presetId.includes('glass') || presetId.includes('focus')) return icons.glass
  if (presetId.includes('swiss') || presetId.includes('minimal')) return icons.minimal
  if (presetId.includes('pop') || presetId.includes('neo')) return icons.pop
  if (presetId.includes('dark') || presetId.includes('stack')) return icons.dark
  return icons.default
}

function getCTAIcon(presetId: string): string {
  const icons = CATEGORY_ICONS.ctas
  if (presetId.includes('orange') || presetId.includes('ripple')) return icons.orange
  if (presetId.includes('arch') || presetId.includes('stats') || presetId.includes('dark')) return icons.stats
  if (presetId.includes('glass') || presetId.includes('glow')) return icons.glass
  if (presetId.includes('cyber') || presetId.includes('neon')) return icons.cyber
  if (presetId.includes('bento') || presetId.includes('grid')) return icons.bento
  if (presetId.includes('gradient') || presetId.includes('border')) return icons.gradient
  if (presetId.includes('newsletter') || presetId.includes('input') || presetId.includes('widget')) return icons.newsletter
  if (presetId.includes('marquee') || presetId.includes('typographic')) return icons.marquee
  if (presetId.includes('3d') || presetId.includes('shapes') || presetId.includes('abstract')) return icons.shapes
  return icons.default
}

function getTabIcon(presetId: string): string {
  const icons = CATEGORY_ICONS.tabs
  if (presetId.includes('capsule') || presetId.includes('sliding')) return icons.capsule
  if (presetId.includes('neon') || presetId.includes('cyberpunk')) return icons.neon
  if (presetId.includes('vertical') || presetId.includes('glass')) return icons.vertical
  if (presetId.includes('brutalist') || presetId.includes('folder')) return icons.brutalist
  if (presetId.includes('minimal') || presetId.includes('dot')) return icons.minimal
  if (presetId.includes('gradient') || presetId.includes('border')) return icons.gradient
  if (presetId.includes('terminal') || presetId.includes('dev')) return icons.terminal
  if (presetId.includes('floating') || presetId.includes('3d')) return icons.floating
  if (presetId.includes('mesh')) return icons.mesh
  if (presetId.includes('classic') || presetId.includes('basic-classic')) return icons.default
  if (presetId.includes('pill') || presetId.includes('toggle')) return icons.capsule
  if (presetId.includes('sidebar') || presetId.includes('basic-vertical')) return icons.vertical
  return icons.default
}

function getBannerIcon(presetId: string): string {
  const icons = CATEGORY_ICONS.banners
  // Video-based banners
  if (presetId.includes('cinematic')) return icons.cinematic
  if (presetId.includes('glass')) return icons.glass
  if (presetId.includes('split')) return icons.split
  if (presetId.includes('tint') || presetId.includes('brand')) return icons.tint
  if (presetId.includes('fashion')) return icons.fashion
  if (presetId.includes('app') || presetId.includes('context')) return icons.app
  // Image-based banners
  if (presetId.includes('editorial') || presetId.includes('offset')) return icons.editorial
  if (presetId.includes('typography') || presetId.includes('mask')) return icons.typography
  if (presetId.includes('organic') || presetId.includes('blob') || presetId.includes('float')) return icons.blob
  if (presetId.includes('monochrome') || presetId.includes('reveal')) return icons.monochrome
  if (presetId.includes('3d') || presetId.includes('perspective')) return icons.perspective
  if (presetId.includes('diagonal') || presetId.includes('duotone')) return icons.diagonal
  return icons.default
}



export function addPresetsPanel(editor: Editor) {
  const panelManager = editor.Panels
  const commandManager = editor.Commands
  
  // Helper function to initialize Lucide icons in the canvas
  const initCanvasLucide = () => initLucideIcons(editor)
  
  // Create Presets Container
  const presetsContainer = document.createElement('div')
  presetsContainer.className = 'gjs-presets-c'
  presetsContainer.style.display = 'none'
  presetsContainer.style.height = '100%'
  presetsContainer.style.width = '100%'
  presetsContainer.style.overflowY = 'auto'
  presetsContainer.style.overflowX = 'hidden'
  presetsContainer.style.boxSizing = 'border-box'
  
  // Define command
  commandManager.add('open-presets', {
    run(editor) {
      const container = editor.getContainer()
      if (!container) return
      
      presetsContainer.style.display = 'block'
      
      editor.Commands.stop('open-blocks')
      editor.Commands.stop('open-sm')
      editor.Commands.stop('open-tm')
      editor.Commands.stop('open-layers')
      
      panelManager.getButton('views', 'open-blocks')?.set('active', false)
      panelManager.getButton('views', 'open-sm')?.set('active', false)
      panelManager.getButton('views', 'open-tm')?.set('active', false)
      panelManager.getButton('views', 'open-layers')?.set('active', false)
    },
    stop() {
      presetsContainer.style.display = 'none'
    }
  })
  
  // Add Presets button
  panelManager.addButton('views', {
    id: 'open-presets',
    className: 'gjs-pn-btn',
    command: 'open-presets',
    attributes: { title: 'Presets' },
    active: false,
    label: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M19 2H5a3 3 0 0 0-3 3v14a3 3 0 0 0 3 3h14a3 3 0 0 0 3-3V5a3 3 0 0 0-3-3m1 17a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h14a1 1 0 0 1 1 1z" opacity="0.5"/><path fill="currentColor" d="M7 7h10v2H7zm0 4h10v2H7zm0 4h7v2H7z"/></svg>`,
  })
  
  // Define categories - ordered logically: Headers first, Footers last
  const categories = ['Headers', 'Hero', 'Sections', 'Products', 'Banners', 'CTAs', 'Tabs', 'FAQs', 'Testimonials', 'Cards', 'Pricing', 'Themes', 'Footers']
  
  categories.forEach((category) => {
    const categoryEl = document.createElement('div')
    categoryEl.className = 'gjs-block-category'
    categoryEl.style.borderBottom = '1px solid rgba(0,0,0,0.05)'
    
    // Category Header
    const titleEl = document.createElement('div')
    titleEl.className = 'gjs-title'
    titleEl.style.cursor = 'pointer'
    titleEl.style.display = 'flex'
    titleEl.style.alignItems = 'center'
    titleEl.style.padding = '10px 15px'
    titleEl.style.userSelect = 'none'
    titleEl.style.background = 'rgba(0,0,0,0.01)'
    
    // Caret Icon
    const caretEl = document.createElement('div')
    caretEl.className = 'gjs-caret-icon'
    caretEl.innerHTML = '<svg viewBox="0 0 24 24" width="14" height="14" fill="currentColor"><path d="M7 10l5 5 5-5z"/></svg>'
    caretEl.style.marginRight = '8px'
    caretEl.style.transition = 'transform 0.2s ease'
    caretEl.style.transform = 'rotate(-90deg)'
    
    titleEl.appendChild(caretEl)
    titleEl.appendChild(document.createTextNode(category))
    
    // Category Content
    const contentEl = document.createElement('div')
    contentEl.className = 'gjs-blocks-c'
    contentEl.style.padding = '8px'
    contentEl.style.display = 'none'
    
    // Populate based on category
    if (category === 'Themes') {
      contentEl.innerHTML = ''
      // 2-column grid for themes - fixed column widths
      contentEl.style.cssText = `
        display: none;
        grid-template-columns: 140px 140px;
        gap: 4px;
        padding: 4px;
      `
      
      themePresets.forEach(preset => {
        const itemEl = createThemePresetItem(preset, editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Sections') {
      contentEl.innerHTML = ''
      // Use flexbox like default GrapesJS blocks - 3 columns
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      sectionPresets.forEach((preset: SectionPreset) => {
        const itemEl = createSectionPresetItem(preset, editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Headers') {
      contentEl.innerHTML = ''
      // Use flexbox like default GrapesJS blocks - 3 columns
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      headerPresets.forEach(preset => {
        const itemEl = createPresetItem(preset, 'header', editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Banners') {
      contentEl.innerHTML = ''
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      bannerPresets.forEach(preset => {
        const itemEl = createBannerPresetItem(preset, editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Testimonials') {
      contentEl.innerHTML = ''
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      testimonialPresets.forEach(preset => {
        const itemEl = createPresetItem(preset, 'header', editor, initCanvasLucide)
        // Adjust the icon for testimonials
        const mediaEl = itemEl.querySelector('.gjs-block__media')
        if (mediaEl) mediaEl.innerHTML = getTestimonialIcon(preset.id)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'FAQs') {
      contentEl.innerHTML = ''
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      faqPresets.forEach(preset => {
        const itemEl = createFAQPresetItem(preset, editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'CTAs') {
      contentEl.innerHTML = ''
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      ctaPresets.forEach(preset => {
        const itemEl = createPresetItem(preset, 'header', editor, initCanvasLucide)
        // Adjust the icon for CTAs
        const mediaEl = itemEl.querySelector('.gjs-block__media')
        if (mediaEl) mediaEl.innerHTML = getCTAIcon(preset.id)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Tabs') {
      contentEl.innerHTML = ''
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      tabPresets.forEach(preset => {
        const itemEl = createTabPresetItem(preset, editor, initCanvasLucide)
        // Adjust the icon for Tabs
        const mediaEl = itemEl.querySelector('.gjs-block__media')
        if (mediaEl) mediaEl.innerHTML = getTabIcon(preset.id)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Pricing') {
      contentEl.innerHTML = ''
      // Use flexbox like default GrapesJS blocks - 3 columns
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      pricingPresets.forEach((preset: Preset) => {
        const itemEl = createPresetItem(preset, 'pricing', editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Cards') {
      contentEl.innerHTML = ''
      // Use flexbox like default GrapesJS blocks - 3 columns
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      cardPresets.forEach((preset: CardPreset) => {
        const itemEl = createCardPresetItem(preset, editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Footers') {
      contentEl.innerHTML = ''
      // Use flexbox like default GrapesJS blocks - 3 columns
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      footerPresets.forEach((preset: Preset) => {
        const itemEl = createPresetItem(preset, 'footer', editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Hero') {
      contentEl.innerHTML = ''
      // Use flexbox like default GrapesJS blocks - 3 columns
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      heroPresets.forEach(preset => {
        const itemEl = createPresetItem(preset, 'hero', editor, initCanvasLucide)
        contentEl.appendChild(itemEl)
      })
    } else if (category === 'Products') {
      contentEl.innerHTML = ''
      contentEl.style.cssText = `
        display: none;
        flex-wrap: wrap;
        padding: 10px;
      `
      
      productPresets.forEach((preset: Preset) => {
        const itemEl = createPresetItem(preset, 'products', editor, initCanvasLucide)
        const mediaEl = itemEl.querySelector('.gjs-block__media')
        if (mediaEl) mediaEl.innerHTML = getProductsIcon(preset.id)
        contentEl.appendChild(itemEl)
      })
    } else {
      contentEl.innerHTML = `<div style="width: 100%; padding: 20px 0; color: #888; font-size: 12px; text-align: center;">No presets available</div>`
    }
    
    // Toggle Logic
    const toggle = (forceOpen?: boolean) => {
      const isOpen = forceOpen !== undefined ? forceOpen : contentEl.style.display === 'none'
      
      if (isOpen) {
        // Themes use grid, others use flex
        contentEl.style.display = category === 'Themes' ? 'grid' : 'flex'
      } else {
        contentEl.style.display = 'none'
      }

      caretEl.style.transform = isOpen ? 'rotate(0deg)' : 'rotate(-90deg)'
      categoryEl.classList.toggle('gjs-open', isOpen)
    }

    titleEl.onclick = () => toggle()
    
    categoryEl.appendChild(titleEl)
    categoryEl.appendChild(contentEl)
    presetsContainer.appendChild(categoryEl)
    
    // All categories start closed - user opens what they need
  })
  
  // Append to views container
  const appendToContainer = () => {
    const viewsContainer = editor.getContainer()?.querySelector('.gjs-pn-views-container')
    if (viewsContainer) {
      viewsContainer.appendChild(presetsContainer)
    } else {
      setTimeout(appendToContainer, 100)
    }
  }
  
  appendToContainer()

  // Listen to other commands to hide presets
  const hidePresets = () => {
    presetsContainer.style.display = 'none'
    panelManager.getButton('views', 'open-presets')?.set('active', false)
  }
  
  editor.on('run:open-blocks', hidePresets)
  editor.on('run:open-sm', hidePresets)
  editor.on('run:open-tm', hidePresets)
  editor.on('run:open-layers', hidePresets)
}

// Create theme preset item with actual screenshot preview
function createThemePresetItem(
  preset: ThemePreset,
  editor: Editor,
  initCanvasLucide: () => void
): HTMLElement {
  const itemEl = document.createElement('div')
  itemEl.className = 'gjs-block theme-preset-item'
  itemEl.style.cssText = `
    cursor: pointer;
    border: 1px solid rgba(255,255,255,0.1);
    border-radius: 4px;
    overflow: hidden;
    transition: all 0.2s ease;
    background: rgba(255,255,255,0.05);
    display: flex;
    flex-direction: column;
    width: 140px !important;
  `
  
  // Thumbnail container
  const thumbnailContainer = document.createElement('div')
  thumbnailContainer.style.cssText = `
    width: 100%;
    aspect-ratio: 16/10;
    position: relative;
    background: #1a1a1a;
    overflow: hidden;
  `
  
  // Thumbnail image - actual screenshot
  const thumbnailImg = document.createElement('img')
  thumbnailImg.src = preset.thumbnail
  thumbnailImg.alt = preset.label
  thumbnailImg.loading = 'lazy'
  thumbnailImg.style.cssText = `
    width: 100%;
    height: 100%;
    object-fit: cover;
    object-position: top;
  `
  thumbnailContainer.appendChild(thumbnailImg)
  
  // Info section
  const infoEl = document.createElement('div')
  infoEl.style.cssText = `
    padding: 10px;
    background: rgba(0,0,0,0.4);
  `
  
  // Label
  const labelEl = document.createElement('div')
  labelEl.textContent = preset.label
  labelEl.style.cssText = `
    font-size: 12px;
    font-weight: 600;
    color: #fff;
    margin-bottom: 3px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  `
  
  // Description
  const descEl = document.createElement('div')
  descEl.textContent = preset.description
  descEl.style.cssText = `
    font-size: 10px;
    color: rgba(255,255,255,0.6);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  `
  
  infoEl.appendChild(labelEl)
  infoEl.appendChild(descEl)
  
  itemEl.appendChild(thumbnailContainer)
  itemEl.appendChild(infoEl)
  
  // Hover effect
  itemEl.onmouseenter = () => {
    itemEl.style.borderColor = '#2a9d8f'
    itemEl.style.transform = 'scale(1.02)'
  }
  itemEl.onmouseleave = () => {
    itemEl.style.borderColor = 'rgba(255,255,255,0.1)'
    itemEl.style.transform = 'scale(1)'
  }

  // Click to load theme
  itemEl.onclick = async () => {
    // Show loading state
    itemEl.style.opacity = '0.5'
    itemEl.style.pointerEvents = 'none'
    
    try {
      // Load the theme content
      const content = await loadThemeContent(preset.id)
      
      // Parse the HTML to extract components
      const parser = new DOMParser()
      const doc = parser.parseFromString(content, 'text/html')
      
      // Get all styles (inline)
      const styleElements = doc.querySelectorAll('style')
      const cssStyles: string[] = []
      styleElements.forEach(style => {
        cssStyles.push(style.innerHTML)
      })
      
      // Get external stylesheet URLs
      const externalStyles: string[] = []
      doc.querySelectorAll('link[rel="stylesheet"]').forEach(link => {
        const href = link.getAttribute('href')
        if (href && !href.startsWith('data:')) {
          externalStyles.push(href)
        }
      })
      
      // Get external script URLs
      const externalScripts: string[] = []
      doc.querySelectorAll('script[src]').forEach(script => {
        const src = script.getAttribute('src')
        if (src) externalScripts.push(src)
      })
      
      // Get inline scripts (including tailwind config)
      const inlineScripts: string[] = []
      doc.querySelectorAll('script:not([src])').forEach(script => {
        const code = script.innerHTML.trim()
        if (code) inlineScripts.push(code)
      })
      
      // Get body content
      const bodyContent = doc.body.innerHTML
      const bodyClass = doc.body.className || ''
      const bodyStyle = doc.body.getAttribute('style') || ''
      
      // Get html class (for scroll-smooth etc)
      const htmlClass = doc.documentElement.className || ''
      
      // Clear editor
      editor.DomComponents.clear()
      
      // Set the body content as components
      const wrapper = editor.getWrapper()
      if (wrapper) {
        // Apply body classes to wrapper
        if (bodyClass) {
          wrapper.addClass(bodyClass)
        }
        if (bodyStyle) {
          const currentStyle = wrapper.getStyle() || {}
          wrapper.setStyle({ ...currentStyle, ...parseInlineStyle(bodyStyle) })
        }
      }
      
      // Add components
      editor.setComponents(bodyContent)
      
      // Set CSS
      editor.setStyle(cssStyles.join('\n'))
      
      // Get canvas document
      const canvasFrame = editor.Canvas.getFrameEl()
      const canvasDoc = canvasFrame?.contentDocument
      const canvasHead = canvasDoc?.head
      const canvasHtml = canvasDoc?.documentElement
      
      if (canvasHtml && htmlClass) {
        canvasHtml.className = htmlClass
      }
      
      if (canvasHead && canvasDoc) {
        // Clear existing dynamic resources
        canvasHead.querySelectorAll('[data-theme-resource]').forEach(el => el.remove())
        
        // Add external stylesheets
        for (const href of externalStyles) {
          const link = canvasDoc.createElement('link')
          link.rel = 'stylesheet'
          link.href = href
          link.setAttribute('data-theme-resource', 'true')
          canvasHead.appendChild(link)
        }
        
        // Add external scripts
        for (const src of externalScripts) {
          const script = canvasDoc.createElement('script')
          script.src = src
          script.setAttribute('data-theme-resource', 'true')
          canvasHead.appendChild(script)
        }
        
        // Wait for scripts to load then execute inline scripts
        setTimeout(() => {
          for (const code of inlineScripts) {
            try {
              const script = canvasDoc.createElement('script')
              script.setAttribute('data-theme-resource', 'true')
              script.textContent = code
              canvasDoc.body.appendChild(script)
            } catch (e) {
              console.warn('Script error:', e)
            }
          }
          
          // Re-initialize Lucide after scripts
          setTimeout(() => {
            initCanvasLucide()
            // Try to call lucide.createIcons if available
            try {
              const canvasWindow = canvasFrame?.contentWindow as any
              if (canvasWindow?.lucide?.createIcons) {
                canvasWindow.lucide.createIcons()
              }
            } catch (e) {
              // Ignore
            }
          }, 500)
        }, 1000)
      }
      
      // Initialize Lucide icons
      setTimeout(initCanvasLucide, 300)
      
      console.log('Theme loaded:', preset.label)
      
    } catch (error) {
      console.error('Error loading theme:', error)
      alert('Failed to load theme. Please try again.')
    } finally {
      itemEl.style.opacity = '1'
      itemEl.style.pointerEvents = 'auto'
    }
  }
  
  return itemEl
}

function getProductsIcon(presetId: string): string {
  const icons = CATEGORY_ICONS.products
  if (presetId.includes('hero')) return icons.hero
  if (presetId.includes('feature') || presetId.includes('single')) return icons.feature
  if (presetId.includes('carousel')) return icons.carousel
  if (presetId.includes('gallery')) return icons.gallery
  if (presetId.includes('specs') || presetId.includes('table')) return icons.specs
  if (presetId.includes('cta')) return icons.cta
  return icons.default
}

function createPresetItem(
  preset: Preset,
  category: 'header' | 'footer' | 'hero' | 'pricing' | 'products' | 'cards',
  editor: Editor,
  initCanvasLucide: () => void
): HTMLElement {
  const itemEl = document.createElement('div')
  itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
  itemEl.title = preset.label
  itemEl.setAttribute('draggable', 'true')
  // Use calc to get 3 columns with gaps like default GrapesJS blocks
  itemEl.style.cssText = `
    width: calc(33.333% - 4px);
    margin: 2px;
    min-height: 70px;
  `
  
  // Media container (icon)
  const mediaEl = document.createElement('div')
  mediaEl.className = 'gjs-block__media'
  mediaEl.innerHTML = getIconForPreset(preset.id, category)
  
  // Label
  const labelEl = document.createElement('div')
  labelEl.className = 'gjs-block-label'
  labelEl.textContent = preset.label
  
  itemEl.appendChild(mediaEl)
  itemEl.appendChild(labelEl)

  // Drag functionality
  itemEl.addEventListener('dragstart', (e) => {
    e.dataTransfer?.setData('text/html', preset.content)
  })

  // Click to append
  itemEl.onclick = () => {
    editor.addComponents(preset.content)
    setTimeout(initCanvasLucide, 200)
    setTimeout(initCanvasLucide, 500)
  }
  
  return itemEl
}

// Generate unique ID for tab presets
let tabPresetCounter = 0
function generateUniqueTabContent(content: string): string {
  const uniqueId = `tab${Date.now()}${tabPresetCounter++}`
  
  // Replace all static IDs with unique ones
  // Match patterns like: data-tab-group="capsule1", href="#capsule1-a", id="capsule1-a"
  // and the querySelector in the script
  
  // Find the data-tab-group value
  const tabGroupMatch = content.match(/data-tab-group="([^"]+)"/)
  if (!tabGroupMatch) return content
  
  const oldGroupId = tabGroupMatch[1]
  
  // Replace all occurrences of the old group ID with the new unique ID
  let newContent = content
    .replace(new RegExp(`data-tab-group="${oldGroupId}"`, 'g'), `data-tab-group="${uniqueId}"`)
    .replace(new RegExp(`href="#${oldGroupId}`, 'g'), `href="#${uniqueId}`)
    .replace(new RegExp(`id="${oldGroupId}`, 'g'), `id="${uniqueId}`)
    .replace(new RegExp(`querySelector\\('\\[data-tab-group="${oldGroupId}"\\]'\\)`, 'g'), `querySelector('[data-tab-group="${uniqueId}"]')`)
    .replace(new RegExp(`#${oldGroupId}-`, 'g'), `#${uniqueId}-`)
  
  return newContent
}

// Generate unique IDs for FAQ presets (for details/summary elements and tab navigation)
let faqPresetCounter = 0
function generateUniqueFAQContent(content: string): string {
  const uniqueId = `faq${Date.now()}${faqPresetCounter++}`
  
  // Replace FAQ tab IDs (for vertical tabs FAQ)
  let newContent = content
    .replace(/data-faq-tabs="([^"]+)"/g, `data-faq-tabs="${uniqueId}"`)
    .replace(/href="#faq-general"/g, `href="#${uniqueId}-general"`)
    .replace(/href="#faq-billing"/g, `href="#${uniqueId}-billing"`)
    .replace(/href="#faq-technical"/g, `href="#${uniqueId}-technical"`)
    .replace(/href="#faq-privacy"/g, `href="#${uniqueId}-privacy"`)
    .replace(/id="faq-general"/g, `id="${uniqueId}-general"`)
    .replace(/id="faq-billing"/g, `id="${uniqueId}-billing"`)
    .replace(/id="faq-technical"/g, `id="${uniqueId}-technical"`)
    .replace(/id="faq-privacy"/g, `id="${uniqueId}-privacy"`)
    .replace(/#faq-general/g, `#${uniqueId}-general`)
    .replace(/#faq-billing/g, `#${uniqueId}-billing`)
    .replace(/#faq-technical/g, `#${uniqueId}-technical`)
    .replace(/#faq-privacy/g, `#${uniqueId}-privacy`)
  
  return newContent
}

// Create tab preset item with unique IDs
function createTabPresetItem(
  preset: Preset,
  editor: Editor,
  initCanvasLucide: () => void
): HTMLElement {
  const itemEl = document.createElement('div')
  itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
  itemEl.title = preset.label
  itemEl.setAttribute('draggable', 'true')
  itemEl.style.cssText = `
    width: calc(33.333% - 4px);
    margin: 2px;
    min-height: 70px;
  `
  
  // Media container (icon)
  const mediaEl = document.createElement('div')
  mediaEl.className = 'gjs-block__media'
  mediaEl.innerHTML = getIconForPreset(preset.id, 'header')
  
  // Label
  const labelEl = document.createElement('div')
  labelEl.className = 'gjs-block-label'
  labelEl.textContent = preset.label
  
  itemEl.appendChild(mediaEl)
  itemEl.appendChild(labelEl)

  // Drag functionality - generate unique IDs on drag
  itemEl.addEventListener('dragstart', (e) => {
    const uniqueContent = generateUniqueTabContent(preset.content)
    e.dataTransfer?.setData('text/html', uniqueContent)
  })

  // Click to append - generate unique IDs on click
  itemEl.onclick = () => {
    const uniqueContent = generateUniqueTabContent(preset.content)
    editor.addComponents(uniqueContent)
    setTimeout(initCanvasLucide, 200)
    setTimeout(initCanvasLucide, 500)
  }
  
  return itemEl
}

// Create FAQ preset item with unique IDs
function createFAQPresetItem(
  preset: Preset,
  editor: Editor,
  initCanvasLucide: () => void
): HTMLElement {
  const itemEl = document.createElement('div')
  itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
  itemEl.title = preset.label
  itemEl.setAttribute('draggable', 'true')
  itemEl.style.cssText = `
    width: calc(33.333% - 4px);
    margin: 2px;
    min-height: 70px;
  `
  
  // Media container (icon)
  const mediaEl = document.createElement('div')
  mediaEl.className = 'gjs-block__media'
  mediaEl.innerHTML = getFAQIcon(preset.id)
  
  // Label
  const labelEl = document.createElement('div')
  labelEl.className = 'gjs-block-label'
  labelEl.textContent = preset.label
  
  itemEl.appendChild(mediaEl)
  itemEl.appendChild(labelEl)

  // Drag functionality - generate unique IDs on drag
  itemEl.addEventListener('dragstart', (e) => {
    const uniqueContent = generateUniqueFAQContent(preset.content)
    e.dataTransfer?.setData('text/html', uniqueContent)
  })

  // Click to append - generate unique IDs on click
  itemEl.onclick = () => {
    const uniqueContent = generateUniqueFAQContent(preset.content)
    editor.addComponents(uniqueContent)
    setTimeout(initCanvasLucide, 200)
    setTimeout(initCanvasLucide, 500)
  }
  
  return itemEl
}

// Create banner preset item with video background
function createBannerPresetItem(
  preset: Preset,
  editor: Editor,
  initCanvasLucide: () => void
): HTMLElement {
  const itemEl = document.createElement('div')
  itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
  // Add tooltip explaining YouTube limitation
  itemEl.title = `${preset.label}\n\nNote: YouTube videos may not autoplay in editor/preview due to iframe restrictions. Videos will work when page is published.`
  itemEl.setAttribute('draggable', 'true')
  itemEl.style.cssText = `
    width: calc(33.333% - 4px);
    margin: 2px;
    min-height: 70px;
  `
  
  // Media container (icon)
  const mediaEl = document.createElement('div')
  mediaEl.className = 'gjs-block__media'
  mediaEl.innerHTML = getBannerIcon(preset.id)
  
  // Label
  const labelEl = document.createElement('div')
  labelEl.className = 'gjs-block-label'
  labelEl.textContent = preset.label
  
  itemEl.appendChild(mediaEl)
  itemEl.appendChild(labelEl)

  // Drag functionality
  itemEl.addEventListener('dragstart', (e) => {
    e.dataTransfer?.setData('text/html', preset.content)
  })

  // Click to append
  itemEl.onclick = () => {
    editor.addComponents(preset.content)
    setTimeout(initCanvasLucide, 200)
    setTimeout(initCanvasLucide, 500)
  }
  
  return itemEl
}


// Create card preset item
function createCardPresetItem(
  preset: CardPreset,
  editor: Editor,
  initCanvasLucide: () => void
): HTMLElement {
  const itemEl = document.createElement('div')
  itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
  itemEl.title = preset.name
  itemEl.setAttribute('draggable', 'true')
  // Use calc to get 3 columns with gaps like default GrapesJS blocks
  itemEl.style.cssText = `
    width: calc(33.333% - 4px);
    margin: 2px;
    min-height: 70px;
  `
  
  // Media container (icon)
  const mediaEl = document.createElement('div')
  mediaEl.className = 'gjs-block__media'
  mediaEl.innerHTML = getIconForPreset(preset.id, 'cards')
  
  // Label
  const labelEl = document.createElement('div')
  labelEl.className = 'gjs-block-label'
  labelEl.textContent = preset.name
  labelEl.style.cssText = `
    font-size: 10px;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  `
  
  itemEl.appendChild(mediaEl)
  itemEl.appendChild(labelEl)

  // Drag functionality
  itemEl.addEventListener('dragstart', (e) => {
    e.dataTransfer?.setData('text/html', preset.html)
  })

  // Click to append
  itemEl.onclick = () => {
    editor.addComponents(preset.html)
    setTimeout(initCanvasLucide, 200)
    setTimeout(initCanvasLucide, 500)
  }
  
  return itemEl
}


// Create section preset item
function createSectionPresetItem(
  preset: SectionPreset,
  editor: Editor,
  initCanvasLucide: () => void
): HTMLElement {
  const itemEl = document.createElement('div')
  itemEl.className = 'gjs-block gjs-one-bg gjs-four-color-h'
  itemEl.title = preset.name
  itemEl.setAttribute('draggable', 'true')
  // Use calc to get 3 columns with gaps like default GrapesJS blocks
  itemEl.style.cssText = `
    width: calc(33.333% - 4px);
    margin: 2px;
    min-height: 70px;
  `
  
  // Media container (icon)
  const mediaEl = document.createElement('div')
  mediaEl.className = 'gjs-block__media'
  
  // Get appropriate icon based on preset id
  const icons = (CATEGORY_ICONS as any).sections
  let icon = icons.default
  if (preset.id.includes('glass') || preset.id.includes('cyber')) icon = icons.glass
  else if (preset.id.includes('timeline')) icon = icons.timeline
  else if (preset.id.includes('bento') || preset.id.includes('dashboard')) icon = icons.bento
  else if (preset.id.includes('accordion')) icon = icons.accordion
  
  mediaEl.innerHTML = icon
  
  // Label
  const labelEl = document.createElement('div')
  labelEl.className = 'gjs-block-label'
  labelEl.textContent = preset.name
  labelEl.style.cssText = `
    font-size: 10px;
    line-height: 1.2;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  `
  
  itemEl.appendChild(mediaEl)
  itemEl.appendChild(labelEl)

  // Drag functionality
  itemEl.addEventListener('dragstart', (e) => {
    e.dataTransfer?.setData('text/html', preset.content)
  })

  // Click to append
  itemEl.onclick = () => {
    editor.addComponents(preset.content)
    setTimeout(initCanvasLucide, 200)
    setTimeout(initCanvasLucide, 500)
  }
  
  return itemEl
}
