// Custom Blocks for GrapesJS Editor
import { Editor } from 'grapesjs'
import { LUCIDE_ICON_OPTIONS } from './icons'

export function registerCustomBlocks(editor: Editor) {
  const blockManager = editor.BlockManager

  // Fix: Override the default button component from grapesjs-plugin-forms
  const defaultButton = editor.DomComponents.getType('button')
  if (defaultButton) {
    editor.DomComponents.addType('button', {
      isComponent: (el) => el.tagName === 'BUTTON',
      model: {
        defaults: {
          ...defaultButton.model.defaults,
          content: '',
          text: '',
          label: '',
          droppable: true,
          resizable: true,
          // Allow selecting children (SVGs inside buttons)
          propagate: ['removable', 'draggable', 'copyable', 'selectable'],
        },
      },
      view: defaultButton.view,
    })
  }

  // Override link/anchor component to allow child selection (for SVGs inside links)
  const defaultLink = editor.DomComponents.getType('link')
  if (defaultLink) {
    editor.DomComponents.addType('link', {
      isComponent: (el) => el.tagName === 'A',
      model: {
        defaults: {
          ...defaultLink.model.defaults,
          droppable: true,
          // Allow selecting children (SVGs inside links)
          propagate: ['removable', 'draggable', 'copyable', 'selectable'],
        },
      },
      view: defaultLink.view,
    })
  }

  // Register Lucide Icon component type
  registerLucideIconComponent(editor)

  // Add a placeholder for Icons category (will be replaced by addIconSearch)
  blockManager.add('icons-placeholder', {
    label: 'Icons',
    category: 'Icons',
    content: '<span></span>',
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
  })

  // Add Section blocks
  addSectionBlocks(blockManager)
}

