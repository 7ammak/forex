import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, extractErrorMessage, extractFieldErrors } from '../../lib/api'
import { formatUSD } from '../../lib/format'
import { useToast } from '../../components/Toast'
import { useConfirm } from '../../components/Confirm'

interface AdminUserRow {
  id: number
  name: string
  email: string
  role: 'user' | 'admin'
  status: 'active' | 'suspended'
  balance: number
  created_at: string
}

interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  from: number | null
  to: number | null
  total: number
}

export default function Users() {
  const [search, setSearch] = useState('')
  const [debouncedSearch, setDebouncedSearch] = useState('')
  const [page, setPage] = useState(1)
  const [adjustingUser, setAdjustingUser] = useState<AdminUserRow | null>(null)

  // 300ms debounce so we don't fire a request on every keystroke.
  useEffect(() => {
    const id = setTimeout(() => {
      setDebouncedSearch(search)
      setPage(1) // new search starts at page 1
    }, 300)
    return () => clearTimeout(id)
  }, [search])

  const usersQuery = useQuery({
    queryKey: ['admin', 'users', { search: debouncedSearch, page }],
    queryFn: async () => {
      const params: Record<string, string | number> = { page }
      if (debouncedSearch.trim() !== '') params.search = debouncedSearch.trim()
      return (await api.get<Paginated<AdminUserRow>>('/admin/users', { params })).data
    },
    placeholderData: (previous) => previous, // keep previous page visible while loading next
  })

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Users</h1>
          <p className="text-sm text-gray-500 mt-1">
            {usersQuery.data
              ? `${usersQuery.data.total.toLocaleString()} total`
              : 'Loading…'}
          </p>
        </div>
        <div className="w-full sm:w-72">
          <label htmlFor="user-search" className="sr-only">Search users</label>
          <input
            id="user-search"
            type="search"
            placeholder="Search by name or email…"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
          />
        </div>
      </header>

      <UsersTable
        query={usersQuery}
        onAdjust={(u) => setAdjustingUser(u)}
      />

      <Pagination
        page={usersQuery.data?.current_page ?? 1}
        lastPage={usersQuery.data?.last_page ?? 1}
        from={usersQuery.data?.from ?? null}
        to={usersQuery.data?.to ?? null}
        total={usersQuery.data?.total ?? 0}
        onChange={setPage}
        disabled={usersQuery.isFetching}
      />

      {adjustingUser && (
        <AdjustBalanceModal
          user={adjustingUser}
          onClose={() => setAdjustingUser(null)}
        />
      )}
    </div>
  )
}

// ---------------- Users table ----------------

function UsersTable({
  query,
  onAdjust,
}: {
  query: ReturnType<typeof useQuery<Paginated<AdminUserRow>>>
  onAdjust: (user: AdminUserRow) => void
}) {
  const errorMessage = query.isError ? extractErrorMessage(query.error) : null
  const rows = query.data?.data ?? []

  if (query.isLoading) {
    return (
      <section className="bg-white rounded-lg shadow p-4 space-y-2">
        {Array.from({ length: 6 }).map((_, i) => (
          <div key={i} className="h-12 rounded bg-gray-100 animate-pulse" />
        ))}
      </section>
    )
  }

  if (errorMessage) {
    return (
      <section className="bg-white rounded-lg shadow p-6 text-sm text-red-600" role="alert">
        {errorMessage}
      </section>
    )
  }

  if (rows.length === 0) {
    return (
      <section className="bg-white rounded-lg shadow p-8 text-center text-sm text-gray-500">
        No users match this search.
      </section>
    )
  }

  return (
    <section className="bg-white rounded-lg shadow overflow-hidden">
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
            <tr>
              <th className="px-4 py-3 text-left font-medium">Name</th>
              <th className="px-4 py-3 text-left font-medium hidden sm:table-cell">Email</th>
              <th className="px-4 py-3 text-left font-medium">Role</th>
              <th className="px-4 py-3 text-left font-medium">Status</th>
              <th className="px-4 py-3 text-right font-medium">Balance</th>
              <th className="px-4 py-3 text-right font-medium">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-200">
            {rows.map((row) => (
              <UserRow key={row.id} row={row} onAdjust={() => onAdjust(row)} />
            ))}
          </tbody>
        </table>
      </div>
    </section>
  )
}

