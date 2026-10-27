import { useEffect, useRef, useState } from 'react'
import { useI18n } from '../i18n.js'
import { SendIcon, StopIcon } from './Icons.jsx'

export default function Composer({ streaming, onSend, onStop, maxLength = 16000 }) {
  const { t } = useI18n()
  const [text, setText] = useState('')
  const ref = useRef(null)

  // Grow with the content, up to a limit.
  useEffect(() => {
    const el = ref.current
    if (!el) return
    el.style.height = 'auto'
    el.style.height = `${Math.min(el.scrollHeight, 240)}px`
  }, [text])

  const submit = () => {
    const value = text.trim()
    if (!value || streaming) return
    onSend(value)
    setText('')
  }

  const onKeyDown = (e) => {
    if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
      e.preventDefault()
      submit()
    }
  }

  return (
    <div className="border-t border-stone-200 bg-white/90 px-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] pt-3 backdrop-blur dark:border-stone-800 dark:bg-stone-950/90">
      <div className="mx-auto flex max-w-3xl items-end gap-2 rounded-2xl border border-stone-300 bg-white p-2 focus-within:border-teal-600 dark:border-stone-700 dark:bg-stone-900">
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
          <button onClick={submit} disabled={!text.trim()} aria-label={t.send} title={t.send} className="rounded-xl bg-teal-700 p-2.5 text-white hover:bg-teal-800 disabled:opacity-40">
            <SendIcon />
          </button>
        )}
      </div>
    </div>
  )
}
