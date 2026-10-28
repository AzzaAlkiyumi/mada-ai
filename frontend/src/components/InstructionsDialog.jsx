import { useEffect, useState } from 'react'
import { useI18n } from '../i18n.js'

const MAX_LENGTH = 2000

export default function InstructionsDialog({ initial, onSave, onClose }) {
  const { t } = useI18n()
  const [text, setText] = useState(initial)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState('')

  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose()
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [onClose])

  const save = async () => {
    setSaving(true)
    setError('')
    try {
      await onSave(text)
    } catch (err) {
      setError(err.errors?.instructions?.[0] || err.message || t.errorGeneric)
      setSaving(false)
    }
  }

  return (
    <div className="fixed inset-0 z-40 flex items-center justify-center bg-black/40 p-4" onClick={onClose}>
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="instructions-title"
        onClick={(e) => e.stopPropagation()}
        className="w-full max-w-lg rounded-2xl bg-white p-5 shadow-xl dark:bg-stone-900"
      >
        <h2 id="instructions-title" className="text-lg font-semibold">
          {t.instructions}
        </h2>
        <p className="mt-1 text-sm text-stone-500 dark:text-stone-400">{t.instructionsHint}</p>
        <textarea
          autoFocus
          dir="auto"
          rows={7}
          value={text}
          maxLength={MAX_LENGTH}
          onChange={(e) => setText(e.target.value)}
          placeholder={t.instructionsExample}
          className="mt-3 w-full resize-y rounded-xl border border-stone-300 bg-transparent p-3 outline-none placeholder:text-stone-400 focus:border-teal-600 dark:border-stone-700"
        />
        <div className="mt-1 text-end text-xs text-stone-500" dir="ltr">
          {text.length} / {MAX_LENGTH}
        </div>
        {error && <p className="mt-2 text-sm text-red-600 dark:text-red-400">{error}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <button onClick={onClose} className="rounded-lg px-4 py-2 text-sm hover:bg-stone-100 dark:hover:bg-stone-800">
            {t.cancel}
          </button>
          <button onClick={save} disabled={saving} className="rounded-lg bg-teal-700 px-4 py-2 text-sm font-medium text-white hover:bg-teal-800 disabled:opacity-50">
            {saving ? t.saving : t.save}
          </button>
        </div>
      </div>
    </div>
  )
}