function UserRow({
  row,
  onAdjust,
}: {
  row: AdminUserRow
  onAdjust: () => void
}) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const updateStatus = useMutation({
    mutationFn: async (status: 'active' | 'suspended') => {
      const response = await api.patch(`/admin/users/${row.id}`, { status })
      return response.data
    },
    onSuccess: (_data, status) => {
      toast.show(
        status === 'suspended'
          ? `Suspended ${row.name}. Their tokens have been revoked.`
          : `Reactivated ${row.name}.`,
      )
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'stats'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'audit-logs'] })
    },
    onError: (err) => {
      toast.show(extractErrorMessage(err, 'Could not update status.'), 'error')
    },
  })

  async function toggleStatus() {
    if (row.status === 'active') {
      const ok = await confirm({
        title: `Suspend ${row.name}?`,
        message:
          'They will be blocked from authenticating, and all their existing tokens will be revoked immediately.',
        confirmLabel: 'Suspend',
        destructive: true,
      })
      if (!ok) return
      updateStatus.mutate('suspended')
    } else {
      updateStatus.mutate('active')
    }
  }

  return (
    <tr className="hover:bg-gray-50">
      <td className="px-4 py-3 font-medium text-gray-900">{row.name}</td>
      <td className="px-4 py-3 text-gray-600 hidden sm:table-cell">{row.email}</td>
      <td className="px-4 py-3">
        <span
          className={[
            'inline-flex items-center rounded px-2 py-0.5 text-xs font-medium',
            row.role === 'admin'
              ? 'bg-purple-100 text-purple-800'
              : 'bg-gray-100 text-gray-700',
          ].join(' ')}
        >
          {row.role}
        </span>
      </td>
      <td className="px-4 py-3">
        <span
          className={[
            'inline-flex items-center rounded px-2 py-0.5 text-xs font-medium',
            row.status === 'active'
              ? 'bg-green-100 text-green-700'
              : 'bg-red-100 text-red-700',
          ].join(' ')}
        >
          {row.status}
        </span>
      </td>
      <td className="px-4 py-3 text-right tabular-nums">
        {formatUSD(Number(row.balance))}
      </td>
      <td className="px-4 py-3 text-right whitespace-nowrap">
        <button
          type="button"
          onClick={toggleStatus}
          disabled={updateStatus.isPending}
          className="rounded border border-gray-300 px-2.5 py-1 text-xs text-gray-700 hover:bg-gray-50 disabled:opacity-50"
        >
          {row.status === 'active' ? 'Suspend' : 'Reactivate'}
        </button>
        <button
          type="button"
          onClick={onAdjust}
          className="ml-2 rounded border border-purple-300 bg-purple-50 px-2.5 py-1 text-xs text-purple-700 hover:bg-purple-100"
        >
          Adjust balance
        </button>
      </td>
    </tr>
  )
}

// ---------------- Pagination ----------------

interface PaginationProps {
  page: number
  lastPage: number
  from: number | null
  to: number | null
  total: number
  onChange: (page: number) => void
  disabled: boolean
}

function Pagination({ page, lastPage, from, to, total, onChange, disabled }: PaginationProps) {
  if (total === 0) return null
  return (
    <nav className="flex items-center justify-between" aria-label="Pagination">
      <p className="text-sm text-gray-500">
        Showing <span className="font-medium">{from ?? 0}</span>–
        <span className="font-medium">{to ?? 0}</span> of{' '}
        <span className="font-medium">{total.toLocaleString()}</span>
      </p>
      <div className="flex gap-2">
        <button
          type="button"
          onClick={() => onChange(Math.max(1, page - 1))}
          disabled={disabled || page <= 1}
          className="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Previous
        </button>
        <span className="inline-flex items-center px-2 text-sm text-gray-600">
          Page {page} of {lastPage}
        </span>
        <button
          type="button"
          onClick={() => onChange(Math.min(lastPage, page + 1))}
          disabled={disabled || page >= lastPage}
          className="rounded border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Next
        </button>
      </div>
    </nav>
  )
}

// ---------------- Adjust-balance modal ----------------

