import { useEffect, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, extractErrorMessage, extractFieldErrors } from '../../lib/api'
import { formatSignedUSD, formatUSD } from '../../lib/format'
import { useToast } from '../../components/Toast'
import { useConfirm } from '../../components/Confirm'
import type { AdminTradeRow } from '../../types'

type StatusFilter = 'all' | 'open' | 'closed'

interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  from: number | null
  to: number | null
  total: number
}

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

export default function Trades() {
  const [status, setStatus] = useState<StatusFilter>('all')
  const [userIdInput, setUserIdInput] = useState('')
  const [debouncedUserId, setDebouncedUserId] = useState('')
  const [page, setPage] = useState(1)
  const [resolving, setResolving] = useState<AdminTradeRow | null>(null)

  useEffect(() => {
    const id = setTimeout(() => {
      setDebouncedUserId(userIdInput.trim())
      setPage(1)
    }, 300)
    return () => clearTimeout(id)
  }, [userIdInput])

  // Reset page when filters change
  useEffect(() => {
    setPage(1)
  }, [status])

  const tradesQuery = useQuery({
    queryKey: ['admin', 'trades', { status, userId: debouncedUserId, page }],
    queryFn: async () => {
      const params: Record<string, string | number> = { page }
      if (status !== 'all') params.status = status
      if (debouncedUserId !== '' && /^\d+$/.test(debouncedUserId)) {
        params.user_id = debouncedUserId
      }
      return (await api.get<Paginated<AdminTradeRow>>('/admin/trades', { params })).data
    },
    placeholderData: (previous) => previous,
  })

  const errorMessage = tradesQuery.isError ? extractErrorMessage(tradesQuery.error) : null
  const rows = tradesQuery.data?.data ?? []

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Trades</h1>
          <p className="text-sm text-gray-500 mt-1">
            {tradesQuery.data
              ? `${tradesQuery.data.total.toLocaleString()} total`
              : 'Loading…'}
          </p>
        </div>
      </header>

      <div className="flex flex-wrap items-center gap-3">
        <div className="flex gap-2">
          <FilterPill active={status === 'all'} onClick={() => setStatus('all')}>All</FilterPill>
          <FilterPill active={status === 'open'} onClick={() => setStatus('open')}>Open</FilterPill>
          <FilterPill active={status === 'closed'} onClick={() => setStatus('closed')}>Closed</FilterPill>
        </div>
        <div className="flex items-center gap-2 ml-auto">
          <label htmlFor="user-id-filter" className="text-sm text-gray-600">
            User ID
          </label>
          <input
            id="user-id-filter"
            type="text"
            inputMode="numeric"
            pattern="\d*"
            placeholder="e.g. 7"
            value={userIdInput}
            onChange={(e) => setUserIdInput(e.target.value)}
            className="w-28 rounded-md border border-gray-300 px-3 py-1.5 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
          />
          {userIdInput && (
            <button
              type="button"
              onClick={() => setUserIdInput('')}
              className="text-sm text-gray-500 hover:text-gray-700"
            >
              clear
            </button>
          )}
        </div>
      </div>

      <section className="bg-white rounded-lg shadow overflow-hidden">
        {tradesQuery.isLoading ? (
          <TableSkeleton />
        ) : errorMessage ? (
          <div className="p-6 text-sm text-red-600" role="alert">{errorMessage}</div>
        ) : rows.length === 0 ? (
          <div className="p-8 text-center text-sm text-gray-500">No trades match these filters.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">ID</th>
                  <th className="px-4 py-3 text-left font-medium">User</th>
                  <th className="px-4 py-3 text-left font-medium">Pair</th>
                  <th className="px-4 py-3 text-left font-medium">Direction</th>
                  <th className="px-4 py-3 text-right font-medium">Stake</th>
                  <th className="px-4 py-3 text-left font-medium">Status</th>
                  <th className="px-4 py-3 text-right font-medium">P&amp;L</th>
                  <th className="px-4 py-3 text-left font-medium hidden md:table-cell">Opened</th>
                  <th className="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {rows.map((trade) => (
                  <TradeRow key={trade.id} trade={trade} onResolve={() => setResolving(trade)} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>

      <Pagination
        page={tradesQuery.data?.current_page ?? 1}
        lastPage={tradesQuery.data?.last_page ?? 1}
        from={tradesQuery.data?.from ?? null}
        to={tradesQuery.data?.to ?? null}
        total={tradesQuery.data?.total ?? 0}
        onChange={setPage}
        disabled={tradesQuery.isFetching}
      />

      {resolving && (
        <ResolveModal trade={resolving} onClose={() => setResolving(null)} />
      )}
    </div>
  )
}

function TradeRow({ trade, onResolve }: { trade: AdminTradeRow; onResolve: () => void }) {
  const pnl = trade.pnl === null ? null : Number(trade.pnl)
  const pnlClass =
    pnl === null
      ? 'text-gray-400'
      : pnl > 0
        ? 'text-green-600 font-medium'
        : pnl < 0
          ? 'text-red-600 font-medium'
          : 'text-gray-900'

  return (
    <tr className="hover:bg-gray-50">
      <td className="px-4 py-3 text-gray-500 tabular-nums">#{trade.id}</td>
      <td className="px-4 py-3">
        <div className="font-medium text-gray-900 truncate max-w-[160px]">
          {trade.user?.name ?? `user #${trade.user_id}`}
        </div>
        <div className="text-xs text-gray-500 truncate max-w-[160px]">
          {trade.user?.email ?? ''}
        </div>
      </td>
      <td className="px-4 py-3 font-medium text-gray-900">
        {trade.currency_pair?.symbol ?? `#${trade.currency_pair_id}`}
      </td>
      <td className="px-4 py-3">
        <span
          className={[
            'inline-flex items-center justify-center w-12 rounded text-xs font-semibold py-0.5',
            trade.direction === 'buy' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700',
          ].join(' ')}
        >
          {trade.direction.toUpperCase()}
        </span>
      </td>
      <td className="px-4 py-3 text-right tabular-nums">{formatUSD(Number(trade.stake))}</td>
      <td className="px-4 py-3">
        {trade.status === 'open' ? (
          <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800">open</span>
        ) : trade.outcome === 'win' ? (
          <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700">win</span>
        ) : (
          <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700">loss</span>
        )}
      </td>
      <td className={['px-4 py-3 text-right tabular-nums', pnlClass].join(' ')}>
        {pnl === null ? '—' : formatSignedUSD(pnl)}
      </td>
      <td className="px-4 py-3 text-gray-600 hidden md:table-cell">{formatDate(trade.opened_at)}</td>
      <td className="px-4 py-3 text-right">
        {trade.status === 'open' && (
          <button
            type="button"
            onClick={onResolve}
            className="rounded border border-purple-300 bg-purple-50 px-2.5 py-1 text-xs text-purple-700 hover:bg-purple-100"
          >
            Resolve
          </button>
        )}
      </td>
    </tr>
  )
}

// ---------------- Resolve modal ----------------

function ResolveModal({ trade, onClose }: { trade: AdminTradeRow; onClose: () => void }) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()
  const [outcome, setOutcome] = useState<'win' | 'loss'>('win')
  const [pnl, setPnl] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const stake = Number(trade.stake)
  const parsed = Number(pnl)
  const validNumber = Number.isFinite(parsed) && parsed > 0
  const lossExceedsStake = outcome === 'loss' && validNumber && parsed > stake

  const submit = useMutation({
    mutationFn: async (input: { outcome: 'win' | 'loss'; pnl: number }) =>
      (await api.post(`/admin/trades/${trade.id}/resolve`, input)).data,
    onSuccess: (_data, vars) => {
      const payout = vars.outcome === 'win' ? stake + vars.pnl : Math.max(0, stake - vars.pnl)
      toast.show(
        `Trade #${trade.id} resolved as ${vars.outcome.toUpperCase()}. Payout ${formatUSD(payout)}.`,
      )
      queryClient.invalidateQueries({ queryKey: ['admin', 'trades'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'stats'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'audit-logs'] })
      onClose()
    },
    onError: (err) => {
      const fields = extractFieldErrors(err)
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        toast.show(extractErrorMessage(err, 'Could not resolve trade.'), 'error')
      }
    },
  })

  async function handleSubmit(e: FormEvent) {
    e.preventDefault()
    const errs: Record<string, string> = {}
    if (!validNumber) errs.pnl = 'Enter an amount greater than zero.'
    else if (lossExceedsStake) errs.pnl = `Loss cannot exceed the stake (${formatUSD(stake)}).`
    if (Object.keys(errs).length > 0) {
      setFieldErrors(errs)
      return
    }

    const payout = outcome === 'win' ? stake + parsed : Math.max(0, stake - parsed)
    const ok = await confirm({
      title: `Resolve trade #${trade.id} as ${outcome.toUpperCase()}?`,
      message: (
        <>
          The user will receive a payout of <strong>{formatUSD(payout)}</strong>{' '}
          (stake {outcome === 'win' ? '+' : '−'} {formatUSD(parsed)}). This cannot be undone.
        </>
      ),
      confirmLabel: 'Resolve trade',
      destructive: true,
    })
    if (!ok) return

    submit.mutate({ outcome, pnl: parsed })
  }

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/40"
      onClick={onClose}
      role="dialog"
      aria-modal="true"
      aria-labelledby="resolve-title"
    >
      <div className="bg-white rounded-lg shadow-xl w-full max-w-md" onClick={(e) => e.stopPropagation()}>
        <header className="flex items-center justify-between border-b border-gray-200 px-5 py-3">
          <h2 id="resolve-title" className="text-lg font-semibold text-gray-900">
            Resolve trade #{trade.id}
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
          <dl className="rounded bg-gray-50 px-3 py-2 text-sm space-y-1">
            <div className="flex justify-between">
              <dt className="text-gray-600">User</dt>
              <dd>{trade.user?.name ?? `#${trade.user_id}`}</dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-gray-600">Pair / Direction</dt>
              <dd>
                {trade.currency_pair?.symbol ?? `#${trade.currency_pair_id}`} ·{' '}
                {trade.direction.toUpperCase()}
              </dd>
            </div>
            <div className="flex justify-between">
              <dt className="text-gray-600">Stake</dt>
              <dd className="tabular-nums">{formatUSD(stake)}</dd>
            </div>
          </dl>

          <form onSubmit={handleSubmit} className="space-y-3" noValidate>
            <div>
              <span className="block text-sm font-medium text-gray-700 mb-1">Outcome</span>
              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={() => setOutcome('win')}
                  className={[
                    'rounded-md py-2 text-sm font-medium border',
                    outcome === 'win'
                      ? 'bg-green-600 border-green-600 text-white'
                      : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                  ].join(' ')}
                >
                  Win
                </button>
                <button
                  type="button"
                  onClick={() => setOutcome('loss')}
                  className={[
                    'rounded-md py-2 text-sm font-medium border',
                    outcome === 'loss'
                      ? 'bg-red-600 border-red-600 text-white'
                      : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                  ].join(' ')}
                >
                  Loss
                </button>
              </div>
            </div>

            <div>
              <label htmlFor="resolve-pnl" className="block text-sm font-medium text-gray-700">
                {outcome === 'win' ? 'Profit amount' : 'Loss amount'} (USD)
              </label>
              <input
                id="resolve-pnl"
                type="number"
                inputMode="decimal"
                min="0.01"
                step="0.01"
                value={pnl}
                onChange={(e) => setPnl(e.target.value)}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-purple-500"
                aria-invalid={Boolean(fieldErrors.pnl)}
                required
              />
              {fieldErrors.pnl && (
                <p className="mt-1 text-sm text-red-600">{fieldErrors.pnl}</p>
              )}
              {!fieldErrors.pnl && validNumber && (
                <p className="mt-1 text-xs text-gray-500">
                  {outcome === 'win'
                    ? `Payout will be ${formatUSD(stake + parsed)} (stake + profit).`
                    : `Payout will be ${formatUSD(Math.max(0, stake - parsed))} (stake − loss).`}
                </p>
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
                {submit.isPending ? 'Resolving…' : 'Resolve trade'}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  )
}

// ---------------- shared bits ----------------

function FilterPill({
  active,
  onClick,
  children,
}: {
  active: boolean
  onClick: () => void
  children: React.ReactNode
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={[
        'rounded-full px-3 py-1.5 text-sm border transition-colors',
        active
          ? 'bg-purple-600 border-purple-600 text-white'
          : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
      ].join(' ')}
      aria-pressed={active}
    >
      {children}
    </button>
  )
}

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

function TableSkeleton() {
  return (
    <div className="p-4 space-y-2">
      {Array.from({ length: 5 }).map((_, i) => (
        <div key={i} className="h-12 rounded bg-gray-100 animate-pulse" />
      ))}
    </div>
  )
}
