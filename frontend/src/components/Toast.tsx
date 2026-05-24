import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
  type ReactNode,
} from 'react'

export type ToastVariant = 'success' | 'error' | 'info'

interface ToastEntry {
  id: number
  message: string
  variant: ToastVariant
}

interface ToastContextValue {
  show: (message: string, variant?: ToastVariant) => void
}

const ToastContext = createContext<ToastContextValue | null>(null)
const DEFAULT_DURATION_MS = 4000

export function ToastProvider({ children }: { children: ReactNode }) {
  const [toasts, setToasts] = useState<ToastEntry[]>([])
  const nextId = useRef(0)

  const dismiss = useCallback((id: number) => {
    setToasts((current) => current.filter((t) => t.id !== id))
  }, [])

  const show = useCallback<ToastContextValue['show']>(
    (message, variant = 'success') => {
      const id = ++nextId.current
      setToasts((current) => [...current, { id, message, variant }])
      window.setTimeout(() => dismiss(id), DEFAULT_DURATION_MS)
    },
    [dismiss],
  )

  return (
    <ToastContext.Provider value={{ show }}>
      {children}
      <div
        aria-live="polite"
        aria-atomic="true"
        className="fixed top-4 right-4 z-[100] flex flex-col gap-2 w-[min(20rem,calc(100vw-2rem))]"
      >
        {toasts.map((t) => (
          <ToastItem key={t.id} entry={t} onDismiss={() => dismiss(t.id)} />
        ))}
      </div>
    </ToastContext.Provider>
  )
}

function ToastItem({
  entry,
  onDismiss,
}: {
  entry: ToastEntry
  onDismiss: () => void
}) {
  const [leaving, setLeaving] = useState(false)
  useEffect(() => {
    const id = window.setTimeout(() => setLeaving(true), DEFAULT_DURATION_MS - 300)
    return () => window.clearTimeout(id)
  }, [])

  const styles: Record<ToastVariant, string> = {
    success: 'border-green-200 bg-green-50 text-green-800',
    error: 'border-red-200 bg-red-50 text-red-800',
    info: 'border-blue-200 bg-blue-50 text-blue-800',
  }
  const icons: Record<ToastVariant, string> = {
    success: '✓',
    error: '!',
    info: 'i',
  }

  return (
    <div
      role={entry.variant === 'error' ? 'alert' : 'status'}
      className={[
        'pointer-events-auto rounded-md border shadow-sm px-3 py-2 text-sm flex items-start gap-2 transition-opacity duration-300',
        styles[entry.variant],
        leaving ? 'opacity-0' : 'opacity-100',
      ].join(' ')}
    >
      <span className="font-mono mt-0.5">{icons[entry.variant]}</span>
      <span className="flex-1 break-words">{entry.message}</span>
      <button
        type="button"
        onClick={onDismiss}
        className="ml-1 text-current/70 hover:text-current"
        aria-label="Dismiss"
      >
        ✕
      </button>
    </div>
  )
}

export function useToast(): ToastContextValue {
  const ctx = useContext(ToastContext)
  if (!ctx) throw new Error('useToast must be used inside <ToastProvider>')
  return ctx
}