function AdjustBalanceModal({
  user,
  onClose,
}: {
  user: AdminUserRow
  onClose: () => void
}) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [direction, setDirection] = useState<'credit' | 'debit'>('credit')
  const [amount, setAmount] = useState('')
  const [note, setNote] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const submit = useMutation({
    mutationFn: async (input: { direction: 'credit' | 'debit'; amount: number; note: string }) =>
      (await api.post(`/admin/users/${user.id}/adjust-balance`, input)).data,
    onSuccess: (_data, vars) => {
      toast.show(
        `${vars.direction === 'credit' ? 'Credited' : 'Debited'} ${formatUSD(vars.amount)} ${vars.direction === 'credit' ? 'to' : 'from'} ${user.name}.`,
      )
      queryClient.invalidateQueries({ queryKey: ['admin', 'users'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'stats'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'audit-logs'] })
      onClose()
    },
    onError: (err) => {
      const fields = extractFieldErrors(err)
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        toast.show(extractErrorMessage(err, 'Could not adjust balance.'), 'error')
      }
    },
  })

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    const errs: Record<string, string> = {}
    const parsed = Number(amount)
    if (!Number.isFinite(parsed) || parsed <= 0) {
      errs.amount = 'Enter an amount greater than zero.'
    }
    if (!note.trim()) {
      errs.note = 'A note is required.'
    }
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs)
      return
    }

    const ok = await confirm({
      title: `${direction === 'credit' ? 'Credit' : 'Debit'} ${formatUSD(parsed)}?`,
      message: (
        <>
          This will {direction === 'credit' ? 'add to' : 'deduct from'}{' '}
          <strong>{user.name}</strong>&apos;s ledger immediately. The action is
          recorded in the audit log and cannot be undone.
        </>
      ),
      confirmLabel: direction === 'credit' ? 'Credit balance' : 'Debit balance',
      destructive: direction === 'debit',
    })
    if (!ok) return

    submit.mutate({ direction, amount: parsed, note: note.trim() })
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="adjust-title"
    >
      <div
        className="bg-white rounded-lg shadow-xl w-full max-w-md"
        onClick={(e) => e.stopPropagation()}
      >
        <header className="flex items-center justify-between border-b border-gray-200 px-5 py-3">
          <h2 id="adjust-title" className="text-lg font-semibold text-gray-900">
            Adjust balance
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
            Adjusting balance for{' '}
            <span className="font-medium text-gray-900">{user.name}</span>{' '}
            <span className="text-gray-500">({user.email})</span>.
            <br />
            <span className="text-xs text-gray-500">
              Current balance: {formatUSD(Number(user.balance))}
            </span>
          </p>

          <form onSubmit={handleSubmit} className="space-y-3" noValidate>
            <div>
              <span className="block text-sm font-medium text-gray-700 mb-1">
                Direction
              </span>
              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={() => setDirection('credit')}
                  className={[
                    'rounded-md py-2 text-sm font-medium border',
                    direction === 'credit'
                      ? 'bg-green-600 border-green-600 text-white'
                      : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                  ].join(' ')}
                >
                  Credit
                </button>
                <button
                  type="button"
                  onClick={() => setDirection('debit')}
                  className={[
                    'rounded-md py-2 text-sm font-medium border',
                    direction === 'debit'
                      ? 'bg-red-600 border-red-600 text-white'
                      : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                  ].join(' ')}
                >
                  Debit
                </button>
              </div>
            </div>

            <div>
              <label htmlFor="adj-amount" className="block text-sm font-medium text-gray-700">
                Amount (USD)
              </label>
              <input
                id="adj-amount"
                type="number"
                inputMode="decimal"
                min="0.01"
                step="0.01"
                value={amount}
                onChange={(e) => setAmount(e.target.value)}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                aria-invalid={Boolean(fieldErrors.amount)}
              />
              {fieldErrors.amount && (
                <p className="mt-1 text-sm text-red-600">{fieldErrors.amount}</p>
              )}
            </div>

            <div>
              <label htmlFor="adj-note" className="block text-sm font-medium text-gray-700">
                Note <span className="text-red-500">*</span>
              </label>
              <input
                id="adj-note"
                type="text"
                maxLength={1000}
                value={note}
                onChange={(e) => setNote(e.target.value)}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
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
                {submit.isPending
                  ? 'Saving…'
                  : direction === 'credit'
                    ? 'Credit balance'
                    : 'Debit balance'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}