function registerLucideIconComponent(editor: Editor) {
  // Register wrapper component for icons (more easily selectable)
  editor.DomComponents.addType('lucide-icon-wrapper', {
    isComponent: (el) => {
      if (el.classList?.contains('lucide-icon-wrapper')) {
        return { type: 'lucide-icon-wrapper' }
      }
      return false
    },
    model: {
      defaults: {
        tagName: 'span',
        name: 'Icon Wrapper',
        draggable: true,
        droppable: false,
        selectable: true,
        hoverable: true,
        removable: true,
        copyable: true,
        badgable: true,
        highlightable: true,
        resizable: {
          tl: 0, tc: 0, tr: 0, cl: 0, cr: 0, bl: 0, bc: 0, br: 1,
          keyWidth: 'width',
          keyHeight: 'height',
        },
        stylable: ['width', 'height', 'color', 'margin', 'padding', 'background-color', 'border-radius'],
        classes: ['lucide-icon-wrapper'],
        style: {
          display: 'inline-flex',
          'align-items': 'center',
          'justify-content': 'center',
          width: '32px',
          height: '32px',
          cursor: 'pointer',
        },
      },
    },
  })

  // Register component for <i data-lucide> elements
  editor.DomComponents.addType('lucide-icon', {
    isComponent: (el) => {
      if (el.tagName === 'I' && el.hasAttribute('data-lucide')) {
        return { type: 'lucide-icon' }
      }
      return false
    },
    model: {
      defaults: {
        tagName: 'i',
        name: 'Icon',
        draggable: true,
        droppable: false,
        selectable: true,
        hoverable: true,
        removable: true,
        copyable: true,
        badgable: true,
        highlightable: true,
        resizable: {
          tl: 0, tc: 0, tr: 0, cl: 0, cr: 0, bl: 0, bc: 0, br: 1,
          keyWidth: 'width',
          keyHeight: 'height',
        },
        stylable: ['width', 'height', 'color', 'margin', 'padding'],
        attributes: { 'data-lucide': 'star', class: 'w-6 h-6' },
        style: {
          display: 'inline-block',
          width: '24px',
          height: '24px',
        },
        traits: [
          {
            type: 'select',
            label: 'Icon',
            name: 'data-lucide',
            options: LUCIDE_ICON_OPTIONS,
          },
        ],
      },
    },
    view: {
      onRender() {
        // Ensure icon renders
        setTimeout(() => {
          const frame = editor.Canvas.getFrameEl()
          if (frame?.contentWindow) {
            const win = frame.contentWindow as any
            if (win.lucide) win.lucide.createIcons()
          }
        }, 100)
      },
    },
  })

  // Register SVG component type for rendered Lucide icons and inline SVGs
  // Changes are persisted to attributes so they export correctly
  editor.DomComponents.addType('svg', {
    isComponent: (el) => el.tagName?.toUpperCase() === 'SVG',
    model: {
      defaults: {
        name: 'SVG Icon',
        tagName: 'svg',
        draggable: true,
        droppable: false,
        selectable: true,
        hoverable: true,
        removable: true,
        copyable: true,
        badgable: true,
        highlightable: true,
        layerable: true,
        resizable: false,
        stylable: false,
        // Ensure SVG is always selectable even inside buttons/links
        propagate: [],
        // Custom properties for SVG - these sync with attributes
        'svg-stroke': '',
        'svg-fill': '',
        'svg-stroke-width': '',
        'svg-size': '',
        traits: [
          {
            type: 'color',
            name: 'svg-stroke',
            label: 'Stroke Color',
            changeProp: true,
          },
          {
            type: 'color',
            name: 'svg-fill',
            label: 'Fill Color',
            changeProp: true,
          },
          {
            type: 'select',
            name: 'svg-stroke-width',
            label: 'Stroke Width',
            changeProp: true,
            options: [
              { id: '1', value: '1', name: '1px' },
              { id: '1.5', value: '1.5', name: '1.5px' },
              { id: '2', value: '2', name: '2px' },
              { id: '2.5', value: '2.5', name: '2.5px' },
              { id: '3', value: '3', name: '3px' },
            ],
          },
          {
            type: 'select',
            name: 'svg-size',
            label: 'Size',
            changeProp: true,
            options: [
              { id: '16', value: '16', name: 'Small (16px)' },
              { id: '20', value: '20', name: 'Medium (20px)' },
              { id: '24', value: '24', name: 'Large (24px)' },
              { id: '28', value: '28', name: 'XL (28px)' },
              { id: '32', value: '32', name: '2XL (32px)' },
              { id: '40', value: '40', name: '3XL (40px)' },
              { id: '48', value: '48', name: '4XL (48px)' },
            ],
          },
        ],
      },
      init() {
        // Listen for property changes and update attributes (for export)
        this.on('change:svg-stroke', this.updateSvgStroke);
        this.on('change:svg-fill', this.updateSvgFill);
        this.on('change:svg-stroke-width', this.updateSvgStrokeWidth);
        this.on('change:svg-size', this.updateSvgSize);
        
        // Initialize properties from existing attributes
        const attrs = this.getAttributes();
        if (attrs.stroke) this.set('svg-stroke', attrs.stroke, { silent: true });
        if (attrs.fill) this.set('svg-fill', attrs.fill, { silent: true });
        if (attrs['stroke-width']) this.set('svg-stroke-width', attrs['stroke-width'], { silent: true });
      },
      updateSvgStroke() {
        const value = this.get('svg-stroke');
        if (value) {
          // Update the component's attributes (persists to export)
          this.addAttributes({ stroke: value });
          // Update the view
          if (this.view?.el) {
            this.view.el.setAttribute('stroke', value);
          }
        }
      },
      updateSvgFill() {
        const value = this.get('svg-fill');
        // Update the component's attributes (persists to export)
        this.addAttributes({ fill: value || 'none' });
        // Update the view
        if (this.view?.el) {
          this.view.el.setAttribute('fill', value || 'none');
        }
      },
      updateSvgStrokeWidth() {
        const value = this.get('svg-stroke-width');
        if (value) {
          // Update the component's attributes (persists to export)
          this.addAttributes({ 'stroke-width': value });
          // Update the view
          if (this.view?.el) {
            this.view.el.setAttribute('stroke-width', value);
          }
        }
      },
      updateSvgSize() {
        const value = this.get('svg-size');
        if (value) {
          const size = value + 'px';
          // Update the component's attributes (persists to export)
          this.addAttributes({ width: size, height: size });
          // Update inline style (persists to export)
          const currentStyle = this.getStyle() || {};
          this.setStyle({
            ...currentStyle,
            width: size,
            height: size,
            'min-width': size,
            'min-height': size,
          });
          // Update the view
          if (this.view?.el) {
            const el = this.view.el;
            el.style.setProperty('width', size, 'important');
            el.style.setProperty('height', size, 'important');
            el.style.setProperty('min-width', size, 'important');
            el.style.setProperty('min-height', size, 'important');
            el.setAttribute('width', size);
            el.setAttribute('height', size);
          }
        }
      },
    },
    view: {
      events() {
        return {
          dblclick: 'onDblClick',
        };
      },
      onDblClick() {
        const editor = (this as any).model?.em?.get('Editor');
        if (editor) {
          editor.runCommand('open-tm');
        }
      },
      onRender() {
        const el = this.el;
        const model = (this as any).model;
        
        if (el && el.tagName?.toUpperCase() === 'SVG') {
          // Ensure SVG is clickable and selectable
          el.style.display = 'inline-block';
          el.style.verticalAlign = 'middle';
          el.style.pointerEvents = 'all';
          el.style.cursor = 'pointer';
          el.style.position = 'relative';
          el.style.zIndex = '1';
          
          // Add a class to help with selection
          el.classList.add('gjs-svg-selectable');
          
          // Read current attributes and set as properties
          const currentStroke = el.getAttribute('stroke');
          const currentFill = el.getAttribute('fill');
          const currentStrokeWidth = el.getAttribute('stroke-width');
          
          if (currentStroke && !model.get('svg-stroke')) {
            model.set('svg-stroke', currentStroke, { silent: true });
          }
          if (currentFill && !model.get('svg-fill')) {
            model.set('svg-fill', currentFill, { silent: true });
          }
          if (currentStrokeWidth && !model.get('svg-stroke-width')) {
            model.set('svg-stroke-width', currentStrokeWidth, { silent: true });
          }
          
          // Apply size if set
          const size = model.get('svg-size');
          if (size) {
            const sizeVal = size + 'px';
            el.style.setProperty('width', sizeVal, 'important');
            el.style.setProperty('height', sizeVal, 'important');
            el.style.setProperty('min-width', sizeVal, 'important');
            el.style.setProperty('min-height', sizeVal, 'important');
          }
        }
      },
    },
  })
}

