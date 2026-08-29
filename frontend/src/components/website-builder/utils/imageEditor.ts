// Image Editor Utility for GrapesJS
// Modern design with Iconify Solar icons

import { Editor } from 'grapesjs'

// Solar icons SVGs
const icons = {
  close: `<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2c5.523 0 10 4.477 10 10s-4.477 10-10 10S2 17.523 2 12S6.477 2 12 2m3.53 6.47a.75.75 0 0 0-1.06 0L12 10.94l-2.47-2.47a.75.75 0 0 0-1.06 1.06L10.94 12l-2.47 2.47a.75.75 0 1 0 1.06 1.06L12 13.06l2.47 2.47a.75.75 0 1 0 1.06-1.06L13.06 12l2.47-2.47a.75.75 0 0 0 0-1.06"/></svg>`,
  crop: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M17 15h2V7c0-1.1-.9-2-2-2H9v2h8zM7 17V1H5v4H1v2h4v10c0 1.1.9 2 2 2h10v4h2v-4h4v-2z"/></svg>`,
  adjust: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M3 17v2h6v-2zM3 5v2h10V5zm10 16v-2h8v-2h-8v-2h-2v6zM7 9v2H3v2h4v2h2V9zm14 4v-2H11v2zm-6-4h2V7h4V5h-4V3h-2z"/></svg>`,
  filter: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10s10-4.48 10-10S17.52 2 12 2m-1 17.93c-3.94-.49-7-3.85-7-7.93s3.05-7.44 7-7.93z"/></svg>`,
  eraser: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="m16.24 3.56l4.95 4.94c.78.79.78 2.05 0 2.84L12 20.53a4.01 4.01 0 0 1-5.66 0L2.81 17c-.78-.79-.78-2.05 0-2.84l10.6-10.6c.79-.78 2.05-.78 2.83 0M4.22 15.58l3.54 3.53c.78.79 2.04.79 2.83 0l3.53-3.53l-4.95-4.95z"/></svg>`,
  upload: `<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path fill="currentColor" d="M11 16V7.85l-2.6 2.6L7 9l5-5l5 5l-1.4 1.45l-2.6-2.6V16zm-5 4q-.825 0-1.412-.587T4 18v-3h2v3h12v-3h2v3q0 .825-.587 1.413T18 20z"/></svg>`,
  image: `<svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24"><path fill="currentColor" d="M5 21q-.825 0-1.412-.587T3 19V5q0-.825.588-1.412T5 3h14q.825 0 1.413.588T21 5v14q0 .825-.587 1.413T19 21zm0-2h14V5H5zm1-2h12l-3.75-5l-3 4L9 13zm-1 2V5z"/></svg>`,
  check: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19L21 7l-1.41-1.41z"/></svg>`,
  reset: `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24"><path fill="currentColor" d="M12 5V1L7 6l5 5V7c3.31 0 6 2.69 6 6s-2.69 6-6 6s-6-2.69-6-6H4c0 4.42 3.58 8 8 8s8-3.58 8-8s-3.58-8-8-8"/></svg>`,
  sparkle: `<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24"><path fill="currentColor" d="m19 9l1.25-2.75L23 5l-2.75-1.25L19 1l-1.25 2.75L15 5l2.75 1.25zm0 14l1.25-2.75L23 19l-2.75-1.25L19 15l-1.25 2.75L15 19l2.75 1.25zM9 4l1.5 3.5L14 9l-3.5 1.5L9 14l-1.5-3.5L4 9l3.5-1.5z"/></svg>`,
}

interface ImageEditorOptions {
  image: string | File
  onSave: (dataUrl: string) => void
  onCancel: () => void
}

