/**
 * Print rendered PDF bytes through the browser's embedded PDF viewer.
 *
 * Document pages used to call window.print(), which printed the whole SPA
 * shell. Printing the bytes returned by the Template Builder render endpoint
 * keeps the output identical to "Download PDF" (same selected template).
 */
export function printPdfBlob(blob: Blob): void {
  const url = URL.createObjectURL(blob)
  const iframe = document.createElement('iframe')
  iframe.style.position = 'fixed'
  iframe.style.right = '0'
  iframe.style.bottom = '0'
  iframe.style.width = '0'
  iframe.style.height = '0'
  iframe.style.border = '0'
  iframe.style.visibility = 'hidden'
  iframe.src = url
  iframe.onload = () => {
    const frameWindow = iframe.contentWindow
    if (!frameWindow) return
    // Give the embedded PDF viewer a moment to initialise before printing.
    window.setTimeout(() => {
      frameWindow.focus()
      frameWindow.print()
      window.setTimeout(() => {
        iframe.remove()
        URL.revokeObjectURL(url)
      }, 60_000)
    }, 500)
  }
  document.body.appendChild(iframe)
}
