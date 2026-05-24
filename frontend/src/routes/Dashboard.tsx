import { useMemo, type ReactNode } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, extractErrorMessage } from '../lib/api'
import { formatUSD } from '../lib/format'
import type { MeResponse, Trade } from '../types'

export default function Dashboard() {
  const meQuery = useQuery({
    queryKey: ['me'],
    queryFn: async () => (await api.get<MeResponse>('/me')).data,
  })

  const tradesQuery = useQuery({
    queryKey: ['trades'],
    queryFn: async () => (await api.get<{ data: Trade[] }>('/trades')).data.data,
  })

  const stats = useMemo(() => {
    const trades = tradesQuery.data ?? []
    const open = trades.filter((t) => t.status === 'open')
    const closed = trades.filter((t) => t.status === 'closed')
    const totalPnl = closed.reduce((sum, t) => sum + Number(t.pnl ?? 0), 0)
    return {
      openCount: open.length,
      closedCount: closed.length,
      totalPnl,
    }
  }, [tradesQuery.data])

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">
          Welcome back{meQuery.data ? `, ${meQuery.data.user.name}` : ''}
        </h1>
        <p className="text-sm text-gray-500 mt-1">
          Here&apos;s a snapshot of your trading account.
        </p>
      </header>

      {/* Balance — large hero card */}
      <StatCard
        title="Current balance"
        loading={meQuery.isLoading}
        error={meQuery.isError ? extractErrorMessage(meQuery.error) : null}
      >
        <div className="text-4xl sm:text-5xl font-semibold text-gray-900 tabular-nums">
          {meQuery.data ? formatUSD(Number(meQuery.data.balance)) : '—'}
        </div>
        {meQuery.data && Number(meQuery.data.available_balance) !== Number(meQuery.data.balance) && (
          <p className="text-sm text-gray-500 mt-2">
            Available: {formatUSD(Number(meQuery.data.available_balance))}
          </p>
        )}
      </StatCard>

      {/* Trade summary grid */}
      <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard
          title="Open trades"
          loading={tradesQuery.isLoading}
          error={tradesQuery.isError ? extractErrorMessage(tradesQuery.error) : null}
        >
          <div className="text-3xl font-semibold text-gray-900 tabular-nums">
            {stats.openCount}
          </div>
        </StatCard>

        <StatCard
          title="Closed trades"
          loading={tradesQuery.isLoading}
          error={tradesQuery.isError ? extractErrorMessage(tradesQuery.error) : null}
        >
          <div className="text-3xl font-semibold text-gray-900 tabular-nums">
            {stats.closedCount}
          </div>
        </StatCard>

        <StatCard
          title="Total P&L"
          loading={tradesQuery.isLoading}
          error={tradesQuery.isError ? extractErrorMessage(tradesQuery.error) : null}
        >
          <div
            className={[
              'text-3xl font-semibold tabular-nums',
              stats.totalPnl > 0 && 'text-green-600',
              stats.totalPnl < 0 && 'text-red-600',
              stats.totalPnl === 0 && 'text-gray-900',
            ]
              .filter(Boolean)
              .join(' ')}
          >
            {stats.totalPnl > 0 ? '+' : ''}
            {formatUSD(stats.totalPnl)}
          </div>
          <p className="text-xs text-gray-500 mt-1">Across {stats.closedCount} closed trades</p>
        </StatCard>
      </div>
    </div>
  )
}

interface StatCardProps {
  title: string
  loading: boolean
  error: string | null
  children: ReactNode
}

function StatCard({ title, loading, error, children }: StatCardProps) {
  return (
    <section className="bg-white rounded-lg shadow p-5 sm:p-6">
      <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
        {title}
      </h3>
      <div className="mt-3">
        {loading ? (
          <Skeleton />
        ) : error ? (
          <ErrorMessage message={error} />
        ) : (
          children
        )}
      </div>
    </section>
  )
}

function Skeleton() {
  return (
    <div className="h-10 w-32 rounded bg-gray-200 animate-pulse" />
  )
}

function ErrorMessage({ message }: { message: string }) {
  return (
    <div className="text-sm text-red-600" role="alert">
      {message}
    </div>
  )
}
