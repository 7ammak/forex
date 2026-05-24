import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, extractErrorMessage, extractFieldErrors } from '../../lib/api'
import { formatUSD } from '../../lib/format'
import { useToast } from '../../components/Toast'
import { useConfirm } from '../../components/Confirm'
import type { FundRequest } from '../../types'

interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

interface AdminUserRow {
  id: number
  name: string
  email: string
  role: 'user' | 'admin'
  balance: number
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString()
}

export default function Approvals() {
  const [creditOpen, setCreditOpen] = useState(false)

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Approvals</h1>
          <p className="text-sm text-gray-500 mt-1">
            Review pending deposit and withdrawal requests, or credit a user
            directly without a request.
          </p>
        </div>
        <button
          type="button"
          onClick={() => setCreditOpen(true)}
          className="rounded bg-purple-600 px-4 py-2 text-sm font-medium text-white hover:bg-purple-700"
        >
          Credit a user
        </button>
      </header>

      <PendingQueue />

      {creditOpen && <CreditUserModal onClose={() => setCreditOpen(false)} />}
    </div>
  )
}

// ---------------- Pending queue ----------------

function PendingQueue() {
  const query = useQuery({
    queryKey: ['admin', 'fund-requests', { status: 'pending' }],
    queryFn: async () =>
      (await api.get<Paginated<FundRequest>>('/admin/fund-requests', {
        params: { status: 'pending' },
      })).data,
  })

  const rows = query.data?.data ?? []
  const errorMessage = query.isError ? extractErrorMessage(query.error) : null

  return (
    <section className="bg-white rounded-lg shadow overflow-hidden">
      <header className="px-4 sm:px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
          Pending requests
        </h2>
        <span className="text-xs text-gray-500">
          {query.data ? `${query.data.total} pending` : '…'}
        </span>
      </header>

      {query.isLoading ? (
        <div className="p-4 space-y-2">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-14 rounded bg-gray-100 animate-pulse" />
          ))}
        </div>
      ) : errorMessage ? (
        <div className="p-6 text-sm text-red-600" role="alert">{errorMessage}</div>
      ) : rows.length === 0 ? (
        <div className="p-8 text-center text-sm text-gray-500">
          No pending requests right now.
        </div>
      ) : (
        <ul className="divide-y divide-gray-200">
          {rows.map((row) => (
            <RequestRow key={row.id} row={row} />
          ))}
        </ul>
      )}
    </section>
  )
}

