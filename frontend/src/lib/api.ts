import axios, { type AxiosError } from 'axios'

const baseURL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api'

export const api = axios.create({
  baseURL,
  withCredentials: false,
  headers: {
    Accept: 'application/json',
  },
})

const TOKEN_KEY = 'auth_token'

export function setAuthToken(token: string | null) {
  if (token) {
    localStorage.setItem(TOKEN_KEY, token)
    api.defaults.headers.common.Authorization = `Bearer ${token}`
  } else {
    localStorage.removeItem(TOKEN_KEY)
    delete api.defaults.headers.common.Authorization
  }
}

export function getStoredToken(): string | null {
  return localStorage.getItem(TOKEN_KEY)
}

const stored = getStoredToken()
if (stored) {
  api.defaults.headers.common.Authorization = `Bearer ${stored}`
}

// AuthContext registers a handler so the interceptor can clear app-level state
// (current user, navigation) when the server signals an invalid/expired token.
let onUnauthorized: (() => void) | null = null

export function setUnauthorizedHandler(handler: (() => void) | null) {
  onUnauthorized = handler
}

api.interceptors.response.use(
  (response) => response,
  (error: AxiosError) => {
    // Treat /login as a normal credential failure, not a session expiry.
    const url = error.config?.url ?? ''
    const isLoginAttempt = url.endsWith('/login') || url.endsWith('/register')

    if (error.response?.status === 401 && !isLoginAttempt) {
      setAuthToken(null)
      onUnauthorized?.()
    }
    return Promise.reject(error)
  },
)

/** Pluck Laravel-style `errors` from a 422 response into a flat field→message map. */
export function extractFieldErrors(error: unknown): Record<string, string> {
  if (axios.isAxiosError(error) && error.response?.status === 422) {
    const data = error.response.data as { errors?: Record<string, string[]> }
    if (data.errors) {
      return Object.fromEntries(
        Object.entries(data.errors).map(([field, msgs]) => [field, msgs[0] ?? '']),
      )
    }
  }
  return {}
}

/** Best-effort top-level error message for non-422 failures. */
export function extractErrorMessage(error: unknown, fallback = 'Something went wrong.'): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as { message?: string } | undefined
    if (data?.message) return data.message
    if (error.code === 'ERR_NETWORK') return 'Cannot reach the server.'
  }
  return fallback
}
