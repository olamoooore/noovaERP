// Theme Presets for GrapesJS Editor
// These are full-page templates that include HTML, CSS, and JS

export interface ThemePreset {
  id: string
  label: string
  description: string
  thumbnail: string
  content: string
}

// Theme metadata - thumbnails are actual screenshots from /theme-screenshots/
export const themeMetadata: { id: string; label: string; description: string }[] = [
  { id: 'theme-01', label: 'NEXUS OS', description: 'Dark futuristic AI/Tech' },
  { id: 'theme-02', label: 'Limited', description: 'Elite digital agency' },
  { id: 'theme-03', label: 'Minimal Pro', description: 'Clean professional' },
  { id: 'theme-04', label: 'Creative Studio', description: 'Bold creative agency' },
  { id: 'theme-05', label: 'SaaS Landing', description: 'Modern SaaS product' },
  { id: 'theme-06', label: 'Portfolio', description: 'Personal showcase' },
  { id: 'theme-07', label: 'E-Commerce', description: 'Online store' },
  { id: 'theme-08', label: 'Blog Magazine', description: 'Content-focused blog' },
  { id: 'theme-09', label: 'Startup', description: 'Tech startup landing' },
  { id: 'theme-10', label: 'Corporate', description: 'Professional business' },
  { id: 'theme-11', label: 'App Landing', description: 'Mobile app showcase' },
  { id: 'theme-12', label: 'Event', description: 'Conference & event' },
]

// Store for loaded theme content
const themeContentCache: Map<string, string> = new Map()

// Function to load theme content from file
export async function loadThemeContent(themeId: string): Promise<string> {
  // Check cache first
  if (themeContentCache.has(themeId)) {
    return themeContentCache.get(themeId)!
  }

  try {
    const response = await fetch(`/Web-Templates/${themeId}.html`)
    if (!response.ok) {
      throw new Error(`Failed to load theme: ${themeId}`)
    }
    const content = await response.text()
    themeContentCache.set(themeId, content)
    return content
  } catch (error) {
    console.error(`Error loading theme ${themeId}:`, error)
    return `<div style="padding: 40px; text-align: center; color: #666;">Failed to load theme: ${themeId}</div>`
  }
}

// Generate theme presets with actual screenshot thumbnails
export function getThemePresets(): ThemePreset[] {
  return themeMetadata.map(meta => ({
    id: meta.id,
    label: meta.label,
    description: meta.description,
    thumbnail: `/theme-screenshots/${meta.id}.png`,
    content: '',
  }))
}

// Export the presets
export const themePresets = getThemePresets()
