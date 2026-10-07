/**
 * #1004: helpers for the line-description rich text.
 *
 * `description_html` is the sanitized formatted copy stored alongside the plain
 * `description` (used for search, exports and e-invoice payloads). These helpers
 * keep the two in sync on the client; the server re-sanitizes before persisting.
 */

const ESCAPES: Record<string, string> = {
  '&': '&amp;',
  '<': '&lt;',
  '>': '&gt;',
  '"': '&quot;',
  "'": '&#39;',
};

const UNESCAPES: Record<string, string> = {
  '&nbsp;': ' ',
  '&amp;': '&',
  '&lt;': '<',
  '&gt;': '>',
  '&quot;': '"',
  '&#39;': "'",
};

/** Unwraps rich HTML into the plain-text search/export copy. */
export function htmlToPlainText(html: string | null | undefined): string {
  if (!html) return '';
  return html
    .replace(/<br\s*\/?>/gi, '\n')
    .replace(/<\/(p|div|li|h[1-6])>/gi, '\n')
    .replace(/<[^>]*>/g, '')
    .replace(/&nbsp;|&amp;|&lt;|&gt;|&quot;|&#39;/g, (entity) => UNESCAPES[entity] ?? entity)
    .replace(/[ \t]+\n/g, '\n')
    .replace(/\n{3,}/g, '\n\n')
    .trim();
}

/** Renders a plain description as minimal rich HTML so it can seed the editor. */
export function plainToRichHtml(text: string | null | undefined): string {
  if (!text) return '';
  return text
    .split(/\r?\n/)
    .map((line) => `<p>${line.replace(/[&<>"']/g, (char) => ESCAPES[char] ?? char) || '<br>'}</p>`)
    .join('');
}

/**
 * #1004: maps a stored `font-family` value back to the editor's dropdown option.
 *
 * The server stores the family lowercased and followed by a PDF fallback stack
 * (e.g. `times new roman, 'dejavu serif', serif`), so the raw TipTap attribute
 * never equals an option label. The first family decides, matched
 * case-insensitively; anything else leaves the dropdown on its placeholder.
 */
export function fontFamilyOption(raw: string | null | undefined, options: readonly string[]): string {
  if (!raw) return '';
  const first = raw.split(',')[0].trim().replace(/^["']|["']$/g, '').trim().toLowerCase();
  return options.find((option) => option.toLowerCase() === first) ?? '';
}
