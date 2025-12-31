// Lucide Icons - We load the full icon set from CDN and provide search
// Only show popular icons by default, search reveals all 1400+ icons

// Popular icons shown by default (no search needed)
export const POPULAR_ICON_NAMES = [
  'search', 'menu', 'x', 'chevron-down', 'chevron-right', 'chevron-left', 'chevron-up',
  'arrow-right', 'arrow-left', 'arrow-up', 'arrow-down',
  'user', 'users', 'shopping-cart', 'shopping-bag', 'heart', 'star',
  'mail', 'phone', 'map-pin', 'clock', 'calendar',
  'check', 'plus', 'minus', 'settings', 'home', 'bell',
  'eye', 'eye-off', 'lock', 'unlock', 'edit', 'trash', 'copy',
  'download', 'upload', 'share', 'link', 'external-link',
  'image', 'file', 'folder', 'bookmark', 'tag', 'filter',
  'grid', 'list', 'play', 'pause', 'volume-2', 'volume-x',
  'sun', 'moon', 'cloud', 'zap', 'globe',
  'instagram', 'facebook', 'twitter', 'linkedin', 'github', 'youtube',
]

// Lucide icon trait options for component settings
export const LUCIDE_ICON_OPTIONS = POPULAR_ICON_NAMES.map(name => ({
  id: name,
  name: name.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')
}))

// Helper to create inline SVG from Lucide icon name
export function createLucideIconSvg(iconName: string): string {
  return `<i data-lucide="${iconName}" class="w-5 h-5"></i>`
}

// Helper to get icon label from name
export function getIconLabel(name: string): string {
  return name.split('-').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')
}
