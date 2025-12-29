import { useEffect, useRef, useCallback } from 'react'
import grapesjs, { Editor } from 'grapesjs'
import 'grapesjs/dist/css/grapes.min.css'

// Plugins
import gjsPresetWebpage from 'grapesjs-preset-webpage'
import gjsBlocksBasic from 'grapesjs-blocks-basic'
import gjsPluginForms from 'grapesjs-plugin-forms'
import gjsComponentCountdown from 'grapesjs-component-countdown'
import gjsTabs from 'grapesjs-tabs'
import gjsCustomCode from 'grapesjs-custom-code'
import gjsTouch from 'grapesjs-touch'
import gjsParserPostcss from 'grapesjs-parser-postcss'
import gjsTooltip from 'grapesjs-tooltip'
import gjsTyped from 'grapesjs-typed'
import gjsStyleBg from 'grapesjs-style-bg'

interface GrapesEditorProps {
  initialContent?: {
    html: string
    css: string
    components?: any
    styles?: any
  }
  onSave?: (data: { html: string; css: string; components: any; styles: any }) => void
  onLoad?: (editor: Editor) => void
  projectId?: string | number // Reserved for future use
}

export function GrapesEditor({ initialContent, onSave, onLoad }: GrapesEditorProps) {
  const editorRef = useRef<Editor | null>(null)
  const containerRef = useRef<HTMLDivElement>(null)

  const handleSave = useCallback(() => {
    if (!editorRef.current || !onSave) return
    
    const editor = editorRef.current
    onSave({
      html: editor.getHtml(),
      css: editor.getCss() || '',
      components: editor.getComponents(),
      styles: editor.getStyle(),
    })
  }, [onSave])

  useEffect(() => {
    if (!containerRef.current || editorRef.current) return

    const editor = grapesjs.init({
      container: containerRef.current,
      height: '100%',
      width: 'auto',
      storageManager: false, // We handle storage ourselves
      
      // Device manager for responsive design
      deviceManager: {
        devices: [
          { name: 'Desktop', width: '' },
          { name: 'Tablet', width: '768px', widthMedia: '992px' },
          { name: 'Mobile', width: '375px', widthMedia: '480px' },
        ],
      },

      // Plugins
      plugins: [
        gjsPresetWebpage,
        gjsBlocksBasic,
        gjsPluginForms,
        gjsComponentCountdown,
        gjsTabs,
        gjsCustomCode,
        gjsTouch,
        gjsParserPostcss,
        gjsTooltip,
        gjsTyped,
        gjsStyleBg,
      ],
      
      pluginsOpts: {
        [gjsPresetWebpage as any]: {
          modalImportTitle: 'Import Template',
          modalImportLabel: '<div style="margin-bottom: 10px;">Paste your HTML/CSS here</div>',
          modalImportContent: (editor: Editor) => {
            return editor.getHtml() + '<style>' + editor.getCss() + '</style>'
          },
        },
        [gjsBlocksBasic as any]: {
          flexGrid: true,
        },
        [gjsPluginForms as any]: {
          blocks: ['form', 'input', 'textarea', 'select', 'button', 'label', 'checkbox', 'radio'],
        },
        [gjsTabs as any]: {
          tabsBlock: { category: 'Extra' },
        },
      },

      // Canvas configuration
      canvas: {
        styles: [
          'https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css',
        ],
      },

      // Asset manager for images
      assetManager: {
        uploadFile: async (e: any) => {
          // TODO: Implement file upload to your backend
          const files = e.dataTransfer ? e.dataTransfer.files : e.target.files
          // For now, just log - you'll want to upload to your API
          console.log('Files to upload:', files)
        },
        assets: [],
      },

      // Style manager sectors
      styleManager: {
        sectors: [
          {
            name: 'General',
            open: false,
            buildProps: ['float', 'display', 'position', 'top', 'right', 'left', 'bottom'],
          },
          {
            name: 'Flex',
            open: false,
            buildProps: ['flex-direction', 'flex-wrap', 'justify-content', 'align-items', 'align-content', 'order', 'flex-basis', 'flex-grow', 'flex-shrink', 'align-self'],
          },
          {
            name: 'Dimension',
            open: false,
            buildProps: ['width', 'height', 'max-width', 'min-height', 'margin', 'padding'],
          },
          {
            name: 'Typography',
            open: false,
            buildProps: ['font-family', 'font-size', 'font-weight', 'letter-spacing', 'color', 'line-height', 'text-align', 'text-decoration', 'text-shadow'],
          },
          {
            name: 'Decorations',
            open: false,
            buildProps: ['background-color', 'border-radius', 'border', 'box-shadow', 'background'],
          },
          {
            name: 'Extra',
            open: false,
            buildProps: ['opacity', 'transition', 'transform', 'cursor', 'overflow'],
          },
        ],
      },
    })

    // Add custom blocks
    addCustomBlocks(editor)

    // Add keyboard shortcuts
    editor.Commands.add('save-project', {
      run: () => handleSave(),
    })

    editor.Keymaps.add('save', 'ctrl+s', 'save-project')
    editor.Keymaps.add('save-mac', 'cmd+s', 'save-project')

    // Load initial content if provided
    if (initialContent) {
      if (initialContent.components) {
        editor.setComponents(initialContent.components)
        editor.setStyle(initialContent.styles || [])
      } else if (initialContent.html) {
        editor.setComponents(initialContent.html)
        editor.setStyle(initialContent.css || '')
      }
    }

    editorRef.current = editor
    onLoad?.(editor)

    return () => {
      editor.destroy()
      editorRef.current = null
    }
  }, [])

  // Update save handler when onSave changes
  useEffect(() => {
    if (!editorRef.current) return
    
    editorRef.current.Commands.add('save-project', {
      run: () => handleSave(),
    })
  }, [handleSave])

  return (
    <div ref={containerRef} className="grapesjs-editor h-full w-full" />
  )
}

