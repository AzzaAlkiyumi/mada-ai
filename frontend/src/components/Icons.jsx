// Small inline icons (no icon library needed).
const base = { width: 18, height: 18, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', strokeWidth: 2, strokeLinecap: 'round', strokeLinejoin: 'round', 'aria-hidden': true }

export const Logo = ({ size = 32 }) => (
  <svg width={size} height={size} viewBox="0 0 32 32" aria-hidden="true">
    <rect width="32" height="32" rx="9" fill="#0f766e" />
    <path d="M9 21.5c0-5.5 3.1-10 7-10s7 4.5 7 10" fill="none" stroke="#fff" strokeWidth="2.6" strokeLinecap="round" />
    <circle cx="16" cy="9.5" r="2.4" fill="#5eead4" />
  </svg>
)

export const PlusIcon = () => (
  <svg {...base}>
    <path d="M12 5v14M5 12h14" />
  </svg>
)

export const SendIcon = () => (
  <svg {...base} className="rtl:-scale-x-100">
    <path d="M5 12h14M13 6l6 6-6 6" />
  </svg>
)

export const AttachIcon = () => (
  <svg {...base}>
    <rect x="3" y="5" width="18" height="14" rx="2" />
    <circle cx="9" cy="10" r="1.5" />
    <path d="m21 16-5-5-8 8" />
  </svg>
)

export const CloseIcon = () => (
  <svg {...base} width={14} height={14}>
    <path d="M6 6l12 12M18 6 6 18" />
  </svg>
)

export const StopIcon = () => (
  <svg {...base}>
    <rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor" />
  </svg>
)

export const CopyIcon = () => (
  <svg {...base} width={15} height={15}>
    <rect x="9" y="9" width="11" height="11" rx="2" />
    <path d="M5 15V6a2 2 0 0 1 2-2h9" />
  </svg>
)

export const CheckIcon = () => (
  <svg {...base} width={15} height={15}>
    <path d="M5 12l5 5L20 7" />
  </svg>
)

export const TrashIcon = () => (
  <svg {...base} width={15} height={15}>
    <path d="M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3" />
  </svg>
)

export const EditIcon = () => (
  <svg {...base} width={15} height={15}>
    <path d="M4 20h4L19 9l-4-4L4 16v4zM13 7l4 4" />
  </svg>
)

export const SearchIcon = () => (
  <svg {...base} width={15} height={15}>
    <circle cx="11" cy="11" r="7" />
    <path d="m20 20-3.5-3.5" />
  </svg>
)

export const SettingsIcon = () => (
  <svg {...base}>
    <path d="M4 7h10M18 7h2M4 17h4M12 17h8" />
    <circle cx="16" cy="7" r="2" />
    <circle cx="10" cy="17" r="2" />
  </svg>
)

export const RetryIcon = () => (
  <svg {...base} width={15} height={15}>
    <path d="M20 11a8 8 0 1 0-2.3 5.7M20 5v6h-6" />
  </svg>
)

export const FileIcon = () => (
  <svg {...base}>
    <path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z" />
    <path d="M14 3v5h5M9 13h6M9 17h4" />
  </svg>
)

export const MenuIcon = () => (
  <svg {...base}>
    <path d="M4 6h16M4 12h16M4 18h16" />
  </svg>
)

export const MoonIcon = () => (
  <svg {...base}>
    <path d="M21 13A9 9 0 1 1 11 3a7 7 0 0 0 10 10z" />
  </svg>
)

export const SunIcon = () => (
  <svg {...base}>
    <circle cx="12" cy="12" r="4" />
    <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
  </svg>
)

export const LogoutIcon = () => (
  <svg {...base} className="rtl:-scale-x-100">
    <path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 16l4-4-4-4M14 12H4" />
  </svg>
)
