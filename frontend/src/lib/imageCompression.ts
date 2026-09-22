import imageCompression from 'browser-image-compression'

/**
 * Options for compressing an image before upload.
 *
 * Defaults are tuned for the WebsiteBuilder save flow: a 1920px max edge
 * preserves good visual quality on retina displays while keeping base64
 * payload sizes under ~500 KB even for dense photos.
 */
export interface ImageCompressionOptions {
  /** Max width/height in pixels. Defaults to 1920. */
  maxWidthOrHeight?: number
  /** JPEG quality (0–1). Defaults to 0.85. */
  quality?: number
  /** Convert PNG to JPEG/WEBP for large images. Defaults to true. */
  convertToWebp?: boolean
  /** When converting, use this MIME type. Defaults to 'image/webp'. */
  targetMimeType?: 'image/webp' | 'image/jpeg' | 'image/png'
  /** Skip compression if original file is smaller than this (bytes). Defaults to 300_000. */
  skipIfSmallerThan?: number
  /** Always re-encode, even if file is already below the skip threshold. Defaults to false. */
  alwaysRecompress?: boolean
}

const DEFAULT_OPTIONS: Required<ImageCompressionOptions> = {
  maxWidthOrHeight: 1920,
  quality: 0.85,
  convertToWebp: true,
  targetMimeType: 'image/webp',
  skipIfSmallerThan: 300_000,
  alwaysRecompress: false,
}

/**
 * Compress an image File before upload. Returns the original file unchanged
 * if compression fails, so the save flow never breaks because of an image
 * problem.
 */
export async function compressImage(
  file: File,
  options: ImageCompressionOptions = {},
): Promise<File> {
  if (!isCompressible(file)) return file

  const opts = { ...DEFAULT_OPTIONS, ...options }

  if (
    !opts.alwaysRecompress &&
    file.size <= opts.skipIfSmallerThan &&
    !shouldRecompressByType(file.type)
  ) {
    return file
  }

  try {
    const compressed = await imageCompression(file, {
      maxWidthOrHeight: opts.maxWidthOrHeight,
      initialQuality: opts.quality,
      useWebWorker: true,
      fileType: opts.convertToWebp ? opts.targetMimeType : undefined,
      alwaysKeepResolution: false,
    })

    if (compressed.size >= file.size && !opts.alwaysRecompress) {
      return file
    }

    const newName = renameToExtension(file.name, compressed)
    return new File([compressed], newName, {
      type: compressed.type || file.type,
      lastModified: Date.now(),
    })
  } catch (err) {
    // Never break the save flow — return the original file.
    console.warn('[imageCompression] compression failed; using original', err)
    return file
  }
}

/**
 * Convert a base64 data URL into a compressed File. Useful when GrapesJS
 * stores images inline as `data:image/png;base64,…` and the page save needs
 * to compact them before persisting the page content.
 */
export async function compressBase64DataUrl(
  dataUrl: string,
  baseName = 'image',
  options: ImageCompressionOptions = {},
): Promise<File | null> {
  const file = await dataUrlToFile(dataUrl, baseName)
  if (!file) return null
  return compressImage(file, options)
}

/**
 * Walk a GrapesJS component tree and compress every inline image asset.
 * Returns a new tree; the original is not mutated. Only handles the
 * `image` component type that ships with GrapesJS core.
 */
