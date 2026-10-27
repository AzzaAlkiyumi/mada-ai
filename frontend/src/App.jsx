import { useEffect, useMemo, useState } from 'react'
import { api } from './api.js'
import ChatApp from './components/ChatApp.jsx'
import Login from './components/Login.jsx'
import { I18nContext, translator } from './i18n.js'

function readPref(key, fallback) {
  try {
    return localStorage.getItem(key) ?? fallback
  } catch {
    return fallback
  }
}

function writePref(key, value) {
  try {
    localStorage.setItem(key, value)
  } catch {
    // Private mode: preferences just don't persist.
  }
}

export default function App() {
  const [lang, setLang] = useState(() => readPref('lang', navigator.language?.startsWith('en') ? 'en' : 'ar'))
  const [dark, setDark] = useState(() => document.documentElement.classList.contains('dark'))
  // undefined = still checking the session, null = signed out
  const [user, setUser] = useState(undefined)

  const i18n = useMemo(() => ({ lang, t: translator(lang) }), [lang])

  useEffect(() => {
    document.documentElement.lang = lang
    document.documentElement.dir = lang === 'ar' ? 'rtl' : 'ltr'
    document.title = i18n.t.appName
  }, [lang, i18n])

  useEffect(() => {
    api
      .me()
      .then((data) => setUser(data.user))
      .catch(() => setUser(null))
  }, [])

  const toggleLang = () => {
    const next = lang === 'ar' ? 'en' : 'ar'
    setLang(next)
    writePref('lang', next)
  }

  const toggleTheme = () => {
    const next = !dark
    setDark(next)
    document.documentElement.classList.toggle('dark', next)
    writePref('theme', next ? 'dark' : 'light')
  }

  const settings = { dark, toggleTheme, toggleLang }

  return (
    <I18nContext.Provider value={i18n}>
      {user === undefined ? (
        <div className="flex h-full items-center justify-center text-stone-500">{i18n.t.loading}</div>
      ) : user ? (
        <ChatApp user={user} onSignedOut={() => setUser(null)} settings={settings} />
      ) : (
        <Login onSignedIn={setUser} settings={settings} />
      )}
    </I18nContext.Provider>
  )
}
