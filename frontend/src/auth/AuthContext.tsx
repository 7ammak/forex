import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react'
import {
  api,
  getStoredToken,
  setAuthToken,
  setUnauthorizedHandler,
} from '../lib/api'

export type Role = 'user' | 'admin'

export interface User {
  id: number
  name: string
  email: string
  role: Role
  status: 'active' | 'suspended'
}

interface AuthState {
  user: User | null
  balance: number | null
  loading: boolean
}

interface AuthContextValue extends AuthState {
  login: (email: string, password: string) => Promise<User>
  register: (input: { name: string; email: string; password: string }) => Promise<User>
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

interface MeResponse {
  user: User
  balance: number
  available_balance: number
}

interface AuthResponse {
  user: User
  token: string
}

export function AuthProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState<AuthState>({
    user: null,
    balance: null,
    loading: Boolean(getStoredToken()),
  })

  const clearAuth = useCallback(() => {
    setAuthToken(null)
    setState({ user: null, balance: null, loading: false })
  }, [])

  const fetchMe = useCallback(async () => {
    const { data } = await api.get<MeResponse>('/me')
    setState({ user: data.user, balance: data.balance, loading: false })
  }, [])

  const refresh = useCallback(async () => {
    if (!getStoredToken()) {
      clearAuth()
      return
    }
    try {
      await fetchMe()
    } catch {
      clearAuth()
    }
  }, [fetchMe, clearAuth])

  // Initial load: if a token is in localStorage, validate it via /api/me.
  useEffect(() => {
    if (!getStoredToken()) {
      setState((s) => ({ ...s, loading: false }))
      return
    }
    refresh()
  }, [refresh])

  // Let the axios 401 interceptor wipe app-level state when the server
  // rejects our token (suspended, revoked, expired).
  useEffect(() => {
    setUnauthorizedHandler(() => {
      setState({ user: null, balance: null, loading: false })
    })
    return () => setUnauthorizedHandler(null)
  }, [])

  const login = useCallback<AuthContextValue['login']>(async (email, password) => {
    const { data } = await api.post<AuthResponse>('/login', { email, password })
    setAuthToken(data.token)
    setState({ user: data.user, balance: null, loading: false })
    // Pull balance + canonical user shape from /me so the dashboard has data ready.
    try {
      await fetchMe()
    } catch {
      // /me will retry on next mount; not fatal here.
    }
    return data.user
  }, [fetchMe])

  const register = useCallback<AuthContextValue['register']>(async (input) => {
    const { data } = await api.post<AuthResponse>('/register', input)
    setAuthToken(data.token)
    setState({ user: data.user, balance: 0, loading: false })
    return data.user
  }, [])

  const logout = useCallback(async () => {
    try {
      await api.post('/logout')
    } catch {
      // Server-side revocation is best-effort; we still clear local state.
    }
    clearAuth()
  }, [clearAuth])

  const value = useMemo<AuthContextValue>(
    () => ({ ...state, login, register, logout, refresh }),
    [state, login, register, logout, refresh],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}

export function homePathFor(user: User): string {
  return user.role === 'admin' ? '/admin' : '/dashboard'
}