// Custom blocks for your ERP
function addCustomBlocks(editor: Editor) {
  const blockManager = editor.BlockManager

  // Hero Section
  blockManager.add('hero-section', {
    label: 'Hero Section',
    category: 'Sections',
    content: `
      <section class="py-20 px-4 bg-gradient-to-r from-blue-600 to-purple-600 text-white">
        <div class="max-w-4xl mx-auto text-center">
          <h1 class="text-5xl font-bold mb-6">Welcome to Your Website</h1>
          <p class="text-xl mb-8 opacity-90">Create stunning websites with our drag-and-drop builder</p>
          <a href="#" class="inline-block px-8 py-3 bg-white text-blue-600 font-semibold rounded-lg hover:bg-gray-100 transition">Get Started</a>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z"/></svg>',
  })

  // Features Grid
  blockManager.add('features-grid', {
    label: 'Features Grid',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-gray-50">
        <div class="max-w-6xl mx-auto">
          <h2 class="text-3xl font-bold text-center mb-12">Our Features</h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
              <div class="w-12 h-12 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-2xl">🚀</span>
              </div>
              <h3 class="text-xl font-semibold mb-2">Fast Performance</h3>
              <p class="text-gray-600">Lightning fast load times for better user experience</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
              <div class="w-12 h-12 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-2xl">🔒</span>
              </div>
              <h3 class="text-xl font-semibold mb-2">Secure</h3>
              <p class="text-gray-600">Enterprise-grade security for your peace of mind</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
              <div class="w-12 h-12 bg-purple-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <span class="text-2xl">📱</span>
              </div>
              <h3 class="text-xl font-semibold mb-2">Responsive</h3>
              <p class="text-gray-600">Looks great on all devices and screen sizes</p>
            </div>
          </div>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M4 11h5V5H4v6zm0 7h5v-6H4v6zm6 0h5v-6h-5v6zm6 0h5v-6h-5v6zm-6-7h5V5h-5v6zm6-6v6h5V5h-5z"/></svg>',
  })

  // Pricing Table
  blockManager.add('pricing-table', {
    label: 'Pricing Table',
    category: 'Sections',
    content: `
      <section class="py-16 px-4">
        <div class="max-w-5xl mx-auto">
          <h2 class="text-3xl font-bold text-center mb-12">Pricing Plans</h2>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="border rounded-lg p-6 text-center">
              <h3 class="text-xl font-semibold mb-2">Starter</h3>
              <div class="text-4xl font-bold mb-4">$9<span class="text-lg text-gray-500">/mo</span></div>
              <ul class="text-gray-600 mb-6 space-y-2">
                <li>5 Projects</li>
                <li>Basic Support</li>
                <li>1GB Storage</li>
              </ul>
              <a href="#" class="block w-full py-2 border border-blue-600 text-blue-600 rounded-lg hover:bg-blue-50 transition">Choose Plan</a>
            </div>
            <div class="border-2 border-blue-600 rounded-lg p-6 text-center relative">
              <span class="absolute -top-3 left-1/2 -translate-x-1/2 bg-blue-600 text-white px-3 py-1 text-sm rounded-full">Popular</span>
              <h3 class="text-xl font-semibold mb-2">Professional</h3>
              <div class="text-4xl font-bold mb-4">$29<span class="text-lg text-gray-500">/mo</span></div>
              <ul class="text-gray-600 mb-6 space-y-2">
                <li>Unlimited Projects</li>
                <li>Priority Support</li>
                <li>10GB Storage</li>
              </ul>
              <a href="#" class="block w-full py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Choose Plan</a>
            </div>
            <div class="border rounded-lg p-6 text-center">
              <h3 class="text-xl font-semibold mb-2">Enterprise</h3>
              <div class="text-4xl font-bold mb-4">$99<span class="text-lg text-gray-500">/mo</span></div>
              <ul class="text-gray-600 mb-6 space-y-2">
                <li>Unlimited Everything</li>
                <li>24/7 Support</li>
                <li>100GB Storage</li>
              </ul>
              <a href="#" class="block w-full py-2 border border-blue-600 text-blue-600 rounded-lg hover:bg-blue-50 transition">Contact Us</a>
            </div>
          </div>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1.41 16.09V20h-2.67v-1.93c-1.71-.36-3.16-1.46-3.27-3.4h1.96c.1 1.05.82 1.87 2.65 1.87 1.96 0 2.4-.98 2.4-1.59 0-.83-.44-1.61-2.67-2.14-2.48-.6-4.18-1.62-4.18-3.67 0-1.72 1.39-2.84 3.11-3.21V4h2.67v1.95c1.86.45 2.79 1.86 2.85 3.39H14.3c-.05-1.11-.64-1.87-2.22-1.87-1.5 0-2.4.68-2.4 1.64 0 .84.65 1.39 2.67 1.91s4.18 1.39 4.18 3.91c-.01 1.83-1.38 2.83-3.12 3.16z"/></svg>',
  })

  // Contact Section
  blockManager.add('contact-section', {
    label: 'Contact Section',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-gray-900 text-white">
        <div class="max-w-4xl mx-auto">
          <h2 class="text-3xl font-bold text-center mb-8">Get In Touch</h2>
          <form class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <input type="text" placeholder="Your Name" class="px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-blue-500 focus:outline-none">
            <input type="email" placeholder="Your Email" class="px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-blue-500 focus:outline-none">
            <textarea placeholder="Your Message" rows="4" class="md:col-span-2 px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-blue-500 focus:outline-none"></textarea>
            <button type="submit" class="md:col-span-2 px-8 py-3 bg-blue-600 text-white font-semibold rounded-lg hover:bg-blue-700 transition">Send Message</button>
          </form>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
  })

  // Footer
  blockManager.add('footer', {
    label: 'Footer',
    category: 'Sections',
    content: `
      <footer class="py-12 px-4 bg-gray-900 text-gray-400">
        <div class="max-w-6xl mx-auto grid grid-cols-1 md:grid-cols-4 gap-8">
          <div>
            <h4 class="text-white font-semibold mb-4">Company</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-white transition">About Us</a></li>
              <li><a href="#" class="hover:text-white transition">Careers</a></li>
              <li><a href="#" class="hover:text-white transition">Contact</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Products</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-white transition">Features</a></li>
              <li><a href="#" class="hover:text-white transition">Pricing</a></li>
              <li><a href="#" class="hover:text-white transition">Integrations</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Resources</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-white transition">Documentation</a></li>
              <li><a href="#" class="hover:text-white transition">Blog</a></li>
              <li><a href="#" class="hover:text-white transition">Support</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Legal</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-white transition">Privacy Policy</a></li>
              <li><a href="#" class="hover:text-white transition">Terms of Service</a></li>
            </ul>
          </div>
        </div>
        <div class="max-w-6xl mx-auto mt-8 pt-8 border-t border-gray-800 text-center">
          <p>&copy; 2025 Your Company. All rights reserved.</p>
        </div>
      </footer>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 19h12v2H6v-2z"/></svg>',
  })

  // Testimonials
  blockManager.add('testimonials', {
    label: 'Testimonials',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-blue-50">
        <div class="max-w-4xl mx-auto text-center">
          <h2 class="text-3xl font-bold mb-12">What Our Customers Say</h2>
          <div class="bg-white p-8 rounded-xl shadow-sm">
            <p class="text-xl text-gray-600 italic mb-6">"This website builder has transformed how we create landing pages. It's intuitive, powerful, and saves us hours of development time."</p>
            <div class="flex items-center justify-center gap-4">
              <div class="w-12 h-12 bg-gray-300 rounded-full"></div>
              <div class="text-left">
                <p class="font-semibold">John Doe</p>
                <p class="text-gray-500 text-sm">CEO, TechCorp</p>
              </div>
            </div>
          </div>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M6 17h3l2-4V7H5v6h3zm8 0h3l2-4V7h-6v6h3z"/></svg>',
  })

  // CTA Banner
  blockManager.add('cta-banner', {
    label: 'CTA Banner',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-blue-600 text-white">
        <div class="max-w-4xl mx-auto text-center">
          <h2 class="text-3xl font-bold mb-4">Ready to Get Started?</h2>
          <p class="text-xl opacity-90 mb-8">Join thousands of satisfied customers building amazing websites</p>
          <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="#" class="px-8 py-3 bg-white text-blue-600 font-semibold rounded-lg hover:bg-gray-100 transition">Start Free Trial</a>
            <a href="#" class="px-8 py-3 border-2 border-white text-white font-semibold rounded-lg hover:bg-white/10 transition">Learn More</a>
          </div>
        </div>
      </section>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M19 13h-6v6h-2v-6H5v-2h6V5h2v6h6v2z"/></svg>',
  })

  // Navigation
  blockManager.add('navbar', {
    label: 'Navigation',
    category: 'Sections',
    content: `
      <nav class="py-4 px-6 bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-6xl mx-auto flex items-center justify-between">
          <a href="#" class="text-2xl font-bold text-blue-600">Logo</a>
          <div class="hidden md:flex items-center gap-8">
            <a href="#" class="text-gray-600 hover:text-blue-600 transition">Home</a>
            <a href="#" class="text-gray-600 hover:text-blue-600 transition">Features</a>
            <a href="#" class="text-gray-600 hover:text-blue-600 transition">Pricing</a>
            <a href="#" class="text-gray-600 hover:text-blue-600 transition">Contact</a>
          </div>
          <a href="#" class="px-6 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition">Sign Up</a>
        </div>
      </nav>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>',
  })
}

export default GrapesEditor
