// Shrink a photo in the browser before upload: a smaller image uploads
// faster and the CPU-only model reads it much sooner.

export const ACCEPTED_IMAGES = 'image/jpeg,image/png,image/webp'

export async function resizeImage(file, maxSide = 768, quality = 0.85) {
  const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
  const scale = Math.min(1, maxSide / Math.max(bitmap.width, bitmap.height))
  const width = Math.round(bitmap.width * scale)
  const height = Math.round(bitmap.height * scale)

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  const ctx = canvas.getContext('2d')
  // JPEG has no transparency; give transparent PNGs a white background.
  ctx.fillStyle = '#fff'
  ctx.fillRect(0, 0, width, height)
  ctx.drawImage(bitmap, 0, 0, width, height)
  bitmap.close()

  const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality))
  if (!blob) throw new Error('Could not read the image.')
  return blob
}
