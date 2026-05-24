import { useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, extractErrorMessage } from '../lib/api'
import { formatSignedUSD, formatUSD } from '../lib/format'
import type { Trade } from '../types'

type StatusFilter = 'all' | 'open' | 'closed'

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  return new Date(iso).toLocaleString()
}

export default function History() {
  const [filter, setFilter] = useState<StatusFilter>('all')

  const tradesQuery = useQuery({
    queryKey: ['trades', { status: filter === 'all' ? null : filter }],
    queryFn: async () => {
      const params = filter === 'all' ? undefined : { status: filter }
      return (await api.get<{ data: Trade[] }>('/trades', { params })).data.data
    },
  })

  const trades = tradesQuery.data ?? []
  const errorMessage = tradesQuery.isError ? extractErrorMessage(tradesQuery.error) : null

  const summary = useMemo(() => {
    if (filter !== 'all') return null
    return {
      open: trades.filter((t) => t.status === 'open').length,
      closed: trades.filter((t) => t.status === 'closed').length,
    }
  }, [trades, filter])

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">History</h1>
        <p className="text-sm text-gray-500 mt-1">
          Every trade you&apos;ve opened, with its outcome and timestamps.
        </p>
      </header>

      <div className="flex flex-wrap items-center gap-2">
        <FilterPill active={filter === 'all'} onClick={() => setFilter('all')}>
          All {summary && <span className="text-gray-400">({summary.open + summary.closed})</span>}
        </FilterPill>
        <FilterPill active={filter === 'open'} onClick={() => setFilter('open')}>
          Open
        </FilterPill>
        <FilterPill active={filter === 'closed'} onClick={() => setFilter('closed')}>
          Closed
        </FilterPill>
      </div>

      <section className="bg-white rounded-lg shadow overflow-hidden">
        {tradesQuery.isLoading ? (
          <TableSkeleton />
        ) : errorMessage ? (
          <div className="p-6 text-sm text-red-600" role="alert">
            {errorMessage}
          </div>
        ) : trades.length === 0 ? (
          <div className="p-8 text-center text-sm text-gray-500">
            No trades to show{filter !== 'all' ? ` for filter "${filter}"` : ''}.
          </div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
                <tr>
                  <th className="px-4 py-3 text-left font-medium">Pair</th>
                  <th className="px-4 py-3 text-left font-medium">Direction</th>
                  <th className="px-4 py-3 text-right font-medium">Stake</th>
                  <th className="px-4 py-3 text-left font-medium">Outcome</th>
                  <th className="px-4 py-3 text-right font-medium">P&amp;L</th>
                  <th className="px-4 py-3 text-left font-medium hidden sm:table-cell">Opened</th>
                  <th className="px-4 py-3 text-left font-medium hidden md:table-cell">Resolved</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-200">
                {trades.map((trade) => (
                  <TradeRow key={trade.id} trade={trade} />
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </div>
  )
}

function TradeRow({ trade }: { trade: Trade }) {
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
      <td className="px-4 py-3 font-medium text-gray-900">
        {trade.currency_pair?.symbol ?? `#${trade.currency_pair_id}`}
      </td>
      <td className="px-4 py-3">
        <DirectionBadge direction={trade.direction} />
      </td>
      <td className="px-4 py-3 text-right tabular-nums">{formatUSD(Number(trade.stake))}</td>
      <td className="px-4 py-3">
        <OutcomeBadge status={trade.status} outcome={trade.outcome} />
      </td>
      <td className={['px-4 py-3 text-right tabular-nums', pnlClass].join(' ')}>
        {pnl === null ? '—' : formatSignedUSD(pnl)}
      </td>
      <td className="px-4 py-3 text-gray-600 hidden sm:table-cell">
        {formatDate(trade.opened_at)}
      </td>
      <td className="px-4 py-3 text-gray-600 hidden md:table-cell">
        {formatDate(trade.resolved_at)}
      </td>
    </tr>
  )
}

function DirectionBadge({ direction }: { direction: 'buy' | 'sell' }) {
  return (
    <span
      className={[
        'inline-flex items-center justify-center w-12 rounded text-xs font-semibold py-0.5',
        direction === 'buy' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700',
      ].join(' ')}
    >
      {direction.toUpperCase()}
    </span>
  )
}

function OutcomeBadge({
  status,
  outcome,
}: {
  status: 'open' | 'closed'
  outcome: 'win' | 'loss' | null
}) {
  if (status === 'open') {
    return (
      <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800">
        pending
      </span>
    )
  }
  if (outcome === 'win') {
    return (
      <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-green-100 text-green-700">
        win
      </span>
    )
  }
  return (
    <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium bg-red-100 text-red-700">
      loss
    </span>
  )
}

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
          ? 'bg-blue-600 border-blue-600 text-white'
          : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
      ].join(' ')}
      aria-pressed={active}
    >
      {children}
    </button>
  )
}

function TableSkeleton() {
  return (
    <div className="p-4 space-y-2">
      {Array.from({ length: 5 }).map((_, i) => (
        <div key={i} className="h-10 rounded bg-gray-100 animate-pulse" />
      ))}
    </div>
  )
}
