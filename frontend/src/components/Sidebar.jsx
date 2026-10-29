import { useI18n } from '../i18n.js'
import { EditIcon, Logo, LogoutIcon, MoonIcon, PlusIcon, SearchIcon, SettingsIcon, SunIcon, TrashIcon } from './Icons.jsx'

export default function Sidebar({ open, onClose, user, conversations, activeId, modelOnline, settings, onNew, onSelect, onRename, onDelete, onSignOut, search, onSearch, onOpenInstructions }) {
  const { t } = useI18n()

  return (
    <>
      {open && <div className="fixed inset-0 z-20 bg-black/40 md:hidden" onClick={onClose} />}

      <aside
        className={`fixed inset-y-0 start-0 z-30 flex w-72 flex-col border-e border-stone-200 bg-stone-50 transition-transform dark:border-stone-800 dark:bg-stone-900 md:static ${open ? '' : 'max-md:ltr:-translate-x-full max-md:rtl:translate-x-full'}`}
      >
        <div className="flex items-center gap-2 px-4 py-4">
          <Logo size={28} />
          <div className="min-w-0 leading-tight">
            <div className="text-lg font-semibold">{t.appName}</div>
            <div className="truncate text-xs text-stone-500 dark:text-stone-400">
              {t.tagline}
            </div>
          </div>
        </div>

        <div className="px-3">
          <button onClick={onNew} className="flex w-full items-center gap-2 rounded-lg border border-stone-300 px-3 py-2 text-sm font-medium hover:bg-white dark:border-stone-700 dark:hover:bg-stone-800">
            <PlusIcon />
            {t.newChat}
          </button>
          <label className="relative mt-2 block">
            <span className="pointer-events-none absolute inset-y-0 start-2.5 flex items-center text-stone-400">
              <SearchIcon />
            </span>
            <input
              type="search"
              dir="auto"
              value={search}
              onChange={(e) => onSearch(e.target.value)}
              placeholder={t.search}
              aria-label={t.search}
              className="w-full rounded-lg border border-stone-300 bg-white py-1.5 pe-2 ps-8 text-sm outline-none placeholder:text-stone-400 focus:border-teal-600 dark:border-stone-700 dark:bg-stone-950"
            />
          </label>
        </div>

        <nav className="mt-4 flex-1 overflow-y-auto px-2" aria-label={t.history}>
          <p className="px-2 pb-1 text-xs font-medium text-stone-500">{t.history}</p>
          {conversations.length === 0 && <p className="px-2 py-2 text-sm text-stone-500">{search.trim() ? t.noResults : t.noConversations}</p>}
          <ul className="space-y-0.5">
            {conversations.map((c) => (
              <li key={c.id} className="group relative">
                <button
                  onClick={() => onSelect(c.id)}
                  dir="auto"
                  className={`block w-full truncate rounded-lg py-2 pe-16 ps-3 text-start text-sm ${
                    c.id === activeId ? 'bg-teal-100 text-teal-900 dark:bg-teal-900/40 dark:text-teal-100' : 'hover:bg-stone-200/70 dark:hover:bg-stone-800'
                  }`}
                >
                  {c.title}
                </button>
                <div className="absolute inset-y-0 end-1 flex items-center gap-0.5 opacity-100 md:opacity-0 md:group-hover:opacity-100 md:group-focus-within:opacity-100">
                  <button onClick={() => onRename(c)} aria-label={t.rename} title={t.rename} className="rounded p-1.5 text-stone-500 hover:bg-stone-300/60 dark:hover:bg-stone-700">
                    <EditIcon />
                  </button>
                  <button onClick={() => onDelete(c)} aria-label={t.delete} title={t.delete} className="rounded p-1.5 text-stone-500 hover:bg-red-100 hover:text-red-700 dark:hover:bg-red-950">
                    <TrashIcon />
                  </button>
                </div>
              </li>
            ))}
          </ul>
        </nav>

        <div className="space-y-2 border-t border-stone-200 p-3 text-sm dark:border-stone-800">
          <div className="flex items-center gap-2 px-1 text-xs text-stone-500">
            <span className={`size-2 rounded-full ${modelOnline === null ? 'bg-stone-400' : modelOnline ? 'bg-emerald-500' : 'bg-red-500'}`} />
            {modelOnline ? t.modelOnline : modelOnline === false ? t.modelOffline : t.loading}
          </div>
          <div className="flex items-center gap-1">
            <span className="flex-1 truncate px-1 text-stone-600 dark:text-stone-400">
              {user.email}
            </span>
            <button onClick={onOpenInstructions} aria-label={t.instructions} title={t.instructions} className="rounded-lg p-1.5 hover:bg-stone-200 dark:hover:bg-stone-800">
              <SettingsIcon />
            </button>
            <button onClick={settings.toggleLang} className="rounded-lg px-2 py-1.5 text-xs hover:bg-stone-200 dark:hover:bg-stone-800">
              {t.language}
            </button>
            <button onClick={settings.toggleTheme} aria-label={t.theme} title={t.theme} className="rounded-lg p-1.5 hover:bg-stone-200 dark:hover:bg-stone-800">
              {settings.dark ? <SunIcon /> : <MoonIcon />}
            </button>
            <button onClick={onSignOut} aria-label={t.signOut} title={t.signOut} className="rounded-lg p-1.5 hover:bg-stone-200 dark:hover:bg-stone-800">
              <LogoutIcon />
            </button>
          </div>
        </div>
      </aside>
    </>
  )
}
