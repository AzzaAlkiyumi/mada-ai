import { useCallback, useEffect, useRef, useState } from 'react'
import { api, streamChat } from '../api.js'
import { useI18n } from '../i18n.js'
import Composer from './Composer.jsx'
import { Logo, MenuIcon } from './Icons.jsx'
import MessageItem from './MessageItem.jsx'
import Sidebar from './Sidebar.jsx'

let tempId = 0
const nextTempId = () => `tmp-${++tempId}`

export default function ChatApp({ user, onSignedOut, settings }) {
  const { t } = useI18n()
  const [conversations, setConversations] = useState([])
  const [activeId, setActiveId] = useState(null)
  const [messages, setMessages] = useState([])
  const [loadingMessages, setLoadingMessages] = useState(false)
  const [streaming, setStreaming] = useState(false)
  const [notice, setNotice] = useState('')
  const [modelOnline, setModelOnline] = useState(null)
  const [sidebarOpen, setSidebarOpen] = useState(false)

  const abortRef = useRef(null)
  const scrollRef = useRef(null)
  const stickToBottom = useRef(true)

  const handleError = useCallback(
    (err) => {
      if (err.status === 401 || err.status === 419) {
        onSignedOut()
        return t.errorSession
      }
      if (err.status === 429) return t.errorRate
      if (err.status === 422) return err.errors?.content?.[0] || t.errorTooLong
      return err.message || t.errorGeneric
    },
    [onSignedOut, t],
  )

  const refreshConversations = useCallback(() => {
    api
      .conversations()
      .then((data) => setConversations(data.conversations))
      .catch((err) => setNotice(handleError(err)))
  }, [handleError])

  useEffect(() => {
    refreshConversations()
  }, [refreshConversations])

  useEffect(() => {
    const check = () =>
      api
        .modelStatus()
        .then((data) => setModelOnline(data.online))
        .catch(() => setModelOnline(false))
    check()
    const timer = setInterval(check, 60_000)
    return () => clearInterval(timer)
  }, [])

  // Follow the reply as it streams, unless the user scrolled up to read.
  useEffect(() => {
    const el = scrollRef.current
    if (el && stickToBottom.current) el.scrollTop = el.scrollHeight
  }, [messages])

  const onScroll = () => {
    const el = scrollRef.current
    stickToBottom.current = el.scrollHeight - el.scrollTop - el.clientHeight < 80
  }

  const stop = () => abortRef.current?.abort()

  const newChat = () => {
    stop()
    setActiveId(null)
    setMessages([])
    setNotice('')
    setSidebarOpen(false)
  }

  const openConversation = async (id) => {
    stop()
    setSidebarOpen(false)
    setActiveId(id)
    setNotice('')
    setLoadingMessages(true)
    try {
      const data = await api.conversation(id)
      setMessages(data.messages)
      stickToBottom.current = true
    } catch (err) {
      setNotice(handleError(err))
    } finally {
      setLoadingMessages(false)
    }
  }

  const renameConversation = async (c) => {
    const title = window.prompt(t.rename, c.title)?.trim()
    if (!title || title === c.title) return
    try {
      const data = await api.renameConversation(c.id, title.slice(0, 120))
      setConversations((list) => list.map((x) => (x.id === c.id ? data.conversation : x)))
    } catch (err) {
      setNotice(handleError(err))
    }
  }

  const deleteConversation = async (c) => {
    if (!window.confirm(t.confirmDelete)) return
    try {
      await api.deleteConversation(c.id)
      setConversations((list) => list.filter((x) => x.id !== c.id))
      if (c.id === activeId) newChat()
    } catch (err) {
      setNotice(handleError(err))
    }
  }

  const signOut = async () => {
    stop()
    try {
      await api.logout()
    } finally {
      onSignedOut()
    }
  }

  const send = async (content) => {
    setNotice('')
    stickToBottom.current = true

    const assistantKey = nextTempId()
    const updateAssistant = (fn) => setMessages((list) => list.map((m) => (m.key === assistantKey ? { ...m, ...fn(m) } : m)))

    setMessages((list) => [
      ...list,
      { id: nextTempId(), role: 'user', content },
      { id: assistantKey, key: assistantKey, role: 'assistant', content: '', status: 'streaming' },
    ])

    const controller = new AbortController()
    abortRef.current = controller
    setStreaming(true)

    // Batch text chunks into one render per animation frame.
    let pending = ''
    let frame = 0
    const flush = () => {
      frame = 0
      if (!pending) return
      const chunk = pending
      pending = ''
      updateAssistant((m) => ({ content: m.content + chunk }))
    }

    try {
      await streamChat({
        conversationId: activeId,
        content,
        signal: controller.signal,
        onMeta: ({ conversation, assistant_message_id }) => {
          setActiveId(conversation.id)
          setConversations((list) => [conversation, ...list.filter((c) => c.id !== conversation.id)])
          updateAssistant(() => ({ id: assistant_message_id }))
        },
        onDelta: (text) => {
          pending += text
          frame ||= requestAnimationFrame(flush)
        },
        onDone: (message) => {
          cancelAnimationFrame(frame)
          updateAssistant(() => ({ ...message, key: undefined }))
        },
        onError: (message) => {
          cancelAnimationFrame(frame)
          flush()
          updateAssistant(() => ({ status: 'error', error: message }))
        },
      })
    } catch (err) {
      cancelAnimationFrame(frame)
      flush()
      if (err.name === 'AbortError') {
        updateAssistant(() => ({ status: 'stopped' }))
      } else {
        updateAssistant(() => ({ status: 'error', error: handleError(err) }))
      }
    } finally {
      // A stream that ended without "done" or "error" (e.g. dropped connection).
      setMessages((list) => list.map((m) => (m.key === assistantKey && m.status === 'streaming' ? { ...m, status: 'stopped' } : m)))
      setStreaming(false)
      abortRef.current = null
      refreshConversations()
    }
  }

  const activeTitle = conversations.find((c) => c.id === activeId)?.title

  return (
    <div className="flex h-full overflow-hidden">
      <Sidebar
        open={sidebarOpen}
        onClose={() => setSidebarOpen(false)}
        user={user}
        conversations={conversations}
        activeId={activeId}
        modelOnline={modelOnline}
        settings={settings}
        onNew={newChat}
        onSelect={openConversation}
        onRename={renameConversation}
        onDelete={deleteConversation}
        onSignOut={signOut}
      />

      <main className="flex min-w-0 flex-1 flex-col">
        <header className="flex items-center gap-2 border-b border-stone-200 px-3 py-2.5 dark:border-stone-800 md:hidden">
          <button onClick={() => setSidebarOpen(true)} aria-label={t.menu} className="rounded-lg p-2 hover:bg-stone-100 dark:hover:bg-stone-800">
            <MenuIcon />
          </button>
          <span dir="auto" className="truncate font-medium">
            {activeTitle || t.appName}
          </span>
        </header>

        <div ref={scrollRef} onScroll={onScroll} className="flex-1 overflow-y-auto">
          <div className="mx-auto max-w-3xl space-y-6 px-4 py-6">
            {loadingMessages && <p className="text-center text-stone-500">{t.loading}</p>}

            {!loadingMessages && messages.length === 0 && (
              <div className="flex flex-col items-center gap-3 pt-[15vh] text-center">
                <Logo size={44} />
                <h2 className="text-xl font-semibold">{t.emptyTitle}</h2>
                <p className="max-w-md text-sm text-stone-500 dark:text-stone-400">{t.emptyHint}</p>
              </div>
            )}

            {messages.map((m) => (
              <MessageItem key={m.key ?? m.id} message={m} />
            ))}

            {notice && <p className="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-950/50 dark:text-amber-200">{notice}</p>}
          </div>
        </div>

        <Composer streaming={streaming} onSend={send} onStop={stop} />
      </main>
    </div>
  )
}