// Create the image editor modal
export function createImageEditorModal(editor: Editor, options: ImageEditorOptions) {
  void editor
  const { image, onSave, onCancel } = options
  
  // Create modal container
  const modal = document.createElement('div')
  modal.className = 'img-editor-modal'
  modal.innerHTML = `
    <div class="img-editor-backdrop"></div>
    <div class="img-editor-dialog">
      <div class="img-editor-header">
        <div class="img-editor-title">
          ${icons.image.replace('width="48" height="48"', 'width="20" height="20"')}
          <span>Edit Image</span>
        </div>
        <button class="img-editor-close">${icons.close}</button>
      </div>
      
      <div class="img-editor-content">
        <div class="img-editor-preview">
          <div class="img-editor-canvas-container">
            <canvas id="img-editor-canvas"></canvas>
          </div>
        </div>
        
        <div class="img-editor-tools">
          <div class="img-editor-tabs">
            <button class="img-tab active" data-tab="crop">${icons.crop}<span>Crop</span></button>
            <button class="img-tab" data-tab="adjust">${icons.adjust}<span>Adjust</span></button>
            <button class="img-tab" data-tab="filters">${icons.filter}<span>Filters</span></button>
            <button class="img-tab" data-tab="bg">${icons.eraser}<span>Remove BG</span></button>
          </div>
          
          <div class="img-tab-content" id="panel-crop">
            <div class="img-panel-title">Aspect Ratio</div>
            <div class="img-ratio-grid">
              <button class="img-ratio-btn active" data-ratio="free">Free</button>
              <button class="img-ratio-btn" data-ratio="1:1">1:1</button>
              <button class="img-ratio-btn" data-ratio="16:9">16:9</button>
              <button class="img-ratio-btn" data-ratio="4:3">4:3</button>
              <button class="img-ratio-btn" data-ratio="3:2">3:2</button>
              <button class="img-ratio-btn" data-ratio="9:16">9:16</button>
            </div>
            <button class="img-apply-btn" id="apply-crop">${icons.check}<span>Apply Crop</span></button>
          </div>
          
          <div class="img-tab-content hidden" id="panel-adjust">
            <div class="img-slider-group">
              <div class="img-slider-header">
                <span>Brightness</span>
                <span class="img-slider-value" id="brightness-val">0</span>
              </div>
              <input type="range" class="img-slider" id="brightness" min="-100" max="100" value="0">
            </div>
            <div class="img-slider-group">
              <div class="img-slider-header">
                <span>Contrast</span>
                <span class="img-slider-value" id="contrast-val">0</span>
              </div>
              <input type="range" class="img-slider" id="contrast" min="-100" max="100" value="0">
            </div>
            <div class="img-slider-group">
              <div class="img-slider-header">
                <span>Saturation</span>
                <span class="img-slider-value" id="saturation-val">0</span>
              </div>
              <input type="range" class="img-slider" id="saturation" min="-100" max="100" value="0">
            </div>
            <div class="img-slider-group">
              <div class="img-slider-header">
                <span>Blur</span>
                <span class="img-slider-value" id="blur-val">0</span>
              </div>
              <input type="range" class="img-slider" id="blur" min="0" max="20" value="0">
            </div>
            <button class="img-reset-btn" id="reset-adjust">${icons.reset}<span>Reset All</span></button>
          </div>
          
          <div class="img-tab-content hidden" id="panel-filters">
            <div class="img-filter-grid">
              <button class="img-filter-btn active" data-filter="none">
                <div class="img-filter-preview none"></div>
                <span>None</span>
              </button>
              <button class="img-filter-btn" data-filter="grayscale">
                <div class="img-filter-preview grayscale"></div>
                <span>B&W</span>
              </button>
              <button class="img-filter-btn" data-filter="sepia">
                <div class="img-filter-preview sepia"></div>
                <span>Sepia</span>
              </button>
              <button class="img-filter-btn" data-filter="vintage">
                <div class="img-filter-preview vintage"></div>
                <span>Vintage</span>
              </button>
              <button class="img-filter-btn" data-filter="warm">
                <div class="img-filter-preview warm"></div>
                <span>Warm</span>
              </button>
              <button class="img-filter-btn" data-filter="cool">
                <div class="img-filter-preview cool"></div>
                <span>Cool</span>
              </button>
              <button class="img-filter-btn" data-filter="dramatic">
                <div class="img-filter-preview dramatic"></div>
                <span>Dramatic</span>
              </button>
              <button class="img-filter-btn" data-filter="fade">
                <div class="img-filter-preview fade"></div>
                <span>Fade</span>
              </button>
            </div>
          </div>
          
          <div class="img-tab-content hidden" id="panel-bg">
            <div class="img-bg-info">
              <p>Remove solid color backgrounds. Works best with uniform backgrounds.</p>
            </div>
            <button class="img-bg-btn" id="remove-bg">
              ${icons.sparkle}
              <span>Remove Background</span>
            </button>
            <div class="img-bg-progress hidden" id="bg-progress">
              <div class="img-progress-bar"><div class="img-progress-fill"></div></div>
              <span>Processing...</span>
            </div>
            <p class="img-bg-tip">For complex backgrounds, use remove.bg</p>
          </div>
        </div>
      </div>
      
      <div class="img-editor-footer">
        <button class="img-cancel-btn">Cancel</button>
        <button class="img-save-btn">${icons.check}<span>Save Image</span></button>
      </div>
    </div>
  `

  // Add styles
  const styles = document.createElement('style')
  styles.id = 'img-editor-styles'
  styles.textContent = `
    .img-editor-modal { position: fixed; inset: 0; z-index: 100000; display: flex; align-items: center; justify-content: center; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
    .img-editor-backdrop { position: absolute; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(4px); }
    .img-editor-dialog { position: relative; width: 95vw; max-width: 1100px; height: 90vh; max-height: 700px; background: #18181b; border-radius: 16px; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid rgba(255,255,255,0.1); }
    
    .img-editor-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.08); }
    .img-editor-title { display: flex; align-items: center; gap: 10px; color: #fff; font-size: 15px; font-weight: 600; }
    .img-editor-title svg { color: #a1a1aa; }
    .img-editor-close { background: transparent; border: none; color: #71717a; cursor: pointer; padding: 6px; border-radius: 8px; display: flex; transition: all 0.15s; }
    .img-editor-close:hover { background: rgba(255,255,255,0.1); color: #fff; }
    
    .img-editor-content { flex: 1; display: flex; overflow: hidden; }
    
    .img-editor-preview { flex: 1; display: flex; align-items: center; justify-content: center; padding: 24px; background: #09090b; position: relative; }
    .img-editor-canvas-container { position: relative; max-width: 100%; max-height: 100%; }
    #img-editor-canvas { max-width: 100%; max-height: 100%; border-radius: 8px; display: block; }
    
    .img-editor-tools { width: 280px; background: #18181b; border-left: 1px solid rgba(255,255,255,0.08); display: flex; flex-direction: column; }
    
    .img-editor-tabs { display: flex; padding: 12px 12px 0; gap: 4px; }
    .img-tab { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 10px 6px; background: transparent; border: none; color: #71717a; font-size: 10px; cursor: pointer; border-radius: 8px; transition: all 0.15s; }
    .img-tab svg { width: 18px; height: 18px; }
    .img-tab:hover { background: rgba(255,255,255,0.05); color: #a1a1aa; }
    .img-tab.active { background: rgba(42,157,143,0.15); color: #3db8a9; }
    
    .img-tab-content { padding: 16px; flex: 1; overflow-y: auto; }
    .img-tab-content.hidden { display: none; }
    
    .img-panel-title { color: #a1a1aa; font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; }
    
    .img-ratio-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin-bottom: 16px; }
    .img-ratio-btn { padding: 10px 8px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 8px; color: #a1a1aa; font-size: 12px; cursor: pointer; transition: all 0.15s; }
    .img-ratio-btn:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.15); color: #fff; }
    .img-ratio-btn.active { background: rgba(42,157,143,0.2); border-color: #2a9d8f; color: #fff; }
    
    .img-apply-btn, .img-reset-btn, .img-bg-btn { width: 100%; padding: 12px; background: #2a9d8f; border: none; border-radius: 10px; color: #fff; font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; justify-content: center; gap: 8px; transition: all 0.15s; }
    .img-apply-btn:hover, .img-bg-btn:hover { background: #238b7e; }
    .img-reset-btn { background: rgba(255,255,255,0.1); margin-top: 16px; }
    .img-reset-btn:hover { background: rgba(255,255,255,0.15); }
    
    .img-slider-group { margin-bottom: 20px; }
    .img-slider-header { display: flex; justify-content: space-between; margin-bottom: 8px; }
    .img-slider-header span { color: #a1a1aa; font-size: 12px; }
    .img-slider-value { color: #2a9d8f; font-weight: 500; }
    .img-slider { width: 100%; height: 4px; -webkit-appearance: none; background: rgba(255,255,255,0.1); border-radius: 2px; outline: none; }
    .img-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 16px; height: 16px; background: #2a9d8f; border-radius: 50%; cursor: pointer; border: 2px solid #18181b; }
    .img-slider::-moz-range-thumb { width: 16px; height: 16px; background: #2a9d8f; border-radius: 50%; cursor: pointer; border: 2px solid #18181b; }
    
    .img-filter-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .img-filter-btn { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 10px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 10px; cursor: pointer; transition: all 0.15s; }
    .img-filter-btn:hover { background: rgba(255,255,255,0.06); border-color: rgba(255,255,255,0.12); }
    .img-filter-btn.active { background: rgba(42,157,143,0.15); border-color: #2a9d8f; }
    .img-filter-btn span { color: #a1a1aa; font-size: 11px; }
    .img-filter-btn.active span { color: #fff; }
    .img-filter-preview { width: 100%; aspect-ratio: 16/10; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 6px; }
    .img-filter-preview.grayscale { filter: grayscale(100%); }
    .img-filter-preview.sepia { filter: sepia(80%); }
    .img-filter-preview.vintage { filter: sepia(40%) contrast(90%); }
    .img-filter-preview.warm { filter: sepia(20%) saturate(140%); }
    .img-filter-preview.cool { filter: saturate(80%) hue-rotate(20deg); }
    .img-filter-preview.dramatic { filter: contrast(130%) saturate(120%); }
    .img-filter-preview.fade { filter: contrast(90%) brightness(110%) saturate(80%); }
    
    .img-bg-info { background: rgba(42,157,143,0.1); border: 1px solid rgba(42,157,143,0.2); border-radius: 10px; padding: 12px; margin-bottom: 16px; }
    .img-bg-info p { color: #a1a1aa; font-size: 12px; line-height: 1.5; margin: 0; }
    .img-bg-tip { color: #52525b; font-size: 11px; text-align: center; margin-top: 12px; }
    .img-bg-progress { display: flex; flex-direction: column; gap: 8px; align-items: center; margin-top: 16px; }
    .img-bg-progress.hidden { display: none; }
    .img-bg-progress span { color: #a1a1aa; font-size: 12px; }
    .img-progress-bar { width: 100%; height: 4px; background: rgba(255,255,255,0.1); border-radius: 2px; overflow: hidden; }
    .img-progress-fill { width: 0%; height: 100%; background: linear-gradient(90deg, #2a9d8f, #3db8a9); animation: img-progress 1.5s ease-in-out infinite; }
    @keyframes img-progress { 0% { width: 0%; } 50% { width: 80%; } 100% { width: 100%; } }
    
    .img-editor-footer { display: flex; justify-content: flex-end; gap: 10px; padding: 16px 20px; border-top: 1px solid rgba(255,255,255,0.08); }
    .img-cancel-btn { padding: 10px 20px; background: rgba(255,255,255,0.1); border: none; border-radius: 10px; color: #a1a1aa; font-size: 13px; cursor: pointer; transition: all 0.15s; }
    .img-cancel-btn:hover { background: rgba(255,255,255,0.15); color: #fff; }
    .img-save-btn { padding: 10px 24px; background: #2a9d8f; border: none; border-radius: 10px; color: #fff; font-size: 13px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.15s; }
    .img-save-btn:hover { background: #238b7e; }
    
    .img-crop-overlay { position: absolute; inset: 0; pointer-events: none; }
    .img-crop-box { position: absolute; border: 2px solid #2a9d8f; background: rgba(42,157,143,0.1); cursor: move; pointer-events: auto; box-shadow: 0 0 0 9999px rgba(0,0,0,0.5); }
    .img-crop-handle { position: absolute; width: 12px; height: 12px; background: #fff; border: 2px solid #2a9d8f; border-radius: 2px; }
  `
  
  document.head.appendChild(styles)
  document.body.appendChild(modal)

  // Get elements
  const canvas = modal.querySelector('#img-editor-canvas') as HTMLCanvasElement
  const ctx = canvas.getContext('2d')!
  const canvasContainer = modal.querySelector('.img-editor-canvas-container') as HTMLElement
  
  // State
  let originalImage: HTMLImageElement | null = null
  let currentFilter = 'none'
  let adjustments = { brightness: 0, contrast: 0, saturation: 0, blur: 0 }
  let cropBox: { x: number, y: number, width: number, height: number } | null = null
  let aspectRatio: number | null = null

  // Load image
  const loadImage = (src: string | File) => {
    const img = new Image()
    // Only set crossOrigin for non-data-URL sources; data URLs are same-origin
    // and forcing 'anonymous' on them has caused silent decode failures for
    // some PNGs in Chrome/Safari.
    // (crossOrigin is left unset for data URLs below.)

    const onImageLoad = () => {
      originalImage = img
      canvas.width = img.width
      canvas.height = img.height
      // PNG (and other formats with transparency) need an alpha-capable canvas
      // context. We always use 'willReadFrequently' for perf + alpha-aware
      // composite in the edit pipeline.
      renderImage()
    }

    const onImageError = (e: Event) => {
      console.error('Image decode failed:', e)
      const reason = src instanceof File
        ? `Could not decode "${src.name}". The file may be corrupt or in an unsupported sub-format (e.g. CMYK PNG, 16-bit PNG, animated APNG).`
        : 'Could not decode the image.'
      alert(reason)
      closeModal()
    }

    if (src instanceof File) {
      const reader = new FileReader()
      reader.onload = (e) => {
        img.onload = onImageLoad
        img.onerror = onImageError
        // Do NOT set img.crossOrigin for data URLs — same-origin already.
        img.src = e.target?.result as string
      }
      reader.onerror = onImageError
      reader.readAsDataURL(src)
    } else {
      // Remote URL — set crossOrigin so the canvas isn't tainted (tainted
      // canvases throw SecurityError on toDataURL).
      img.crossOrigin = 'anonymous'
      img.onload = onImageLoad
      img.onerror = onImageError
      img.src = src
    }
  }
  
  // Render image with current adjustments
  const renderImage = () => {
    if (!originalImage || !ctx) return
    
    ctx.clearRect(0, 0, canvas.width, canvas.height)
    
    let filterStr = ''
    filterStr += `brightness(${100 + adjustments.brightness}%) `
    filterStr += `contrast(${100 + adjustments.contrast}%) `
    filterStr += `saturate(${100 + adjustments.saturation}%) `
    if (adjustments.blur > 0) filterStr += `blur(${adjustments.blur}px) `
    
    switch (currentFilter) {
      case 'grayscale': filterStr += 'grayscale(100%) '; break
      case 'sepia': filterStr += 'sepia(80%) '; break
      case 'vintage': filterStr += 'sepia(40%) contrast(90%) brightness(90%) '; break
      case 'warm': filterStr += 'sepia(20%) saturate(140%) '; break
      case 'cool': filterStr += 'saturate(80%) hue-rotate(20deg) '; break
      case 'dramatic': filterStr += 'contrast(130%) saturate(120%) '; break
      case 'fade': filterStr += 'contrast(90%) brightness(110%) saturate(80%) '; break
    }
    
    ctx.filter = filterStr.trim() || 'none'
    ctx.drawImage(originalImage, 0, 0)
    ctx.filter = 'none'
  }

  // Tab switching
  modal.querySelectorAll('.img-tab').forEach(btn => {
    btn.addEventListener('click', () => {
      modal.querySelectorAll('.img-tab').forEach(b => b.classList.remove('active'))
      modal.querySelectorAll('.img-tab-content').forEach(c => c.classList.add('hidden'))
      btn.classList.add('active')
      const tabId = `panel-${btn.getAttribute('data-tab')}`
      modal.querySelector(`#${tabId}`)?.classList.remove('hidden')
      
      const isCropTab = btn.getAttribute('data-tab') === 'crop'
      if (!isCropTab) removeCropOverlay()
    })
  })
  
  // Crop ratio buttons
  modal.querySelectorAll('.img-ratio-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      modal.querySelectorAll('.img-ratio-btn').forEach(b => b.classList.remove('active'))
      btn.classList.add('active')
      
      const ratio = btn.getAttribute('data-ratio')
      if (ratio === 'free') {
        aspectRatio = null
      } else {
        const [w, h] = ratio!.split(':').map(Number)
        aspectRatio = w / h
      }
      initCropBox()
    })
  })
  
  // Initialize crop box
  const initCropBox = () => {
    removeCropOverlay()
    
    const rect = canvas.getBoundingClientRect()
    const overlay = document.createElement('div')
    overlay.className = 'img-crop-overlay'
    
    let boxWidth = rect.width * 0.75
    let boxHeight = rect.height * 0.75
    
    if (aspectRatio) {
      if (boxWidth / boxHeight > aspectRatio) {
        boxWidth = boxHeight * aspectRatio
      } else {
        boxHeight = boxWidth / aspectRatio
      }
    }
    
    cropBox = {
      x: (rect.width - boxWidth) / 2,
      y: (rect.height - boxHeight) / 2,
      width: boxWidth,
      height: boxHeight
    }
    
    const box = document.createElement('div')
    box.className = 'img-crop-box'
    updateCropBoxStyle(box)
    
    // Add resize handles
    const handles = ['nw', 'ne', 'sw', 'se']
    handles.forEach(pos => {
      const handle = document.createElement('div')
      handle.className = 'img-crop-handle'
      handle.style.cssText = `
        ${pos.includes('n') ? 'top: -6px;' : 'bottom: -6px;'}
        ${pos.includes('w') ? 'left: -6px;' : 'right: -6px;'}
        cursor: ${pos}-resize;
      `
      
      let isResizing = false
      let resizeStartX = 0, resizeStartY = 0
      let startBox = { x: 0, y: 0, width: 0, height: 0 }
      
      handle.addEventListener('mousedown', (e) => {
        e.stopPropagation()
        isResizing = true
        resizeStartX = e.clientX
        resizeStartY = e.clientY
        startBox = { ...cropBox! }
        
        const onMouseMove = (e: MouseEvent) => {
          if (!isResizing || !cropBox) return
          const dx = e.clientX - resizeStartX
          const dy = e.clientY - resizeStartY
          const canvasRect = canvas.getBoundingClientRect()
          
          let newX = startBox.x, newY = startBox.y
          let newW = startBox.width, newH = startBox.height
          
          if (pos.includes('e')) newW = Math.max(50, startBox.width + dx)
          if (pos.includes('w')) { newW = Math.max(50, startBox.width - dx); newX = startBox.x + (startBox.width - newW) }
          if (pos.includes('s')) newH = Math.max(50, startBox.height + dy)
          if (pos.includes('n')) { newH = Math.max(50, startBox.height - dy); newY = startBox.y + (startBox.height - newH) }
          
          if (aspectRatio) {
            if (pos.includes('e') || pos.includes('w')) newH = newW / aspectRatio
            else newW = newH * aspectRatio
          }
          
          newX = Math.max(0, Math.min(canvasRect.width - newW, newX))
          newY = Math.max(0, Math.min(canvasRect.height - newH, newY))
          
          cropBox = { x: newX, y: newY, width: newW, height: newH }
          updateCropBoxStyle(box)
        }
        
        const onMouseUp = () => {
          isResizing = false
          document.removeEventListener('mousemove', onMouseMove)
          document.removeEventListener('mouseup', onMouseUp)
        }
        
        document.addEventListener('mousemove', onMouseMove)
        document.addEventListener('mouseup', onMouseUp)
      })
      
      box.appendChild(handle)
    })
    
    // Make crop box draggable
    let isDragging = false
    let startX = 0, startY = 0
    
    box.addEventListener('mousedown', (e) => {
      if ((e.target as HTMLElement).classList.contains('img-crop-handle')) return
      isDragging = true
      startX = e.clientX - cropBox!.x
      startY = e.clientY - cropBox!.y
    })
    
    const onDragMove = (e: MouseEvent) => {
      if (!isDragging || !cropBox) return
      const rect = canvas.getBoundingClientRect()
      cropBox.x = Math.max(0, Math.min(rect.width - cropBox.width, e.clientX - startX))
      cropBox.y = Math.max(0, Math.min(rect.height - cropBox.height, e.clientY - startY))
      updateCropBoxStyle(box)
    }
    
    const onDragEnd = () => { isDragging = false }
    
    document.addEventListener('mousemove', onDragMove)
    document.addEventListener('mouseup', onDragEnd)
    
    overlay.appendChild(box)
    canvasContainer.appendChild(overlay)
  }
  
  const updateCropBoxStyle = (box: HTMLElement) => {
    if (!cropBox) return
    box.style.left = `${cropBox.x}px`
    box.style.top = `${cropBox.y}px`
    box.style.width = `${cropBox.width}px`
    box.style.height = `${cropBox.height}px`
  }
  
  const removeCropOverlay = () => {
    canvasContainer.querySelector('.img-crop-overlay')?.remove()
    cropBox = null
  }

  // Apply crop
  modal.querySelector('#apply-crop')?.addEventListener('click', () => {
    if (!cropBox || !originalImage) return
    
    const rect = canvas.getBoundingClientRect()
    const scaleX = originalImage.width / rect.width
    const scaleY = originalImage.height / rect.height
    
    const cropX = cropBox.x * scaleX
    const cropY = cropBox.y * scaleY
    const cropW = cropBox.width * scaleX
    const cropH = cropBox.height * scaleY
    
    const tempCanvas = document.createElement('canvas')
    tempCanvas.width = cropW
    tempCanvas.height = cropH
    const tempCtx = tempCanvas.getContext('2d')!
    tempCtx.drawImage(originalImage, cropX, cropY, cropW, cropH, 0, 0, cropW, cropH)
    
    const croppedImg = new Image()
    croppedImg.onload = () => {
      originalImage = croppedImg
      canvas.width = croppedImg.width
      canvas.height = croppedImg.height
      renderImage()
      removeCropOverlay()
    }
    croppedImg.src = tempCanvas.toDataURL('image/png')
  })

  // Adjustment sliders
  const sliders = ['brightness', 'contrast', 'saturation', 'blur']
  sliders.forEach(name => {
    const slider = modal.querySelector(`#${name}`) as HTMLInputElement
    const valueEl = modal.querySelector(`#${name}-val`)
    
    slider?.addEventListener('input', () => {
      adjustments[name as keyof typeof adjustments] = parseInt(slider.value)
      if (valueEl) valueEl.textContent = slider.value
      renderImage()
    })
  })
  
  // Reset adjustments
  modal.querySelector('#reset-adjust')?.addEventListener('click', () => {
    adjustments = { brightness: 0, contrast: 0, saturation: 0, blur: 0 }
    sliders.forEach(name => {
      const slider = modal.querySelector(`#${name}`) as HTMLInputElement
      const valueEl = modal.querySelector(`#${name}-val`)
      if (slider) slider.value = '0'
      if (valueEl) valueEl.textContent = '0'
    })
    renderImage()
  })
  
  // Filter buttons
  modal.querySelectorAll('.img-filter-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      modal.querySelectorAll('.img-filter-btn').forEach(b => b.classList.remove('active'))
      btn.classList.add('active')
      currentFilter = btn.getAttribute('data-filter') || 'none'
      renderImage()
    })
  })

  // Background removal
  modal.querySelector('#remove-bg')?.addEventListener('click', async () => {
    const progressEl = modal.querySelector('#bg-progress') as HTMLElement
    const btnEl = modal.querySelector('#remove-bg') as HTMLElement
    
    progressEl?.classList.remove('hidden')
    btnEl.style.display = 'none'
    
    try {
      const imageData = ctx.getImageData(0, 0, canvas.width, canvas.height)
      const data = imageData.data
      const width = canvas.width
      const height = canvas.height
      
      // Sample background color from edges
      const edgePoints: { x: number, y: number }[] = []
      const step = Math.max(1, Math.floor(Math.min(width, height) / 20))
      
      for (let x = 0; x < width; x += step) {
        edgePoints.push({ x, y: 0 }, { x, y: height - 1 })
      }
      for (let y = 0; y < height; y += step) {
        edgePoints.push({ x: 0, y }, { x: width - 1, y })
      }
      
      let bgR = 0, bgG = 0, bgB = 0
      edgePoints.forEach(({ x, y }) => {
        const i = (y * width + x) * 4
        bgR += data[i]; bgG += data[i + 1]; bgB += data[i + 2]
      })
      bgR = Math.round(bgR / edgePoints.length)
      bgG = Math.round(bgG / edgePoints.length)
      bgB = Math.round(bgB / edgePoints.length)
      
      const visited = new Uint8Array(width * height)
      const tolerance = 40
      const stack: { x: number, y: number }[] = []
      
      for (let x = 0; x < width; x++) {
        stack.push({ x, y: 0 }, { x, y: height - 1 })
      }
      for (let y = 0; y < height; y++) {
        stack.push({ x: 0, y }, { x: width - 1, y })
      }
      
      const isSimilar = (idx: number) => {
        const diff = Math.abs(data[idx] - bgR) + Math.abs(data[idx + 1] - bgG) + Math.abs(data[idx + 2] - bgB)
        return diff < tolerance * 3
      }
      
      while (stack.length > 0) {
        const { x, y } = stack.pop()!
        if (x < 0 || x >= width || y < 0 || y >= height) continue
        const idx = y * width + x
        if (visited[idx]) continue
        
        const pixelIdx = idx * 4
        if (!isSimilar(pixelIdx)) continue
        
        visited[idx] = 1
        data[pixelIdx + 3] = 0
        
        stack.push({ x: x + 1, y }, { x: x - 1, y }, { x, y: y + 1 }, { x, y: y - 1 })
      }
      
      // Edge smoothing
      for (let y = 1; y < height - 1; y++) {
        for (let x = 1; x < width - 1; x++) {
          const idx = (y * width + x) * 4
          if (data[idx + 3] === 0) continue
          
          let transparentCount = 0
          const neighbors = [
            ((y - 1) * width + x) * 4,
            ((y + 1) * width + x) * 4,
            (y * width + x - 1) * 4,
            (y * width + x + 1) * 4
          ]
          neighbors.forEach(nIdx => { if (data[nIdx + 3] === 0) transparentCount++ })
          
          if (transparentCount > 0 && transparentCount < 4) {
            data[idx + 3] = Math.round(255 * (1 - transparentCount / 6))
          }
        }
      }
      
      ctx.putImageData(imageData, 0, 0)
      
      const newImg = new Image()
      newImg.onload = () => { originalImage = newImg }
      newImg.src = canvas.toDataURL('image/png')
      
    } catch (error) {
      console.error('Background removal failed:', error)
    } finally {
      progressEl?.classList.add('hidden')
      btnEl.style.display = 'flex'
    }
  })

  // Close modal
  const closeModal = () => {
    modal.remove()
    styles.remove()
    onCancel()
  }
  
  modal.querySelector('.img-editor-close')?.addEventListener('click', closeModal)
  modal.querySelector('.img-cancel-btn')?.addEventListener('click', closeModal)
  modal.querySelector('.img-editor-backdrop')?.addEventListener('click', closeModal)
  
  // Save image
  modal.querySelector('.img-save-btn')?.addEventListener('click', () => {
    // Preserve the original image's MIME type so transparency is kept (PNG)
    // and the data URL matches the file extension. Falls back to PNG for
    // unknown / SVG sources where alpha may matter.
    let outMime = 'image/png'
    if (typeof image === 'object' && 'type' in image) {
      const t = (image as File).type
      if (t === 'image/jpeg') outMime = 'image/jpeg'
      else if (t === 'image/webp') outMime = 'image/webp'
      // PNG/GIF/SVG → keep PNG (preserves alpha)
    } else if (typeof image === 'string' && image.startsWith('data:')) {
      const m = /^data:image\/([a-z]+);/.exec(image)
      if (m && ['jpeg', 'jpg', 'webp'].includes(m[1])) outMime = `image/${m[1]}`
    }
    const dataUrl = canvas.toDataURL(outMime)
    modal.remove()
    styles.remove()
    onSave(dataUrl)
  })
  
  // Load the image
  loadImage(image)
}

