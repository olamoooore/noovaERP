/**
 * Shared helpers for the document render endpoints (Template Builder PDF/HTML).
 */

/**
 * Render errors are returned as a JSON blob because the PDF endpoints use
 * `responseType: 'blob'`. Extract the server message so a toast never shows
 * "[object Blob]".
 */
export async function extractBlobError(error: any): Promise<string | null> {
  const data = error?.response?.data
  if (data instanceof Blob) {
    try {
      const parsed = JSON.parse(await data.text())
      return parsed?.message ?? null
    } catch {
      return null
    }
  }
  return data?.message ?? null
}
