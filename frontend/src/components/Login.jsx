import { useState } from 'react'
import { api } from '../api.js'
import { useI18n } from '../i18n.js'
import { Logo, MoonIcon, SunIcon } from './Icons.jsx'

export default function Login({ onSignedIn, settings }) {
  const { t } = useI18n()
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [remember, setRemember] = useState(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')

  const submit = async (e) => {
    e.preventDefault()
    setBusy(true)
    setError('')
    try {
      const { user } = await api.login(email, password, remember)
      onSignedIn(user)
    } catch (err) {
      setError(err.status === 429 ? t.errorRate : err.errors?.email?.[0] || err.message || t.errorGeneric)
    } finally {
      setBusy(false)
    }
  }

  const input =
    'w-full rounded-lg border border-stone-300 bg-white px-3 py-2.5 outline-none focus:border-teal-600 focus:ring-2 focus:ring-teal-600/20 dark:border-stone-700 dark:bg-stone-900'

  return (
    <div className="flex min-h-full flex-col items-center justify-center px-4 py-10">
      <div className="absolute end-4 top-4 flex gap-2">
        <button onClick={settings.toggleLang} className="rounded-lg px-3 py-1.5 text-sm hover:bg-stone-100 dark:hover:bg-stone-800">
          {t.language}
        </button>
        <button onClick={settings.toggleTheme} aria-label={t.theme} className="rounded-lg p-2 hover:bg-stone-100 dark:hover:bg-stone-800">
          {settings.dark ? <SunIcon /> : <MoonIcon />}
        </button>
      </div>

      <form onSubmit={submit} className="w-full max-w-sm space-y-5">
        <div className="flex flex-col items-center gap-3 text-center">
          <Logo size={48} />
          <h1 className="text-2xl font-semibold">{t.appName}</h1>
          <p className="text-sm text-stone-500 dark:text-stone-400" dir="ltr">{t.tagline}</p>
        </div>

        <label className="block space-y-1.5">
          <span className="text-sm font-medium">{t.email}</span>
          <input type="email" dir="ltr" autoComplete="username" required value={email} onChange={(e) => setEmail(e.target.value)} className={input} />
        </label>

        <label className="block space-y-1.5">
          <span className="text-sm font-medium">{t.password}</span>
          <input type="password" dir="ltr" autoComplete="current-password" required value={password} onChange={(e) => setPassword(e.target.value)} className={input} />
        </label>

        <label className="flex items-center gap-2 text-sm">
          <input type="checkbox" checked={remember} onChange={(e) => setRemember(e.target.checked)} className="accent-teal-700" />
          {t.remember}
        </label>

        {error && <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/50 dark:text-red-300">{error}</p>}

        <button disabled={busy} className="w-full rounded-lg bg-teal-700 px-4 py-2.5 font-medium text-white hover:bg-teal-800 disabled:opacity-60">
          {busy ? t.signingIn : t.signIn}
        </button>
      </form>
    </div>
  )
}