function RequestRow({ row }: { row: FundRequest }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const act = useMutation({
    mutationFn: async (action: 'approve' | 'reject') =>
      (await api.post(`/admin/fund-requests/${row.id}/${action}`)).data,
    onSuccess: (_data, action) => {
      const verbed = action === 'approve' ? 'Approved' : 'Rejected'
      toast.show(
        `${verbed} ${row.type} of ${formatUSD(Number(row.amount))} for ${row.user?.name ?? `user #${row.user_id}`}.`,
      )
      queryClient.invalidateQueries({ queryKey: ['admin', 'fund-requests'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'stats'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'audit-logs'] })
    },
    onError: (err) => {
      toast.show(extractErrorMessage(err, `Could not update request.`), 'error')
    },
  })

  async function doAction(action: 'approve' | 'reject') {
    // Reject is always destructive. Approving a withdrawal moves real money
    // OUT of the user's balance. Approving a deposit just credits — friendly.
    const isDestructive = action === 'reject' || (action === 'approve' && row.type === 'withdrawal')
    if (isDestructive) {
      const ok = await confirm({
        title:
          action === 'reject'
            ? `Reject ${row.type} of ${formatUSD(Number(row.amount))}?`
            : `Approve withdrawal of ${formatUSD(Number(row.amount))}?`,
        message:
          action === 'reject'
            ? 'The request will be marked rejected. The user can submit a new one.'
            : `${formatUSD(Number(row.amount))} will be debited from ${row.user?.name ?? 'this user'}'s ledger immediately.`,
        confirmLabel: action === 'reject' ? 'Reject' : 'Approve withdrawal',
        destructive: true,
      })
      if (!ok) return
    }
    act.mutate(action)
  }

  return (
    <li className="px-4 sm:px-6 py-3">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2 flex-wrap">
            <span
              className={[
                'inline-flex items-center rounded px-2 py-0.5 text-xs font-semibold',
                row.type === 'deposit'
                  ? 'bg-green-100 text-green-700'
                  : 'bg-amber-100 text-amber-800',
              ].join(' ')}
            >
              {row.type === 'deposit' ? '↓ Deposit' : '↑ Withdrawal'}
            </span>
            <span className="font-medium text-gray-900 tabular-nums">
              {formatUSD(Number(row.amount))}
            </span>
            <span className="text-sm text-gray-600 truncate">
              {row.user?.name ?? `user #${row.user_id}`}
              {row.user?.email ? ` · ${row.user.email}` : ''}
            </span>
            {row.user_balance !== undefined && (
              <span className="text-xs text-gray-500">
                (current balance {formatUSD(Number(row.user_balance))})
              </span>
            )}
          </div>
          <div className="text-xs text-gray-500 mt-1">
            Requested {formatDate(row.created_at)}
            {row.note ? ` · ${row.note}` : ''}
          </div>
        </div>
        <div className="flex gap-2">
          <button
            type="button"
            onClick={() => doAction('reject')}
            disabled={act.isPending}
            className="rounded border border-red-300 bg-white px-3 py-1.5 text-sm text-red-700 hover:bg-red-50 disabled:opacity-50"
          >
            Reject
          </button>
          <button
            type="button"
            onClick={() => doAction('approve')}
            disabled={act.isPending}
            className="rounded bg-green-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-green-700 disabled:opacity-50"
          >
            {act.isPending && act.variables === 'approve' ? 'Approving…' : 'Approve'}
          </button>
        </div>
      </div>
    </li>
  )
}

// ---------------- Credit-a-user modal ----------------

