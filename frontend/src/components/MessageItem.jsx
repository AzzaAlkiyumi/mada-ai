import { memo, useState } from 'react'
import { messageImageUrl } from '../api.js'
import { useI18n } from '../i18n.js'
import { CheckIcon, CopyIcon, EditIcon, FileIcon, Logo, RetryIcon } from './Icons.jsx'
import { useCopy } from '../useCopy.js'
import Markdown from './Markdown.jsx'

const actionClass = 'flex items-center gap-1 rounded px-1.5 py-1 hover:bg-stone-100 disabled:opacity-40 dark:hover:bg-stone-800'

function UserMessage({ message, busy, onEdit }) {
  const { t } = useI18n()
  const [draft, setDraft] = useState(null) // null = not editing
  const imageSrc = message.image_url || (message.has_image ? messageImageUrl(message.id) : null)
  const hasAttachment = !!(imageSrc || message.file_name)

  const save = () => {
    const content = draft.trim()
    if (!content && !hasAttachment) return
    setDraft(null)
    onEdit(message, content)
  }

  return (
    <div className="group flex flex-col items-end gap-1.5">
      {imageSrc && (
        <a href={imageSrc} target="_blank" rel="noreferrer">
          <img src={imageSrc} alt="" loading="lazy" className="max-h-64 max-w-[min(85%,20rem)] rounded-2xl border border-stone-200 object-contain dark:border-stone-800" />
        </a>
      )}
      {message.file_name && (
        <div className="flex max-w-[85%] items-center gap-2 rounded-xl border border-stone-200 bg-stone-50 px-3 py-2 text-sm dark:border-stone-700 dark:bg-stone-800">
          <FileIcon />
          <span className="truncate" dir="auto">
            {message.file_name}
          </span>
        </div>
      )}

      {draft !== null ? (
        <div className="w-full max-w-[85%] rounded-2xl border border-teal-600 bg-white p-2 dark:bg-stone-900">
          <textarea
            autoFocus
            dir="auto"
            rows={3}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            onKeyDown={(e) => e.key === 'Escape' && setDraft(null)}
            className="w-full resize-y bg-transparent px-2 py-1 outline-none"
          />
          <div className="flex items-center gap-2 px-1 pt-1">
            <span className="flex-1 text-xs text-stone-500">{t.editHint}</span>
            <button onClick={() => setDraft(null)} className="rounded-lg px-3 py-1.5 text-sm hover:bg-stone-100 dark:hover:bg-stone-800">
              {t.cancel}
            </button>
            <button onClick={save} disabled={!draft.trim() && !hasAttachment} className="rounded-lg bg-teal-700 px-3 py-1.5 text-sm text-white hover:bg-teal-800 disabled:opacity-40">
              {t.saveAndSend}
            </button>
          </div>
        </div>
      ) : (
        <>
          {message.content && (
            <div dir="auto" className="max-w-[85%] whitespace-pre-wrap break-words rounded-2xl bg-teal-700 px-4 py-2.5 text-white">
              {message.content}
            </div>
          )}
          {!busy && (
            <button
              onClick={() => setDraft(message.content)}
              className={`${actionClass} text-xs text-stone-500 md:opacity-0 md:group-hover:opacity-100 md:focus:opacity-100`}
            >
              <EditIcon />
              {t.edit}
            </button>
          )}
        </>
      )}
    </div>
  )
}

function MessageItem({ message, busy, canRegenerate, onEdit, onRegenerate }) {
  const { t } = useI18n()
  const [copied, copy] = useCopy()

  if (message.role === 'user') {
    return <UserMessage message={message} busy={busy} onEdit={onEdit} />
  }

  const streaming = message.status === 'streaming'
  const failed = message.status === 'error'
  const meta = [
    message.duration_ms && `${(message.duration_ms / 1000).toFixed(1)}${t.seconds}`,
    message.completion_tokens && `${message.completion_tokens} ${t.tokens}`,
  ].filter(Boolean)
  const showRegenerate = canRegenerate && !busy

  return (
    <div className="flex gap-3">
      <div className="mt-1 shrink-0">
        <Logo size={26} />
      </div>
      <div className="min-w-0 flex-1" dir="auto">
        {message.content ? (
          <div className={streaming ? 'typing-cursor' : ''}>
            <Markdown text={message.content} />
          </div>
        ) : streaming ? (
          <p className="animate-pulse text-stone-500">{message.readingImage ? t.imageReading : t.thinking}</p>
        ) : null}

        {failed && (
          <p className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">{message.error || t.errorGeneric}</p>
        )}

        {!streaming && (message.content || message.status === 'stopped' || showRegenerate) && (
          <div className="mt-1.5 flex flex-wrap items-center gap-3 text-xs text-stone-500">
            {message.content && (
              <button onClick={() => copy(message.content)} className={actionClass}>
                {copied ? <CheckIcon /> : <CopyIcon />}
                {copied ? t.copied : t.copy}
              </button>
            )}
            {showRegenerate && (
              <button onClick={() => onRegenerate(message)} className={actionClass}>
                <RetryIcon />
                {t.regenerate}
              </button>
            )}
            {message.status === 'stopped' && <span>{t.stopped}</span>}
            {meta.map((m) => (
              <span key={m}>{m}</span>
            ))}
          </div>
        )}
      </div>
    </div>
  )
}

export default memo(MessageItem)
