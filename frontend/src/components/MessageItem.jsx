import { memo } from 'react'
import { messageImageUrl } from '../api.js'
import { useI18n } from '../i18n.js'
import { CheckIcon, CopyIcon, Logo } from './Icons.jsx'
import { useCopy } from '../useCopy.js'
import Markdown from './Markdown.jsx'

function MessageItem({ message }) {
  const { t } = useI18n()
  const [copied, copy] = useCopy()

  if (message.role === 'user') {
    const imageSrc = message.image_url || (message.has_image ? messageImageUrl(message.id) : null)
    return (
      <div className="flex flex-col items-end gap-1.5">
        {imageSrc && (
          <a href={imageSrc} target="_blank" rel="noreferrer">
            <img src={imageSrc} alt="" loading="lazy" className="max-h-64 max-w-[min(85%,20rem)] rounded-2xl border border-stone-200 object-contain dark:border-stone-800" />
          </a>
        )}
        {message.content && (
          <div dir="auto" className="max-w-[85%] whitespace-pre-wrap break-words rounded-2xl bg-teal-700 px-4 py-2.5 text-white">
            {message.content}
          </div>
        )}
      </div>
    )
  }

  const streaming = message.status === 'streaming'
  const meta = [
    message.duration_ms && `${(message.duration_ms / 1000).toFixed(1)}${t.seconds}`,
    message.completion_tokens && `${message.completion_tokens} ${t.tokens}`,
  ].filter(Boolean)

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

        {message.status === 'error' && (
          <p className="mt-2 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">{message.error || t.errorGeneric}</p>
        )}

        {!streaming && (message.content || message.status === 'stopped') && (
          <div className="mt-1.5 flex items-center gap-3 text-xs text-stone-500">
            {message.content && (
              <button onClick={() => copy(message.content)} className="flex items-center gap-1 rounded px-1.5 py-1 hover:bg-stone-100 dark:hover:bg-stone-800">
                {copied ? <CheckIcon /> : <CopyIcon />}
                {copied ? t.copied : t.copy}
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
