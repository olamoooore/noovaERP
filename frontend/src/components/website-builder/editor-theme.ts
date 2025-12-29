/**
 * Editor Theme Color Tokens
 * 
 * This file defines the color system for the GrapesJS website editor.
 * These tokens match the CSS custom properties defined in grapes-custom.css.
 * 
 * Requirements:
 * - 1.1: Neutral gray color palette (zinc)
 * - 1.2: Teal (#2a9d8f) as primary accent color
 * - 1.5: WCAG AA contrast ratios for all text elements
 */

export const editorTheme = {
  // Background Colors (Zinc Palette) - Requirement 1.1
  bgBase: '#18181b',      // zinc-900 - Main background
  bgSurface: '#27272a',   // zinc-800 - Panel backgrounds
  bgElevated: '#3f3f46',  // zinc-700 - Elevated surfaces
  bgHover: '#52525b',     // zinc-600 - Hover states
  
  // Border Colors
  borderDefault: '#3f3f46', // zinc-700
  borderSubtle: '#27272a',  // zinc-800
  borderFocus: '#2a9d8f',   // teal accent
  
  // Text Colors
  textPrimary: '#fafafa',   // zinc-50
  textSecondary: '#a1a1aa', // zinc-400
  textMuted: '#71717a',     // zinc-500
  
  // Accent Colors (Teal) - Requirement 1.2
  accentPrimary: '#2a9d8f',    // teal
  accentHover: '#3db8a9',      // teal lighter
  accentBg: 'rgba(42, 157, 143, 0.1)',  // teal 10% - hover highlight
  accentBgActive: 'rgba(42, 157, 143, 0.2)', // teal 20% - selected/active
} as const;

/**
 * Color pairs used in the editor for WCAG contrast testing.
 * Each pair represents a text color on a background color.
 */
export const colorPairs = [
  // Primary text on backgrounds
  { text: editorTheme.textPrimary, bg: editorTheme.bgBase, name: 'Primary text on base' },
  { text: editorTheme.textPrimary, bg: editorTheme.bgSurface, name: 'Primary text on surface' },
  { text: editorTheme.textPrimary, bg: editorTheme.bgElevated, name: 'Primary text on elevated' },
  { text: editorTheme.textPrimary, bg: editorTheme.bgHover, name: 'Primary text on hover' },
  
  // Secondary text on backgrounds
  { text: editorTheme.textSecondary, bg: editorTheme.bgBase, name: 'Secondary text on base' },
  { text: editorTheme.textSecondary, bg: editorTheme.bgSurface, name: 'Secondary text on surface' },
  { text: editorTheme.textSecondary, bg: editorTheme.bgElevated, name: 'Secondary text on elevated' },
  
  // Muted text on backgrounds
  { text: editorTheme.textMuted, bg: editorTheme.bgBase, name: 'Muted text on base' },
  { text: editorTheme.textMuted, bg: editorTheme.bgSurface, name: 'Muted text on surface' },
  
  // Accent color as text
  { text: editorTheme.accentPrimary, bg: editorTheme.bgBase, name: 'Accent on base' },
  { text: editorTheme.accentPrimary, bg: editorTheme.bgSurface, name: 'Accent on surface' },
  { text: editorTheme.accentPrimary, bg: editorTheme.bgElevated, name: 'Accent on elevated' },
] as const;

export type EditorTheme = typeof editorTheme;
export type ColorPair = typeof colorPairs[number];