export async function compressGrapesJsComponents(
  components: any,
  options: ImageCompressionOptions = {},
): Promise<{ components: any; compressedCount: number; bytesBefore: number; bytesAfter: number }> {
  let compressedCount = 0
  let bytesBefore = 0
  let bytesAfter = 0

  // GrapesJS returns a Components Collection, not an array. Walk the plain
  // JSON definition instead: spreading a Collection copies circular internals
  // (model/parent back-references) and makes the save payload unserialisable.
  const source = components && typeof components.toJSON === 'function'
    ? components.toJSON()
    : components

  const walk = async (node: any): Promise<any> => {
    // The root is an array of component definitions; nested component lists are
    // arrays too. Without this branch the walk returned [] for any array input,
    // which saved an EMPTY components tree and blanked the editor canvas.
    if (Array.isArray(node)) {
      return Promise.all(node.map(walk))
    }
    if (!node || typeof node !== 'object') return node

    // Clone shallowly to avoid mutating the original tree.
    const next: any = Array.isArray(node) ? [] : { ...node }

    if (node.type === 'image' && typeof node.attributes?.src === 'string') {
      const src: string = node.attributes.src
      if (src.startsWith('data:')) {
        bytesBefore += src.length
        const compressed = await compressBase64DataUrl(src, `gjs-image-${node.id ?? Date.now()}`, options)
        if (compressed) {
          const reader = new FileReader()
          const newDataUrl = await new Promise<string>((resolve, reject) => {
            reader.onerror = () => reject(reader.error)
            reader.onload = () => resolve(String(reader.result))
            reader.readAsDataURL(compressed)
          })
          bytesAfter += newDataUrl.length
          if (newDataUrl.length < src.length) {
            next.attributes = { ...node.attributes, src: newDataUrl }
            compressedCount++
          } else {
            bytesAfter += src.length
            next.attributes = node.attributes
          }
        } else {
          bytesAfter += src.length
          next.attributes = node.attributes
        }
      } else {
        next.attributes = node.attributes
      }
    } else if (node.attributes) {
      next.attributes = node.attributes
    }

    if (Array.isArray(node.components)) {
      next.components = await Promise.all(node.components.map(walk))
    }
    if (Array.isArray(node.children)) {
      next.children = await Promise.all(node.children.map(walk))
    }

    return next
  }

  const newTree = await walk(source)
  return { components: newTree, compressedCount, bytesBefore, bytesAfter }
}

/**
 * Compress every inline data URL in an HTML string. Useful for cleaning up
 * `editorInstance.getHtml()` before persisting.
 */
export async function compressInlineImagesInHtml(
  html: string,
  options: ImageCompressionOptions = {},
): Promise<{ html: string; compressedCount: number; bytesBefore: number; bytesAfter: number }> {
  if (!html || typeof html !== 'string') {
    return { html, compressedCount: 0, bytesBefore: 0, bytesAfter: 0 }
  }

  const dataUrlRegex = /src="(data:image\/[^;]+;base64,[^"]+)"/g
  const matches = Array.from(html.matchAll(dataUrlRegex))
  if (matches.length === 0) {
    return { html, compressedCount: 0, bytesBefore: 0, bytesAfter: 0 }
  }

  let compressedCount = 0
  let bytesBefore = 0
  let bytesAfter = 0
  let resultHtml = html

  for (const match of matches) {
    const dataUrl = match[1]
    bytesBefore += dataUrl.length
    const compressed = await compressBase64DataUrl(dataUrl, 'inline-image', options)
    if (!compressed) {
      bytesAfter += dataUrl.length
      continue
    }
    const newDataUrl = await fileToDataUrl(compressed)
    if (newDataUrl.length < dataUrl.length) {
      bytesAfter += newDataUrl.length
      resultHtml = resultHtml.replace(dataUrl, newDataUrl)
      compressedCount++
    } else {
      bytesAfter += dataUrl.length
    }
  }

  return { html: resultHtml, compressedCount, bytesBefore, bytesAfter }
}

function isCompressible(file: File): boolean {
  return /^image\/(png|jpe?g|webp|gif|bmp)$/i.test(file.type)
}

function shouldRecompressByType(type: string): boolean {
  // PNGs compress better when re-encoded to WebP/JPEG, so always try.
  return /^image\/png$/i.test(type)
}

function renameToExtension(originalName: string, compressed: Blob): string {
  const targetType = compressed.type || ''
  const ext = targetType.includes('webp')
    ? 'webp'
    : targetType.includes('jpeg')
    ? 'jpg'
    : targetType.includes('png')
    ? 'png'
    : 'img'
  const stem = originalName.replace(/\.[^.]+$/, '') || 'image'
  return `${stem}.${ext}`
}

async function dataUrlToFile(dataUrl: string, baseName: string): Promise<File | null> {
  try {
    const res = await fetch(dataUrl)
    const blob = await res.blob()
    const ext = blob.type.includes('png') ? 'png' : blob.type.includes('webp') ? 'webp' : 'jpg'
    return new File([blob], `${baseName}.${ext}`, {
      type: blob.type,
      lastModified: Date.now(),
    })
  } catch {
    return null
  }
}

async function fileToDataUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error)
    reader.onload = () => resolve(String(reader.result))
    reader.readAsDataURL(file)
  })
}
