// Thin client for the Laravel API. Auth uses Sanctum's cookie session:
// fetch /sanctum/csrf-cookie once, then send the XSRF-TOKEN cookie back
// as the X-XSRF-TOKEN header on every state-changing request.

export class ApiError extends Error {
  constructor(message, status, errors = {}) {
    super(message)
    this.status = status
    this.errors = errors
  }
}

function xsrfToken() {
  const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)
  return match ? decodeURIComponent(match[1]) : ''
}

async function ensureCsrfCookie() {
  if (!xsrfToken()) {
    await fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' })
  }
}

function headers(extra = {}) {
  return {
    Accept: 'application/json',
    'Content-Type': 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    'X-XSRF-TOKEN': xsrfToken(),
    ...extra,
  }
}

async function toError(response) {
  let body = {}
  try {
    body = await response.json()
  } catch {
    // Non-JSON error page (e.g. a proxy error).
  }
  return new ApiError(body.message || `HTTP ${response.status}`, response.status, body.errors || {})
}

async function request(method, path, data) {
  if (method !== 'GET') await ensureCsrfCookie()

  const response = await fetch(`/api${path}`, {
    method,
    credentials: 'same-origin',
    headers: headers(),
    body: data === undefined ? undefined : JSON.stringify(data),
  })

  if (!response.ok) throw await toError(response)
  return response.json()
}

export const api = {
  me: () => request('GET', '/me'),
  login: (email, password, remember) => request('POST', '/login', { email, password, remember }),
  logout: () => request('POST', '/logout'),
  conversations: () => request('GET', '/conversations'),
  conversation: (id) => request('GET', `/conversations/${id}`),
  renameConversation: (id, title) => request('PATCH', `/conversations/${id}`, { title }),
  deleteConversation: (id) => request('DELETE', `/conversations/${id}`),
  modelStatus: () => request('GET', '/model/status'),
}

/**
 * Send a message and read the reply as server-sent events.
 * Handlers: onMeta, onDelta, onDone, onError. Pass an AbortSignal to stop.
 */
export async function streamChat({ conversationId, content, signal, onMeta, onDelta, onDone, onError }) {
  await ensureCsrfCookie()

  const response = await fetch('/api/chat', {
    method: 'POST',
    credentials: 'same-origin',
    headers: headers({ Accept: 'text/event-stream' }),
    body: JSON.stringify({ conversation_id: conversationId ?? null, content }),
    signal,
  })

  if (!response.ok) throw await toError(response)

  const reader = response.body.pipeThrough(new TextDecoderStream()).getReader()
  let buffer = ''

  for (;;) {
    const { value, done } = await reader.read()
    if (done) break
    buffer += value

    let boundary
    while ((boundary = buffer.indexOf('\n\n')) !== -1) {
      const raw = buffer.slice(0, boundary)
      buffer = buffer.slice(boundary + 2)

      const event = raw.match(/^event: (.*)$/m)?.[1]
      const data = raw.match(/^data: (.*)$/m)?.[1]
      if (!event || data === undefined) continue

      const payload = JSON.parse(data)
      if (event === 'meta') onMeta?.(payload)
      else if (event === 'delta') onDelta?.(payload.text)
      else if (event === 'done') onDone?.(payload.message)
      else if (event === 'error') onError?.(payload.message)
    }
  }
}
