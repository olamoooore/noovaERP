import { describe, it, expect } from 'vitest'
import { fontFamilyOption, htmlToPlainText, plainToRichHtml } from './richText'

describe('htmlToPlainText (#1004)', () => {
  it('unwraps formatting into the plain description copy', () => {
    expect(htmlToPlainText('<p><b>Bold</b> and <u>under</u></p>')).toBe('Bold and under')
  })

  it('turns block boundaries into line breaks and unescapes entities', () => {
    expect(htmlToPlainText('<p>Line 1</p><p>Line 2</p>')).toBe('Line 1\nLine 2')
    expect(htmlToPlainText('<p>Fish &amp; Chips<br>4 &lt; 5</p>')).toBe('Fish & Chips\n4 < 5')
  })

  it('handles empty input', () => {
    expect(htmlToPlainText('')).toBe('')
    expect(htmlToPlainText(null)).toBe('')
    expect(htmlToPlainText(undefined)).toBe('')
  })
})

describe('plainToRichHtml (#1004)', () => {
  it('escapes plain text and keeps line breaks as paragraphs', () => {
    expect(plainToRichHtml('Fish & Chips')).toBe('<p>Fish &amp; Chips</p>')
    expect(plainToRichHtml('One\nTwo')).toBe('<p>One</p><p>Two</p>')
    expect(plainToRichHtml('<script>alert(1)</script>')).toBe('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>')
  })

  it('round-trips through htmlToPlainText', () => {
    expect(htmlToPlainText(plainToRichHtml('Keep <this> & that'))).toBe('Keep <this> & that')
  })
})

describe('fontFamilyOption (#1004)', () => {
  const options = ['Arial', 'Times New Roman', 'Courier New', 'serif'];

  it('re-selects the dropdown option for a stored lowercase PDF fallback stack', () => {
    expect(fontFamilyOption("times new roman, 'dejavu serif', serif", options)).toBe('Times New Roman');
    expect(fontFamilyOption('"Courier New", "dejavu sans mono", monospace', options)).toBe('Courier New');
    expect(fontFamilyOption('arial', options)).toBe('Arial');
    expect(fontFamilyOption('serif', options)).toBe('serif');
  });

  it('returns an empty value for missing or unknown families', () => {
    expect(fontFamilyOption(undefined, options)).toBe('');
    expect(fontFamilyOption('', options)).toBe('');
    expect(fontFamilyOption('Comic Sans MS, serif', options)).toBe('');
  });
});
