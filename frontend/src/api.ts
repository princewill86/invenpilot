export type Account = {
  user: { id: number; name: string; email: string; platform_admin: boolean }
  businesses: { id: number; name: string; currency: string; role: string; suspended_at: string | null }[]
}

export type Page<T> = { data: T[]; current_page: number; last_page: number; total: number }

export class ApiError extends Error {
  constructor(public status: number, message: string) {
    super(message)
  }
}

export async function api<T>(
  path: string,
  data?: Record<string, unknown> | FormData,
  method = data === undefined ? 'GET' : 'POST',
  signal?: AbortSignal,
): Promise<T> {
  const headers: Record<string, string> = { Accept: 'application/json' }
  if (method !== 'GET') {
    let token = csrfToken()
    if (!token) {
      const csrf = await fetch('/sanctum/csrf-cookie', { headers, signal })
      if (!csrf.ok) throw new ApiError(csrf.status, 'Could not start a secure session.')
      token = csrfToken()
    }
    if (!token) throw new ApiError(403, 'Session cookies must be enabled.')
    headers['X-XSRF-TOKEN'] = token
    if (data !== undefined && !(data instanceof FormData)) headers['Content-Type'] = 'application/json'
  }

  const response = await fetch('/api/v1' + path, {
    method, headers, signal,
    body: data instanceof FormData ? data : data === undefined ? undefined : JSON.stringify(data),
  })
  if (response.status === 204) return undefined as T

  const result = await response.json().catch(() => null)
  if (!response.ok) {
    throw new ApiError(response.status, result?.message ?? 'The request could not be completed.')
  }
  if (result === null) throw new ApiError(502, 'The server returned an unexpected response.')
  return result as T
}

function csrfToken() {
  const token = document.cookie.split('; ').find(cookie => cookie.startsWith('XSRF-TOKEN='))
  return token ? decodeURIComponent(token.slice('XSRF-TOKEN='.length)) : null
}

export function errorMessage(error: unknown) {
  return error instanceof ApiError ? error.message : 'The server could not be reached.'
}

export function query(values: Record<string, string | number | undefined>) {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(values)) {
    if (value !== undefined && value !== '') params.set(key, String(value))
  }
  const result = params.toString()
  return result ? '?' + result : ''
}