function CreditUserModal({ onClose }: { onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [emailQuery, setEmailQuery] = useState('')
  const [matched, setMatched] = useState<AdminUserRow | null>(null)
  const [amount, setAmount] = useState('')
  const [note, setNote] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [lookupError, setLookupError] = useState<string | null>(null)

  const lookup = useMutation({
    mutationFn: async (term: string) =>
      (await api.get<{ data: AdminUserRow[] }>('/admin/users', { params: { search: term } })).data,
    onSuccess: (data) => {
      const found = data.data.find((u) => u.email.toLowerCase() === emailQuery.trim().toLowerCase())
        ?? data.data[0]
      if (!found) {
        setMatched(null)
        setLookupError('No user matches that email.')
      } else {
        setMatched(found)
        setLookupError(null)
      }
    },
    onError: (err) => {
      setMatched(null)
      setLookupError(extractErrorMessage(err, 'Could not look up that user.'))
    },
  })

  const submit = useMutation({
    mutationFn: async (input: { amount: number; note: string }) => {
      if (!matched) throw new Error('No user selected')
      return (await api.post(`/admin/users/${matched.id}/adjust-balance`, {
        direction: 'credit',
        amount: input.amount,
        note: input.note,
      })).data
    },
    onSuccess: () => {
      toast.show(`Credited ${formatUSD(Number(amount))} to ${matched?.name}.`)
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'stats'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'audit-logs'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'fund-requests'] })
      onClose()
    },
    onError: (err) => {
      const fields = extractFieldErrors(err)
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        toast.show(extractErrorMessage(err, 'Could not credit the user.'), 'error')
      }
    },
  })

  function handleLookup(e: FormEvent) {
    e.preventDefault()
    const term = emailQuery.trim()
    if (!term) {
      setLookupError('Enter an email address.')
      return
    }
    lookup.mutate(term)
  }

  function handleSubmit(e: FormEvent) {
    e.preventDefault()
    const errs: Record<string, string> = {}
    const parsed = Number(amount)
    if (!Number.isFinite(parsed) || parsed <= 0) errs.amount = 'Enter an amount greater than zero.'
    if (!note.trim()) errs.note = 'A note is required.'
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs)
      return
    }
    submit.mutate({ amount: parsed, note: note.trim() })
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="credit-title"
    >
      <div
        className="bg-white rounded-lg shadow-xl w-full max-w-md"
        onClick={(e) => e.stopPropagation()}
      >
        <header className="flex items-center justify-between border-b border-gray-200 px-5 py-3">
          <h2 id="credit-title" className="text-lg font-semibold text-gray-900">
            Credit a user
          </h2>
          <button
            type="button"
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 p-1"
            aria-label="Close"
          >
            ✕
          </button>
        </header>

        <div className="px-5 py-4 space-y-4">
          <p className="text-sm text-gray-600">
            Direct credit via <code className="text-xs">adjust-balance</code>.
            No fund request required.
          </p>

          {/* Step 1: find the user */}
          <form onSubmit={handleLookup} className="space-y-2">
            <label htmlFor="credit-email" className="block text-sm font-medium text-gray-700">
              User email
            </label>
            <div className="flex gap-2">
              <input
                id="credit-email"
                type="email"
                value={emailQuery}
                onChange={(e) => setEmailQuery(e.target.value)}
                className="flex-1 rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                placeholder="user@example.com"
              />
              <button
                type="submit"
                disabled={lookup.isPending || !emailQuery}
                className="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50"
              >
                {lookup.isPending ? 'Finding…' : 'Find'}
              </button>
            </div>
            {lookupError && <p className="text-sm text-red-600">{lookupError}</p>}
            {matched && (
              <div className="rounded bg-gray-50 px-3 py-2 text-sm">
                <div className="font-medium text-gray-900">{matched.name}</div>
                <div className="text-xs text-gray-500">
                  {matched.email} · current balance{' '}
                  <span className="tabular-nums">{formatUSD(Number(matched.balance))}</span>
                </div>
              </div>
            )}
          </form>

          {/* Step 2: amount + note */}
          {matched && (
            <form onSubmit={handleSubmit} className="space-y-3 border-t border-gray-200 pt-4" noValidate>
              <div>
                <label htmlFor="credit-amount" className="block text-sm font-medium text-gray-700">
                  Amount (USD)
                </label>
                <input
                  id="credit-amount"
                  type="number"
                  inputMode="decimal"
                  min="0.01"
                  step="0.01"
                  value={amount}
                  onChange={(e) => setAmount(e.target.value)}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                  aria-invalid={Boolean(fieldErrors.amount)}
                />
                {fieldErrors.amount && (
                  <p className="mt-1 text-sm text-red-600">{fieldErrors.amount}</p>
                )}
              </div>

              <div>
                <label htmlFor="credit-note" className="block text-sm font-medium text-gray-700">
                  Note <span className="text-red-500">*</span>
                </label>
                <input
                  id="credit-note"
                  type="text"
                  maxLength={1000}
                  value={note}
                  onChange={(e) => setNote(e.target.value)}
                  className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500"
                  aria-invalid={Boolean(fieldErrors.note)}
                  required
                />
                {fieldErrors.note && (
                  <p className="mt-1 text-sm text-red-600">{fieldErrors.note}</p>
                )}
              </div>

              <div className="flex justify-end gap-2 pt-2">
                <button
                  type="button"
                  onClick={onClose}
                  className="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
                >
                  Cancel
                </button>
                <button
                  type="submit"
                  disabled={submit.isPending}
                  className="rounded bg-purple-600 px-4 py-1.5 text-sm font-medium text-white hover:bg-purple-700 disabled:opacity-50"
                >
                  {submit.isPending ? 'Crediting…' : 'Credit balance'}
                </button>
              </div>
            </form>
          )}
        </div>
      </div>
    </div>
  )
}