function addSectionBlocks(blockManager: any) {
  // Hero Section
  blockManager.add('hero-section', {
    label: 'Hero Section',
    category: 'Sections',
    content: `
      <section class="py-20 px-4 bg-gradient-to-r from-teal-600 to-cyan-600 text-white">
        <div class="max-w-4xl mx-auto text-center">
          <h1 class="text-5xl font-bold mb-6">Welcome to Your Website</h1>
          <p class="text-xl mb-8 opacity-90">Create stunning websites with our drag-and-drop builder</p>
          <a href="#" class="inline-block px-8 py-3 bg-white text-teal-600 font-semibold rounded-lg hover:bg-gray-100 transition">Get Started</a>
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
              <div class="w-12 h-12 bg-teal-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="zap" class="w-6 h-6 text-teal-600"></i>
              </div>
              <h3 class="text-xl font-semibold mb-2">Fast Performance</h3>
              <p class="text-gray-600">Lightning fast load times for better user experience</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
              <div class="w-12 h-12 bg-teal-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="lock" class="w-6 h-6 text-teal-600"></i>
              </div>
              <h3 class="text-xl font-semibold mb-2">Secure</h3>
              <p class="text-gray-600">Enterprise-grade security for your peace of mind</p>
            </div>
            <div class="bg-white p-6 rounded-lg shadow-sm text-center">
              <div class="w-12 h-12 bg-teal-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i data-lucide="smartphone" class="w-6 h-6 text-teal-600"></i>
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

  // Contact Section
  blockManager.add('contact-section', {
    label: 'Contact Section',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-gray-900 text-white">
        <div class="max-w-4xl mx-auto">
          <h2 class="text-3xl font-bold text-center mb-8">Get In Touch</h2>
          <form class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <input type="text" placeholder="Your Name" class="px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-teal-500 focus:outline-none">
            <input type="email" placeholder="Your Email" class="px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-teal-500 focus:outline-none">
            <textarea placeholder="Your Message" rows="4" class="md:col-span-2 px-4 py-3 rounded-lg bg-gray-800 border border-gray-700 focus:border-teal-500 focus:outline-none"></textarea>
            <button type="submit" class="md:col-span-2 px-8 py-3 bg-teal-600 text-white font-semibold rounded-lg hover:bg-teal-700 transition">Send Message</button>
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
              <li><a href="#" class="hover:text-teal-400 transition">About Us</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Careers</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Contact</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Products</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-teal-400 transition">Features</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Pricing</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Integrations</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Resources</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-teal-400 transition">Documentation</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Blog</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Support</a></li>
            </ul>
          </div>
          <div>
            <h4 class="text-white font-semibold mb-4">Legal</h4>
            <ul class="space-y-2">
              <li><a href="#" class="hover:text-teal-400 transition">Privacy Policy</a></li>
              <li><a href="#" class="hover:text-teal-400 transition">Terms of Service</a></li>
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

  // CTA Banner
  blockManager.add('cta-banner', {
    label: 'CTA Banner',
    category: 'Sections',
    content: `
      <section class="py-16 px-4 bg-teal-600 text-white">
        <div class="max-w-4xl mx-auto text-center">
          <h2 class="text-3xl font-bold mb-4">Ready to Get Started?</h2>
          <p class="text-xl opacity-90 mb-8">Join thousands of satisfied customers building amazing websites</p>
          <div class="flex flex-col sm:flex-row gap-4 justify-center">
            <a href="#" class="px-8 py-3 bg-white text-teal-600 font-semibold rounded-lg hover:bg-gray-100 transition">Start Free Trial</a>
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
          <a href="#" class="text-2xl font-bold text-teal-600">Logo</a>
          <div class="hidden md:flex items-center gap-8">
            <a href="#" class="text-gray-600 hover:text-teal-600 transition">Home</a>
            <a href="#" class="text-gray-600 hover:text-teal-600 transition">Features</a>
            <a href="#" class="text-gray-600 hover:text-teal-600 transition">Pricing</a>
            <a href="#" class="text-gray-600 hover:text-teal-600 transition">Contact</a>
          </div>
          <a href="#" class="px-6 py-2 bg-teal-600 text-white rounded-lg hover:bg-teal-700 transition">Sign Up</a>
        </div>
      </nav>
    `,
    media: '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 18h18v-2H3v2zm0-5h18v-2H3v2zm0-7v2h18V6H3z"/></svg>',
  })
}
