import { useEffect, useRef, useCallback } from 'react'
import grapesjs, { Editor } from 'grapesjs'
import 'grapesjs/dist/css/grapes.min.css'

// Configuration
import { getPluginsWithoutTabs } from './config/plugins'
import { canvasConfig } from './config/canvas'
import { styleManagerConfig } from './config/styleManager'
import { deviceManagerConfig } from './config/devices'

// Blocks
import { registerCustomBlocks } from './blocks'

// Panels
import { customizePanelIcons, addUploadButton, addPresetsPanel, addIconSearch } from './panels'

// Utils
import { setupLucideListeners, setupContextMenu, setupKeyboardShortcuts, setupImageUpload, setupTabListeners } from './utils'

// Types
import { GrapesEditorProps } from './types'

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
      storageManager: false,

      // Parser config
      parser: {
        optionsHtml: {
          allowScripts: true,
        },
      },

      // Device manager
      deviceManager: deviceManagerConfig,

      // Plugins - use plugins without grapesjs-tabs to avoid conflicts with custom tab presets
      plugins: getPluginsWithoutTabs(),
      pluginsOpts: {
        'grapesjs-preset-webpage': {
          modalImportTitle: 'Import Template',
          modalImportLabel: '<div style="margin-bottom: 10px;">Paste your HTML/CSS here</div>',
          modalImportContent: (ed: Editor) => {
            return ed.getHtml() + '<style>' + ed.getCss() + '</style>'
          },
        },
        'grapesjs-blocks-basic': {
          flexGrid: true,
        },
        'grapesjs-plugin-forms': {
          blocks: ['form', 'input', 'textarea', 'select', 'button', 'label', 'checkbox', 'radio'],
        },
        'grapesjs-navbar': {
          block: { category: 'Extra' },
        },
        'grapesjs-blocks-flexbox': {
          flexboxBlock: { category: 'Layout' },
        },
        'grapesjs-plugin-export': {
          addExportBtn: true,
        },
        'grapesjs-style-gradient': {
          colorPicker: 'default',
        },
      },

      // Canvas configuration
      canvas: canvasConfig,

      // Asset manager - we use custom upload handling via setupImageUpload
      assetManager: {
        assets: [],
        autoAdd: false,
        dropzone: false, // Disable default dropzone, we handle it ourselves
      },

      // Style manager
      styleManager: styleManagerConfig,
    })

    // Register custom blocks
    registerCustomBlocks(editor)
    
    // Register custom video component type with editable traits
    editor.DomComponents.addType('video', {
      isComponent: (el) => el.tagName === 'VIDEO',
      model: {
        defaults: {
          tagName: 'video',
          draggable: true,
          droppable: false,
          traits: [
            {
              type: 'text',
              name: 'src',
              label: 'Video URL',
              placeholder: 'https://example.com/video.mp4 or upload below',
              changeProp: true,
            },
            {
              type: 'button',
              name: 'upload-video',
              label: 'Upload Video',
              text: 'Choose Video File',
              command: 'open-video-upload',
            },
            {
              type: 'checkbox',
              name: 'autoplay',
              label: 'Autoplay',
              valueTrue: 'autoplay',
              valueFalse: '',
            },
            {
              type: 'checkbox',
              name: 'loop',
              label: 'Loop',
              valueTrue: 'loop',
              valueFalse: '',
            },
            {
              type: 'checkbox',
              name: 'muted',
              label: 'Muted',
              valueTrue: 'muted',
              valueFalse: '',
            },
            {
              type: 'checkbox',
              name: 'controls',
              label: 'Show Controls',
              valueTrue: 'controls',
              valueFalse: '',
            },
            {
              type: 'checkbox',
              name: 'playsinline',
              label: 'Plays Inline',
              valueTrue: 'playsinline',
              valueFalse: '',
            },
            {
              type: 'text',
              name: 'poster',
              label: 'Poster Image URL',
              placeholder: 'https://example.com/poster.jpg',
            },
          ],
        },
        init() {
          this.on('change:src', this.updateVideoSrc);
        },
        updateVideoSrc() {
          const src = this.get('src');
          if (src) {
            const sourceEl = this.components().find((c: any) => c.get('tagName') === 'source');
            if (sourceEl) {
              sourceEl.addAttributes({ src });
            } else {
              this.components().add({
                tagName: 'source',
                attributes: { src, type: 'video/mp4' }
              });
            }
          }
        },
      },
    });

    // Register custom iframe component type for YouTube video backgrounds
    editor.DomComponents.addType('iframe', {
      isComponent: (el) => el.tagName === 'IFRAME',
      model: {
        defaults: {
          tagName: 'iframe',
          draggable: true,
          droppable: false,
          attributes: {
            frameborder: '0',
            allowfullscreen: true,
          },
          traits: [
            {
              type: 'text',
              name: 'data-video-id',
              label: 'YouTube Video ID',
              placeholder: 'e.g., dQw4w9WgXcQ (from youtube.com/watch?v=ID)',
              changeProp: true,
            },
            {
              type: 'text',
              name: 'src',
              label: 'Full Embed URL',
              placeholder: 'https://www.youtube.com/embed/VIDEO_ID',
            },
            {
              type: 'button',
              name: 'convert-to-video',
              label: 'Use Uploaded Video Instead',
              text: 'Switch to Self-Hosted Video',
              command: 'convert-iframe-to-video',
            },
          ],
        },
        init() {
          this.on('change:data-video-id', this.updateYouTubeUrl);
        },
        updateYouTubeUrl() {
          const videoId = this.get('data-video-id') || this.getAttributes()['data-video-id'];
          if (videoId && videoId.length > 5) {
            const url = `https://www.youtube.com/embed/${videoId}?autoplay=1&mute=1&controls=0&loop=1&playlist=${videoId}&showinfo=0&rel=0&iv_load_policy=3&playsinline=1`;
            this.addAttributes({ src: url, 'data-video-id': videoId });
          }
        },
      },
    });

    // Command to convert iframe to video element
    editor.Commands.add('convert-iframe-to-video', {
      run(editor) {
        const selected = editor.getSelected();
        if (selected && selected.get('tagName') === 'iframe') {
          const parent = selected.parent();
          const index = parent?.components().indexOf(selected);
          
          // Remove iframe
          selected.remove();
          
          // Create video element with same styling
          if (parent && index !== undefined) {
            const videoComponent = parent.components().add({
              tagName: 'video',
              attributes: {
                autoplay: true,
                muted: true,
                loop: true,
                playsinline: true,
                class: 'video-bg',
              },
              style: {
                position: 'absolute',
                top: '50%',
                left: '50%',
                'min-width': '100%',
                'min-height': '100%',
                width: 'auto',
                height: 'auto',
                transform: 'translate(-50%, -50%)',
                'object-fit': 'cover',
              },
              components: [
                {
                  tagName: 'source',
                  attributes: { src: '', type: 'video/mp4' }
                }
              ]
            }, { at: index });
            
            // Select the new video and open trait manager
            editor.select(videoComponent);
            editor.runCommand('open-tm');
            
            // Show instructions and open file picker after a short delay
            setTimeout(() => {
              // Create and trigger file input
              const input = document.createElement('input');
              input.type = 'file';
              input.accept = 'video/mp4,video/webm,video/ogg';
              input.style.display = 'none';
              document.body.appendChild(input);
              
              input.onchange = async (e) => {
                const file = (e.target as HTMLInputElement).files?.[0];
                document.body.removeChild(input);
                
                if (!file) {
                  alert('No file selected. You can upload a video using the "Choose Video File" button in the Settings panel.');
                  return;
                }
                
                const blobUrl = URL.createObjectURL(file);
                
                // Update the video source
                const sourceEl = videoComponent.components().find((c: any) => c.get('tagName') === 'source');
                if (sourceEl) {
                  sourceEl.addAttributes({ src: blobUrl });
                }
                videoComponent.set('src', blobUrl);
                videoComponent.addAttributes({ src: blobUrl });
                
                // Force video element to reload
                const videoEl = videoComponent.view?.el as HTMLVideoElement;
                if (videoEl) {
                  videoEl.load();
                  videoEl.play().catch(() => {});
                }
                
                alert('Video uploaded successfully!\n\nNote: For production, upload the video to your server and update the Video URL in the Settings panel.');
              };
              
              // Handle cancel
              input.oncancel = () => {
                document.body.removeChild(input);
                alert('Video upload cancelled.\n\nYou can upload a video anytime using the "Choose Video File" button in the Settings panel (gear icon).');
              };
              
              input.click();
            }, 200);
          }
        }
      }
    });

    // Command to open video upload dialog
    editor.Commands.add('open-video-upload', {
      run(editor) {
        const selected = editor.getSelected();
        if (!selected) {
          alert('Please select a video element first.');
          return;
        }
        
        // Create file input and append to body for better browser compatibility
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'video/mp4,video/webm,video/ogg';
        input.style.display = 'none';
        document.body.appendChild(input);
        
        input.onchange = async (e) => {
          const file = (e.target as HTMLInputElement).files?.[0];
          document.body.removeChild(input);
          
          if (!file) return;
          
          const blobUrl = URL.createObjectURL(file);
          
          // Update the video source
          if (selected.get('tagName') === 'video' || selected.get('tagName') === 'VIDEO') {
            selected.set('src', blobUrl);
            selected.addAttributes({ src: blobUrl });
            
            const sourceEl = selected.components().find((c: any) => {
              const tag = c.get('tagName');
              return tag === 'source' || tag === 'SOURCE';
            });
            if (sourceEl) {
              sourceEl.addAttributes({ src: blobUrl });
            } else {
              // Add source element if it doesn't exist
              selected.components().add({
                tagName: 'source',
                attributes: { src: blobUrl, type: 'video/mp4' }
              });
            }
            
            // Force video element to reload and play
            const videoEl = selected.view?.el as HTMLVideoElement;
            if (videoEl) {
              videoEl.load();
              videoEl.play().catch(() => {});
            }
          }
          
          alert('Video uploaded successfully!\n\nNote: For production, upload the video to your server and update the Video URL field.');
        };
        
        // Handle cancel/close
        input.oncancel = () => {
          document.body.removeChild(input);
        };
        
        // Small delay to ensure DOM is ready
        setTimeout(() => input.click(), 50);
      }
    });

    // Command to upload video for video background section
    editor.Commands.add('upload-video-background', {
      run(editor) {
        const selected = editor.getSelected();
        if (!selected) {
          alert('Please select a video background section first.');
          return;
        }
        
        // Create file input and append to body for better browser compatibility
        const input = document.createElement('input');
        input.type = 'file';
        input.accept = 'video/mp4,video/webm,video/ogg';
        input.style.display = 'none';
        document.body.appendChild(input);
        
        input.onchange = async (e) => {
          const file = (e.target as HTMLInputElement).files?.[0];
          document.body.removeChild(input);
          
          if (!file) return;
          
          const blobUrl = URL.createObjectURL(file);
          
          // Update the video-url property
          selected.set('video-url', blobUrl);
          selected.set('video-type', 'file');
          
          // Also update the video element directly
          const video = selected.components().find((c: any) => c.get('type') === 'video-bg-element');
          if (video) {
            const source = video.components().at(0);
            if (source) {
              source.addAttributes({ src: blobUrl });
            }
            
            // Force video to reload
            const videoEl = video.view?.el as HTMLVideoElement;
            if (videoEl) {
              videoEl.load();
              videoEl.play().catch(() => {});
            }
          }
          
          alert('Video uploaded successfully!\n\nNote: For production, upload the video to your server and update the Video URL in Settings.');
        };
        
        // Handle cancel/close
        input.oncancel = () => {
          document.body.removeChild(input);
        };
        
        // Small delay to ensure DOM is ready
        setTimeout(() => input.click(), 50);
      }
    });

    // Register source component type
    editor.DomComponents.addType('source', {
      isComponent: (el) => el.tagName === 'SOURCE',
      model: {
        defaults: {
          tagName: 'source',
          draggable: false,
          droppable: false,
          layerable: false,
          selectable: false,
          hoverable: false,
          traits: [
            {
              type: 'text',
              name: 'src',
              label: 'Source URL',
            },
            {
              type: 'select',
              name: 'type',
              label: 'Type',
              options: [
                { id: 'video/mp4', name: 'MP4' },
                { id: 'video/webm', name: 'WebM' },
                { id: 'video/ogg', name: 'OGG' },
              ],
            },
          ],
        },
      },
    });
    
    // Add custom presets panel
    addPresetsPanel(editor)

    // Add save command
    editor.Commands.add('save-project', {
      run: () => handleSave(),
    })

    // Remove ALL default GrapesJS keymaps to prevent conflicts
    // We handle all shortcuts ourselves
    const keymaps = editor.Keymaps
    const allKeymaps = keymaps.getAll()
    Object.keys(allKeymaps).forEach(id => {
      try {
        keymaps.remove(id)
      } catch (e) {
        // Ignore errors
      }
    })

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

    // Setup Lucide icon listeners
    const cleanupLucide = setupLucideListeners(editor)

    // Setup custom context menu (replaces browser default)
    const cleanupContextMenu = setupContextMenu(editor)

    // Setup keyboard shortcuts
    const cleanupKeyboardShortcuts = setupKeyboardShortcuts(editor)

    // Setup image upload with editor
    setupImageUpload(editor)

    // Setup tab functionality for preview mode
    const cleanupTabListeners = setupTabListeners(editor)

    // Customize panel icons after editor is fully loaded
    editor.on('load', () => {
      customizePanelIcons(editor)
      addUploadButton(editor)
      addIconSearch(editor)
      
      // Close all block categories by default - user opens what they need
      setTimeout(() => {
        const blockCategories = document.querySelectorAll('.gjs-block-category')
        blockCategories.forEach((category) => {
          category.classList.remove('gjs-open')
          const blocksContainer = category.querySelector('.gjs-blocks-c') as HTMLElement
          if (blocksContainer) {
            blocksContainer.style.display = 'none'
          }
          // Rotate caret to closed position
          const caret = category.querySelector('.gjs-caret-icon') as HTMLElement
          if (caret) {
            caret.style.transform = 'rotate(-90deg)'
          }
        })
      }, 100)
    })

    // Auto-open the relevant inspector when a component is selected.
    editor.on('component:selected', (component: any) => {
      const tagName = component.get('tagName')?.toUpperCase() || component.view?.el?.tagName?.toUpperCase()
      const componentType = component.get('type')
      
      // Open trait manager for video-related / special components
      if (tagName === 'SVG' || tagName === 'VIDEO' || tagName === 'IFRAME' || componentType === 'video-background-section') {
        editor.runCommand('open-tm')
        return
      }

      // For every other block, surface the Style Manager so color, typography,
      // background image, borders, spacing, etc. are immediately editable.
      // Without this the right panel keeps showing the Blocks/Presets panel and
      // styling controls appear unavailable. Skip the root wrapper.
      if (componentType !== 'wrapper' && tagName !== 'BODY') {
        editor.runCommand('open-sm')
      }
    })

    // Double-click on image to open image editor
    editor.on('component:dblclick', (component: any) => {
      const tagName = component.get('tagName')?.toUpperCase() || component.view?.el?.tagName?.toUpperCase()
      if (tagName === 'IMG') {
        const src = component.get('src') || component.getAttributes()?.src
        if (src) {
          editor.runCommand('open-image-editor', { image: src, target: component })
        }
      }
      // Double-click on video or iframe to open trait manager for editing
      if (tagName === 'VIDEO' || tagName === 'IFRAME') {
        editor.runCommand('open-tm')
      }
    })

    // Handle tab switching in editor canvas - allows editing different tab content
    editor.on('component:selected', (component: any) => {
      const el = component.view?.el
      if (!el) return

      // Check if this is a tab button (link with href starting with #)
      const isTabButton = el.tagName === 'A' && el.getAttribute('href')?.startsWith('#')
      const hasTabClass = el.classList?.contains('capsule-btn') ||
                          el.classList?.contains('neon-btn') ||
                          el.classList?.contains('glass-btn') ||
                          el.classList?.contains('folder-btn') ||
                          el.classList?.contains('clean-btn') ||
                          el.classList?.contains('grad-btn') ||
                          el.classList?.contains('term-btn') ||
                          el.classList?.contains('float-btn') ||
                          el.classList?.contains('mesh-btn') ||
                          el.classList?.contains('f9-nav-item')

      if (isTabButton || hasTabClass) {
        const href = el.getAttribute('href')
        if (!href || href === '#') return

        const targetId = href.replace('#', '')
        const tabContainer = el.closest('[data-tab-group]') || el.closest('[data-faq-tabs]') || el.closest('section')
        if (!tabContainer) return

        // Get the button class to determine which selector to use
        const btnClasses = ['capsule-btn', 'neon-btn', 'glass-btn', 'folder-btn', 'clean-btn', 'grad-btn', 'term-btn', 'float-btn', 'mesh-btn', 'f9-nav-item']
        let btnClass = ''
        for (const cls of btnClasses) {
          if (el.classList?.contains(cls)) {
            btnClass = cls
            break
          }
        }

        // Remove active from all buttons in this container
        if (btnClass) {
          tabContainer.querySelectorAll(`.${btnClass}`).forEach((btn: Element) => {
            btn.classList.remove('active')
          })
        }

        // Add active to clicked button
        el.classList.add('active')

        // Handle FAQ vertical tabs (f9-tab-content)
        if (btnClass === 'f9-nav-item') {
          tabContainer.querySelectorAll('.f9-tab-content').forEach((content: Element) => {
            content.classList.remove('active')
          })
          const targetContent = tabContainer.querySelector(`#${targetId}`)
          if (targetContent) {
            targetContent.classList.add('active')
          }
        } else {
          // Hide all tab panes and show the target
          tabContainer.querySelectorAll('.tab-pane').forEach((pane: Element) => {
            pane.classList.remove('active')
            ;(pane as HTMLElement).style.display = 'none'
          })

          const targetPane = tabContainer.querySelector(`#${targetId}`)
          if (targetPane) {
            targetPane.classList.add('active')
            ;(targetPane as HTMLElement).style.display = 'block'
          }
        }
      }

      // Handle FAQ accordion toggle when selecting summary elements
      const isSummary = el.tagName === 'SUMMARY'
      if (isSummary) {
        const details = el.parentElement
        if (details && details.tagName === 'DETAILS') {
          // Toggle the details element
          if (details.hasAttribute('open')) {
            details.removeAttribute('open')
          } else {
            details.setAttribute('open', '')
          }
        }
      }
    })

    // Handle style updates - remove conflicting inline styles when using style manager
    editor.on('component:styleUpdate', (component: any) => {
      const el = component.view?.el
      if (!el) return

      // Get the style being updated
      const style = component.getStyle()
      
      // If background-color is being set via style manager, remove inline background styles
      if (style['background-color'] || style['background']) {
        // Remove inline background styles that might conflict
        if (el.style.backgroundColor) {
          el.style.backgroundColor = ''
        }
        if (el.style.background) {
          el.style.background = ''
        }
        
        // Also update the component's inline style attribute
        const inlineStyle = component.get('style') || {}
        if (inlineStyle['background-color'] || inlineStyle['background']) {
          const newStyle = { ...inlineStyle }
          delete newStyle['background-color']
          delete newStyle['background']
          component.set('style', newStyle)
        }
      }
    })

    // Handle component cloning - generate unique IDs for tabs and FAQs
    editor.on('component:clone', (clonedComponent: any) => {
      const html = clonedComponent.toHTML()
      
      // Check if this is a tab or FAQ component that needs unique IDs
      const hasTabGroup = html.includes('data-tab-group=')
      const hasFaqTabs = html.includes('data-faq-tabs=')
      
      if (hasTabGroup || hasFaqTabs) {
        const uniqueId = `clone${Date.now()}${Math.random().toString(36).substr(2, 5)}`
        
        // Function to recursively update IDs in component tree
        const updateIds = (comp: any) => {
          // Update attributes
          const attrs = comp.getAttributes() || {}
          
          // Update data-tab-group
          if (attrs['data-tab-group']) {
            const oldId = attrs['data-tab-group']
            comp.addAttributes({ 'data-tab-group': uniqueId })
            
            // Update all href and id attributes in children
            comp.components().forEach((child: any) => updateChildIds(child, oldId, uniqueId))
          }
          
          // Update data-faq-tabs
          if (attrs['data-faq-tabs']) {
            comp.addAttributes({ 'data-faq-tabs': uniqueId })
          }
          
          // Recursively check children
          comp.components().forEach((child: any) => updateIds(child))
        }
        
        const updateChildIds = (comp: any, oldId: string, newId: string) => {
          const attrs = comp.getAttributes() || {}
          
          // Update href attributes
          if (attrs.href && attrs.href.includes(`#${oldId}`)) {
            comp.addAttributes({ href: attrs.href.replace(oldId, newId) })
          }
          
          // Update id attributes
          if (attrs.id && attrs.id.includes(oldId)) {
            comp.addAttributes({ id: attrs.id.replace(oldId, newId) })
          }
          
          // Recursively update children
          comp.components().forEach((child: any) => updateChildIds(child, oldId, newId))
        }
        
        updateIds(clonedComponent)
        
        // Also update any inline scripts
        const scriptComps = clonedComponent.find('script')
        scriptComps.forEach((scriptComp: any) => {
          const content = scriptComp.get('content') || ''
          if (content.includes('data-tab-group') || content.includes('data-faq-tabs')) {
            // The script will use document.currentScript.parentElement which will work correctly
            // No need to update script content as it's self-contained
          }
        })
      }
    })

    // Fallback: Try to customize icons after a delay
    setTimeout(() => {
      customizePanelIcons(editor)
      addUploadButton(editor)
      addIconSearch(editor)
    }, 1000)

    return () => {
      cleanupLucide()
      cleanupContextMenu()
      cleanupKeyboardShortcuts()
      cleanupTabListeners()
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

export default GrapesEditor
