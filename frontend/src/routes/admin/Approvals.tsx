import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, extractErrorMessage } from '../../lib/api'
import { formatUSD } from '../../lib/format'
import { useToast } from '../../components/Toast'
import { useConfirm } from '../../components/Confirm'
import type { AdminDepositRow, AdminWithdrawalRow } from '../../types'

interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString()
}

export default function Approvals() {
  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">Approvals</h1>
        <p className="text-sm text-gray-500 mt-1">
          Pending deposit and withdrawal requests awaiting review.
        </p>
      </header>

      <PendingDeposits />
      <PendingWithdrawals />
    </div>
  )
}

// ---------------- Deposits ----------------

function PendingDeposits() {
  const query = useQuery({
    queryKey: ['admin', 'deposits', { status: 'pending' }],
    queryFn: async () =>
      (await api.get<Paginated<AdminDepositRow>>('/admin/deposits', { params: { status: 'pending' } })).data,
  })

  return (
    <ApprovalCard
      title="Pending deposits"
      query={query}
      kind="deposit"
    />
  )
}

function PendingWithdrawals() {
  const query = useQuery({
    queryKey: ['admin', 'withdrawals', { status: 'pending' }],
    queryFn: async () =>
      (await api.get<Paginated<AdminWithdrawalRow>>('/admin/withdrawals', { params: { status: 'pending' } })).data,
  })

  return (
    <ApprovalCard
      title="Pending withdrawals"
      query={query}
      kind="withdrawal"
    />
  )
}

// ---------------- Card ----------------

interface ApprovalCardProps {
  title: string
  query: ReturnType<typeof useQuery<Paginated<AdminDepositRow | AdminWithdrawalRow>>>
  kind: 'deposit' | 'withdrawal'
}

function ApprovalCard({ title, query, kind }: ApprovalCardProps) {
  const rows = query.data?.data ?? []
  const errorMessage = query.isError ? extractErrorMessage(query.error) : null

  return (
    <section className="bg-white rounded-lg shadow overflow-hidden">
      <header className="px-4 sm:px-6 py-4 border-b border-gray-200 flex items-center justify-between">
        <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
          {title}
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
          No pending {kind === 'deposit' ? 'deposits' : 'withdrawals'}.
        </div>
      ) : (
        <ul className="divide-y divide-gray-200">
          {rows.map((row) => (
            <ApprovalRow key={row.id} row={row} kind={kind} />
          ))}
        </ul>
      )}
    </section>
  )
}

function ApprovalRow({
  row,
  kind,
}: {
  row: AdminDepositRow | AdminWithdrawalRow
  kind: 'deposit' | 'withdrawal'
}) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const confirm = useConfirm()

  const endpoint = (action: 'approve' | 'reject') =>
    `/admin/${kind === 'deposit' ? 'deposits' : 'withdrawals'}/${row.id}/${action}`

  const act = useMutation({
    mutationFn: async (action: 'approve' | 'reject') => (await api.post(endpoint(action))).data,
    onSuccess: (_data, action) => {
      const verbed = action === 'approve' ? 'Approved' : 'Rejected'
      toast.show(`${verbed} ${kind} of ${formatUSD(Number(row.amount))} for ${row.user?.name ?? `user #${row.user_id}`}.`)
      queryClient.invalidateQueries({ queryKey: ['admin', kind === 'deposit' ? 'deposits' : 'withdrawals'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'stats'] })
      queryClient.invalidateQueries({ queryKey: ['admin', 'audit-logs'] })
    },
    onError: (err) => {
      toast.show(extractErrorMessage(err, `Could not update ${kind} request.`), 'error')
    },
  })

  // Approving a withdrawal moves real money OUT, so it's destructive.
  // Approving a deposit just credits the user's balance — friendly.
  // Rejecting anything is destructive (final).
  async function doAction(action: 'approve' | 'reject') {
    const isDestructive = action === 'reject' || (action === 'approve' && kind === 'withdrawal')
    if (isDestructive) {
      const ok = await confirm({
        title:
          action === 'reject'
            ? `Reject ${kind} of ${formatUSD(Number(row.amount))}?`
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
          <div className="flex items-baseline gap-3">
            <span className="font-medium text-gray-900 tabular-nums">
              {formatUSD(Number(row.amount))}
            </span>
            <span className="text-sm text-gray-600 truncate">
              {row.user?.name ?? `user #${row.user_id}`}
              {row.user?.email ? ` · ${row.user.email}` : ''}
            </span>
          </div>
          <div className="text-xs text-gray-500 mt-0.5">
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
