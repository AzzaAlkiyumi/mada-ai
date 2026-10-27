import { useState } from 'react'

export function useCopy() {
  const [copied, setCopied] = useState(false)
  const copy = async (text) => {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      setTimeout(() => setCopied(false), 1500)
    } catch {
      // Clipboard blocked (e.g. plain http on a remote host).
    }
  }
  return [copied, copy]
}
