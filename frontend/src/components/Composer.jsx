import { useEffect, useRef, useState } from 'react'
import { useI18n } from '../i18n.js'
import { ACCEPTED_IMAGES, resizeImage } from '../resizeImage.js'
import { AttachIcon, CloseIcon, SendIcon, StopIcon } from './Icons.jsx'

export default function Composer({ streaming, onSend, onStop, maxLength = 16000 }) {
  const { t } = useI18n()
  const [text, setText] = useState('')
  const [image, setImage] = useState(null) // { blob, url }
  const [imageError, setImageError] = useState('')
  const ref = useRef(null)
  const fileRef = useRef(null)

  // Grow with the content, up to a limit.
  useEffect(() => {
    const el = ref.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 240)}px`
  }, [text])

  const pickImage = async (e) => {
    const file = e.target.files?.[0]
    e.target.value = ''
    if (!file) return
    setImageError('')
    try {
      const blob = await resizeImage(file)
      setImage({ blob, url: URL.createObjectURL(blob) })
    } catch {
      setImageError(t.errorImage)
    }
  }

  const removeImage = () => {
    if (image) URL.revokeObjectURL(image.url)
    setImage(null)
  }

  const submit = () => {
    const value = text.trim()
    if ((!value && !image) || streaming) return
    // The preview URL now belongs to the sent message, so it is not revoked here.
    onSend(value, image)
    setText('')
    setImage(null)
    setImageError('')
  }

  const onKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault()
      submit()
    }
  }

  return (
    <div className="border-t border-stone-200 bg-white/90 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur dark:border-stone-800 dark:bg-stone-950/90">
      <div className="mx-auto max-w-3xl rounded-2xl border border-stone-300 bg-white p-2 focus-within:border-teal-600 dark:border-stone-700 dark:bg-stone-900">
        {image && (
          <div className="relative mb-2 ms-1 inline-block">
            <img src={image.url} alt="" className="h-20 w-20 rounded-lg object-cover" />
            <button
              onClick={removeImage}
              aria-label={t.removeImage}
              title={t.removeImage}
              className="absolute -end-2 -top-2 rounded-full bg-stone-800 p-1 text-white hover:bg-stone-700 dark:bg-stone-200 dark:text-stone-900"
            >
              <CloseIcon />
            </button>
          </div>
        )}
        {imageError && <p className="mb-2 px-2 text-sm text-red-600 dark:text-red-400">{imageError}</p>}

        <div className="flex items-end gap-2">
          <input ref={fileRef} type="file" accept={ACCEPTED_IMAGES} onChange={pickImage} className="hidden" />
          <button
            onClick={() => fileRef.current?.click()}
            disabled={streaming}
            aria-label={t.attachImage}
            title={t.attachImage}
            className="rounded-xl p-2.5 text-stone-500 hover:bg-stone-100 hover:text-stone-800 disabled:opacity-40 dark:hover:bg-stone-800 dark:hover:text-stone-200"
          >
            <AttachIcon />
          </button>
          <textarea
            ref={ref}
            dir="auto"
            rows={1}
            value={text}
            maxLength={maxLength}
            onChange={(e) => setText(e.target.value)}
            onKeyDown={onKeyDown}
            placeholder={t.placeholder}
            className="max-h-60 min-h-[2.5rem] flex-1 resize-none bg-transparent px-2 py-2 outline-none placeholder:text-stone-400"
          />
          {streaming ? (
            <button onClick={onStop} aria-label={t.stop} title={t.stop} className="rounded-xl bg-stone-800 p-2.5 text-white hover:bg-stone-700 dark:bg-stone-200 dark:text-stone-900">
              <StopIcon />
            </button>
          ) : (
            <button onClick={submit} disabled={!text.trim() && !image} aria-label={t.send} title={t.send} className="rounded-xl bg-teal-700 p-2.5 text-white hover:bg-teal-800 disabled:opacity-40">
              <SendIcon />
            </button>
          )}
        </div>
      </div>
    </div>
  )
}