// Setup asset manager with image editor integration
export function setupImageUpload(editor: Editor) {
  const am = editor.AssetManager

  // Custom upload handler - this is the main function that handles file uploads
  const handleUpload = (files: FileList) => {
    // Accept any image/* MIME type, but also fall back to extension-based
    // detection because some browsers (notably older Safari and some mobile
    // uploaders) report empty `f.type` for PNG/WebP files.
    const acceptedExt = /\.(png|jpe?g|gif|webp|bmp|svg)$/i
    const file = Array.from(files).find(
      (f) => f.type.startsWith('image/') || (f.name && acceptedExt.test(f.name))
    )
    if (!file) return

    // Open image editor
    createImageEditorModal(editor, {
      image: file,
      onSave: (dataUrl) => {
        // Add to asset manager. Use the data URL's MIME type (not a hard-coded
        // 'image') so downstream consumers (page renderer, asset manager
        // thumbnail, published HTML) pick the correct format.
        const mimeMatch = /^data:([^;]+);/.exec(dataUrl)
        const mime = mimeMatch ? mimeMatch[1] : (file.type || 'image/png')
        am.add({ src: dataUrl, type: mime, name: file.name })

        // If there's a selected image component, update it
        const selected = editor.getSelected()
        if (selected && selected.get('tagName')?.toUpperCase() === 'IMG') {
          selected.set('src', dataUrl)
          selected.addAttributes({ src: dataUrl })
        }

        // Close modal and refresh
        editor.Modal.close()
      },
      onCancel: () => {}
    })
  }
  
  // Add command to open image editor for existing images
  editor.Commands.add('open-image-editor', {
    run: (_ed, _sender, options: { image: string | File, target?: any }) => {
      createImageEditorModal(editor, {
        image: options.image,
        onSave: (dataUrl) => {
          am.add({ src: dataUrl, type: 'image' })
          if (options.target) {
            options.target.set('src', dataUrl)
            options.target.addAttributes({ src: dataUrl })
          }
        },
        onCancel: () => {}
      })
    }
  })
  
  // Add command to trigger file upload dialog
  editor.Commands.add('upload-image', {
    run: () => {
      const input = document.createElement('input')
      input.type = 'file'
      input.accept = 'image/*'
      input.style.display = 'none'
      document.body.appendChild(input)
      
      input.onchange = (e: Event) => {
        const target = e.target as HTMLInputElement
        if (target.files && target.files.length > 0) {
          handleUpload(target.files)
        }
        input.remove()
      }
      
      input.click()
    }
  })

  // Add command to upload background image
  editor.Commands.add('upload-bg-image', {
    run: () => {
      const input = document.createElement('input')
      input.type = 'file'
      input.accept = 'image/*'
      input.style.display = 'none'
      document.body.appendChild(input)
      
      input.onchange = (e: Event) => {
        const target = e.target as HTMLInputElement
        if (target.files && target.files.length > 0) {
          const file = target.files[0]
          
          // Open image editor for background image
          createImageEditorModal(editor, {
            image: file,
            onSave: (dataUrl) => {
              // Add to asset manager — use the data URL's MIME, not a
              // hard-coded 'image', so PNG/JPG/WebP are all handled.
              const mimeMatch = /^data:([^;]+);/.exec(dataUrl)
              const mime = mimeMatch ? mimeMatch[1] : (file.type || 'image/png')
              am.add({ src: dataUrl, type: mime, name: file.name })

              // Apply to selected component's background-image
              const selected = editor.getSelected()
              if (selected) {
                selected.addStyle({ 'background-image': `url(${dataUrl})` })
              }

              // Refresh the bg image button UI
              const container = document.querySelector('.bg-image-upload-container')
              if (container) {
                container.remove()
                setTimeout(() => {
                  // Re-trigger injection to update UI
                  editor.trigger('style:sector:update')
                }, 50)
              }
            },
            onCancel: () => {}
          })
        }
        input.remove()
      }
      
      input.click()
    }
  })
  
  // Inject custom upload button into asset manager
  const injectUploadButton = () => {
    const fileUploader = document.querySelector('.gjs-am-file-uploader')
    if (!fileUploader || fileUploader.querySelector('.custom-upload-zone')) return
    
    // Hide default content
    const defaultContent = fileUploader.querySelector('.gjs-am-file-uploader__content')
    if (defaultContent) (defaultContent as HTMLElement).style.display = 'none'
    
    // Create custom upload zone
    const uploadZone = document.createElement('div')
    uploadZone.className = 'custom-upload-zone'
    uploadZone.style.cssText = `
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      background: linear-gradient(135deg, rgba(42,157,143,0.1) 0%, rgba(42,157,143,0.05) 100%);
      border: 2px dashed rgba(42,157,143,0.4);
      border-radius: 12px;
      cursor: pointer;
      transition: all 0.2s;
      margin: 8px;
    `
    uploadZone.innerHTML = `
      <svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="0 0 24 24" style="color: #2a9d8f; margin-bottom: 12px;">
        <path fill="currentColor" d="M11 16V7.85l-2.6 2.6L7 9l5-5l5 5l-1.4 1.45l-2.6-2.6V16zm-5 4q-.825 0-1.412-.587T4 18v-3h2v3h12v-3h2v3q0 .825-.587 1.413T18 20z"/>
      </svg>
      <div style="color: #fff; font-size: 14px; font-weight: 500; margin-bottom: 4px;">Upload Image</div>
      <div style="color: #71717a; font-size: 12px;">With crop, adjust & background removal</div>
    `
    
    uploadZone.onmouseenter = () => {
      uploadZone.style.borderColor = 'rgba(42,157,143,0.7)'
      uploadZone.style.background = 'linear-gradient(135deg, rgba(42,157,143,0.15) 0%, rgba(42,157,143,0.08) 100%)'
    }
    uploadZone.onmouseleave = () => {
      uploadZone.style.borderColor = 'rgba(42,157,143,0.4)'
      uploadZone.style.background = 'linear-gradient(135deg, rgba(42,157,143,0.1) 0%, rgba(42,157,143,0.05) 100%)'
    }
    
    uploadZone.onclick = (e) => {
      e.preventDefault()
      e.stopPropagation()
      editor.runCommand('upload-image')
    }
    
    // Make parent relative
    ;(fileUploader as HTMLElement).style.position = 'relative'
    fileUploader.appendChild(uploadZone)
  }

  // Inject background image upload button into style manager
  const injectBgImageButton = () => {
    // Find the Background sector
    const bgSector = Array.from(document.querySelectorAll('.gjs-sm-sector')).find(
      sector => sector.querySelector('.gjs-sm-sector-title')?.textContent?.trim() === 'Background'
    )
    if (!bgSector || bgSector.querySelector('.bg-image-upload-container')) return
    
    const properties = bgSector.querySelector('.gjs-sm-properties')
    if (!properties) return
    
    // Create upload button container
    const container = document.createElement('div')
    container.className = 'bg-image-upload-container gjs-sm-property'
    container.style.cssText = 'margin-bottom: 12px;'
    
    // Get current background-image value
    const selected = editor.getSelected()
    const currentBgRaw = selected?.getStyle()?.['background-image']
    const currentBg = typeof currentBgRaw === 'string' ? currentBgRaw : ''
    const hasImage = currentBg && currentBg !== 'none' && currentBg.includes('url(')
    
    container.innerHTML = `
      <div class="gjs-sm-label" style="color: #a1a1aa; font-size: 11px; margin-bottom: 6px;">Background Image</div>
      <div style="display: flex; gap: 6px;">
        <button class="bg-upload-btn" style="
          flex: 1;
          padding: 10px 12px;
          background: ${hasImage ? 'rgba(42,157,143,0.2)' : 'rgba(255,255,255,0.05)'};
          border: 1px solid ${hasImage ? '#2a9d8f' : 'rgba(255,255,255,0.1)'};
          border-radius: 6px;
          color: ${hasImage ? '#3db8a9' : '#a1a1aa'};
          font-size: 12px;
          cursor: pointer;
          display: flex;
          align-items: center;
          justify-content: center;
          gap: 8px;
          transition: all 0.15s;
        ">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" style="flex-shrink: 0;">
            <path fill="currentColor" d="M11 16V7.85l-2.6 2.6L7 9l5-5l5 5l-1.4 1.45l-2.6-2.6V16zm-5 4q-.825 0-1.412-.587T4 18v-3h2v3h12v-3h2v3q0 .825-.587 1.413T18 20z"/>
          </svg>
          <span>${hasImage ? 'Change Image' : 'Upload Image'}</span>
        </button>
        ${hasImage ? `
          <button class="bg-remove-btn" style="
            padding: 10px 12px;
            background: rgba(239,68,68,0.1);
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: 6px;
            color: #f87171;
            font-size: 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.15s;
          " title="Remove Image">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24">
              <path fill="currentColor" d="M7 21q-.825 0-1.412-.587T5 19V6H4V4h5V3h6v1h5v2h-1v13q0 .825-.587 1.413T17 21zM17 6H7v13h10zM9 17h2V8H9zm4 0h2V8h-2zM7 6v13z"/>
            </svg>
          </button>
        ` : ''}
      </div>
      ${hasImage ? `
        <div style="margin-top: 8px; padding: 8px; background: rgba(255,255,255,0.03); border-radius: 6px; border: 1px solid rgba(255,255,255,0.06);">
          <div style="width: 100%; height: 60px; background-image: ${currentBg}; background-size: cover; background-position: center; border-radius: 4px;"></div>
        </div>
      ` : ''}
    `
    
    // Insert at the beginning of properties
    properties.insertBefore(container, properties.firstChild)
    
    // Add event listeners
    const uploadBtn = container.querySelector('.bg-upload-btn')
    const removeBtn = container.querySelector('.bg-remove-btn')
    
    uploadBtn?.addEventListener('click', (e) => {
      e.preventDefault()
      e.stopPropagation()
      editor.runCommand('upload-bg-image')
    })
    
    uploadBtn?.addEventListener('mouseenter', () => {
      (uploadBtn as HTMLElement).style.background = 'rgba(42,157,143,0.3)'
      ;(uploadBtn as HTMLElement).style.borderColor = '#2a9d8f'
      ;(uploadBtn as HTMLElement).style.color = '#3db8a9'
    })
    
    uploadBtn?.addEventListener('mouseleave', () => {
      const sel = editor.getSelected()
      const bgRaw = sel?.getStyle()?.['background-image']
      const bg = typeof bgRaw === 'string' ? bgRaw : ''
      const hasImg = bg && bg !== 'none' && bg.includes('url(')
      ;(uploadBtn as HTMLElement).style.background = hasImg ? 'rgba(42,157,143,0.2)' : 'rgba(255,255,255,0.05)'
      ;(uploadBtn as HTMLElement).style.borderColor = hasImg ? '#2a9d8f' : 'rgba(255,255,255,0.1)'
      ;(uploadBtn as HTMLElement).style.color = hasImg ? '#3db8a9' : '#a1a1aa'
    })
    
    removeBtn?.addEventListener('click', (e) => {
      e.preventDefault()
      e.stopPropagation()
      const sel = editor.getSelected()
      if (sel) {
        sel.addStyle({ 'background-image': 'none' })
        // Re-inject to update UI
        container.remove()
        setTimeout(injectBgImageButton, 50)
      }
    })
    
    removeBtn?.addEventListener('mouseenter', () => {
      (removeBtn as HTMLElement).style.background = 'rgba(239,68,68,0.2)'
      ;(removeBtn as HTMLElement).style.borderColor = 'rgba(239,68,68,0.5)'
    })
    
    removeBtn?.addEventListener('mouseleave', () => {
      (removeBtn as HTMLElement).style.background = 'rgba(239,68,68,0.1)'
      ;(removeBtn as HTMLElement).style.borderColor = 'rgba(239,68,68,0.3)'
    })
  }
  
  // Listen for asset manager open
  editor.on('run:open-assets', () => {
    setTimeout(injectUploadButton, 50)
    setTimeout(injectUploadButton, 200)
  })
  
  editor.on('modal:open', () => {
    setTimeout(injectUploadButton, 100)
  })

  // Listen for style manager render to inject bg image button
  editor.on('style:sector:update', () => {
    setTimeout(injectBgImageButton, 50)
  })

  // Also inject when component is selected
  editor.on('component:selected', () => {
    setTimeout(injectBgImageButton, 100)
  })

  // Initial injection after load
  editor.on('load', () => {
    setTimeout(injectBgImageButton, 500)
  })
  
  return handleUpload
}
