import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { api, extractErrorMessage } from '../../lib/api'
import { formatUSD } from '../../lib/format'
import type { AuditLogEntry } from '../../types'

interface AdminStats {
  total_users: number
  total_balance: number
  open_trades: number
  pending_deposits: number
  pending_withdrawals: number
}

export default function Overview() {
  const statsQuery = useQuery({
    queryKey: ['admin', 'stats'],
    queryFn: async () => (await api.get<AdminStats>('/admin/stats')).data,
  })

  const auditQuery = useQuery({
    queryKey: ['admin', 'audit-logs', { limit: 10 }],
    queryFn: async () =>
      (await api.get<{ data: AuditLogEntry[] }>('/admin/audit-logs', { params: { limit: 10 } })).data.data,
  })

  const loading = statsQuery.isLoading
  const error = statsQuery.isError ? extractErrorMessage(statsQuery.error) : null
  const stats = statsQuery.data

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">Overview</h1>
        <p className="text-sm text-gray-500 mt-1">
          Snapshot of platform activity.
        </p>
      </header>

      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <StatCard
          label="Total users"
          loading={loading}
          error={error}
          value={stats?.total_users.toLocaleString() ?? '—'}
          link={{ to: '/admin/users', label: 'Manage users →' }}
        />
        <StatCard
          label="Total platform balance"
          loading={loading}
          error={error}
          value={stats ? formatUSD(Number(stats.total_balance)) : '—'}
          tone={stats && Number(stats.total_balance) < 0 ? 'negative' : undefined}
        />
        <StatCard
          label="Open trades"
          loading={loading}
          error={error}
          value={stats?.open_trades.toLocaleString() ?? '—'}
          link={{ to: '/admin/trades', label: 'View trades →' }}
        />
        <StatCard
          label="Pending deposits"
          loading={loading}
          error={error}
          value={stats?.pending_deposits.toLocaleString() ?? '—'}
          tone={stats && stats.pending_deposits > 0 ? 'attention' : undefined}
          link={{ to: '/admin/approvals', label: 'Review approvals →' }}
        />
        <StatCard
          label="Pending withdrawals"
          loading={loading}
          error={error}
          value={stats?.pending_withdrawals.toLocaleString() ?? '—'}
          tone={stats && stats.pending_withdrawals > 0 ? 'attention' : undefined}
          link={{ to: '/admin/approvals', label: 'Review approvals →' }}
        />
      </div>

      <RecentActivity query={auditQuery} />
    </div>
  )
}

interface StatCardProps {
  label: string
  value: string
  loading: boolean
  error: string | null
  tone?: 'negative' | 'attention'
  link?: { to: string; label: string }
}

function StatCard({ label, value, loading, error, tone, link }: StatCardProps) {
  const valueClass = [
    'mt-2 text-3xl font-semibold tabular-nums',
    tone === 'negative' && 'text-red-600',
    tone === 'attention' && 'text-amber-700',
    !tone && 'text-gray-900',
  ]
    .filter(Boolean)
    .join(' ')

  return (
    <section className="bg-white rounded-lg shadow p-5 sm:p-6">
      <h3 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
        {label}
      </h3>
      {loading ? (
        <div className="mt-3 h-9 w-32 rounded bg-gray-200 animate-pulse" />
      ) : error ? (
        <p className="mt-3 text-sm text-red-600" role="alert">{error}</p>
      ) : (
        <>
          <div className={valueClass}>{value}</div>
          {link && (
            <Link
              to={link.to}
              className="inline-block mt-3 text-sm text-purple-700 hover:underline"
            >
              {link.label}
            </Link>
          )}
        </>
      )}
    </section>
  )
}

// ---------------- Recent audit activity ----------------

function RecentActivity({
  query,
}: {
  query: ReturnType<typeof useQuery<AuditLogEntry[]>>
}) {
  const entries = query.data ?? []
  const errorMessage = query.isError ? extractErrorMessage(query.error) : null

  return (
    <section className="bg-white rounded-lg shadow">
      <header className="px-4 sm:px-6 py-4 border-b border-gray-200">
        <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
          Recent admin activity
        </h2>
      </header>

      {query.isLoading ? (
        <div className="p-4 space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-10 rounded bg-gray-100 animate-pulse" />
          ))}
        </div>
      ) : errorMessage ? (
        <div className="p-6 text-sm text-red-600" role="alert">{errorMessage}</div>
      ) : entries.length === 0 ? (
        <div className="p-8 text-center text-sm text-gray-500">
          No admin actions recorded yet.
        </div>
      ) : (
        <ul className="divide-y divide-gray-200">
          {entries.map((entry) => (
            <AuditRow key={entry.id} entry={entry} />
          ))}
        </ul>
      )}
    </section>
  )
}

function AuditRow({ entry }: { entry: AuditLogEntry }) {
  const actor = entry.actor?.name ?? 'system'
  const when = new Date(entry.created_at).toLocaleString()
  const target = entry.target_type
    ? `${shortClass(entry.target_type)}#${entry.target_id ?? '?'}`
    : null

  return (
    <li className="px-4 sm:px-6 py-3 flex items-start justify-between gap-3">
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-2">
          <ActionTag action={entry.action} />
          <span className="text-sm text-gray-700">
            by <span className="font-medium text-gray-900">{actor}</span>
          </span>
          {target && (
            <span className="text-xs text-gray-500 font-mono">{target}</span>
          )}
        </div>
        {entry.meta && Object.keys(entry.meta).length > 0 && (
          <div className="text-xs text-gray-500 mt-0.5 truncate">
            {renderMeta(entry.meta)}
          </div>
        )}
      </div>
      <time className="text-xs text-gray-500 whitespace-nowrap" dateTime={entry.created_at}>
        {when}
      </time>
    </li>
  )
}

function ActionTag({ action }: { action: string }) {
  const tone = (() => {
    if (action.includes('approved') || action.includes('credit') || action.includes('reactivated')) {
      return 'bg-green-100 text-green-800'
    }
    if (action.includes('rejected') || action.includes('debit') || action.includes('suspended')) {
      return 'bg-red-100 text-red-800'
    }
    return 'bg-blue-100 text-blue-800'
  })()
  return (
    <span className={['inline-flex items-center rounded px-2 py-0.5 text-xs font-medium font-mono', tone].join(' ')}>
      {action}
    </span>
  )
}

function shortClass(fqcn: string): string {
  const parts = fqcn.split('\\')
  return parts[parts.length - 1] ?? fqcn
}

function renderMeta(meta: Record<string, unknown>): string {
  const parts: string[] = []
  for (const [key, value] of Object.entries(meta)) {
    if (value === null || value === undefined || value === '') continue
    if (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') {
      parts.push(`${key}: ${value}`)
    }
  }
  return parts.join(' · ')
}
