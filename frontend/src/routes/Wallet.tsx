import { useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, extractErrorMessage, extractFieldErrors } from '../lib/api'
import { formatUSD } from '../lib/format'
import { useToast } from '../components/Toast'
import type {
  FundRequest,
  FundRequestType,
  MeResponse,
  RequestStatus,
  Transaction,
  TransactionType,
} from '../types'

function formatDate(iso: string): string {
  return new Date(iso).toLocaleString()
}

const TYPE_LABELS: Record<TransactionType, string> = {
  admin_credit: 'Admin credit',
  admin_debit: 'Admin debit',
  trade_stake: 'Trade stake',
  trade_payout: 'Trade payout',
  deposit_approved: 'Deposit',
  withdrawal_approved: 'Withdrawal',
}

export default function Wallet() {
  const meQuery = useQuery({
    queryKey: ['me'],
    queryFn: async () => (await api.get<MeResponse>('/me')).data,
  })

  const txQuery = useQuery({
    queryKey: ['transactions'],
    queryFn: async () =>
      (await api.get<{ data: Transaction[] }>('/transactions')).data.data,
  })

  const requestsQuery = useQuery({
    queryKey: ['fund-requests'],
    queryFn: async () =>
      (await api.get<{ data: FundRequest[] }>('/fund-requests')).data.data,
  })

  const deposits = (requestsQuery.data ?? []).filter((r) => r.type === 'deposit')
  const withdrawals = (requestsQuery.data ?? []).filter((r) => r.type === 'withdrawal')

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-gray-900">Wallet</h1>
          <p className="text-sm text-gray-500 mt-1">
            Funds, transactions, and pending requests.
          </p>
        </div>
        <div className="text-right">
          <div className="text-xs text-gray-500 uppercase tracking-wide">
            Available balance
          </div>
          <div className="text-2xl font-semibold tabular-nums text-gray-900">
            {meQuery.data
              ? formatUSD(Number(meQuery.data.available_balance))
              : meQuery.isLoading ? '…' : '—'}
          </div>
        </div>
      </header>

      <TransactionsCard query={txQuery} />

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <RequestForm
          type="deposit"
          title="Request a deposit"
          submitLabel="Submit deposit"
          help="An admin will review and approve before funds reach your wallet."
        />
        <RequestForm
          type="withdrawal"
          title="Request a withdrawal"
          submitLabel="Submit withdrawal"
          help="Available balance must cover the amount when you submit and when an admin approves."
        />
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <RequestsList
          title="Past deposit requests"
          loading={requestsQuery.isLoading}
          error={requestsQuery.isError ? extractErrorMessage(requestsQuery.error) : null}
          rows={deposits}
        />
        <RequestsList
          title="Past withdrawal requests"
          loading={requestsQuery.isLoading}
          error={requestsQuery.isError ? extractErrorMessage(requestsQuery.error) : null}
          rows={withdrawals}
        />
      </div>
    </div>
  )
}

// ---------------- Transactions ----------------

function TransactionsCard({
  query,
}: {
  query: ReturnType<typeof useQuery<Transaction[]>>
}) {
  const rows = query.data ?? []
  const errorMessage = query.isError ? extractErrorMessage(query.error) : null

  return (
    <section className="bg-white rounded-lg shadow overflow-hidden">
      <header className="px-4 sm:px-6 py-4 border-b border-gray-200">
        <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
          Ledger transactions
        </h2>
      </header>

      {query.isLoading ? (
        <div className="p-4 space-y-2">
          {Array.from({ length: 4 }).map((_, i) => (
            <div key={i} className="h-10 rounded bg-gray-100 animate-pulse" />
          ))}
        </div>
      ) : errorMessage ? (
        <div className="p-6 text-sm text-red-600" role="alert">
          {errorMessage}
        </div>
      ) : rows.length === 0 ? (
        <div className="p-8 text-center text-sm text-gray-500">
          No transactions yet.
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="min-w-full text-sm">
            <thead className="bg-gray-50 text-gray-500 uppercase text-xs tracking-wide">
              <tr>
                <th className="px-4 py-3 text-left font-medium">When</th>
                <th className="px-4 py-3 text-left font-medium">Type</th>
                <th className="px-4 py-3 text-left font-medium hidden md:table-cell">Note</th>
                <th className="px-4 py-3 text-right font-medium">Amount</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-200">
              {rows.map((tx) => {
                const amount = Number(tx.amount)
                return (
                  <tr key={tx.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 text-gray-600">{formatDate(tx.created_at)}</td>
                    <td className="px-4 py-3 text-gray-900">{TYPE_LABELS[tx.type] ?? tx.type}</td>
                    <td className="px-4 py-3 text-gray-600 hidden md:table-cell truncate max-w-xs">
                      {tx.note ?? ''}
                    </td>
                    <td
                      className={[
                        'px-4 py-3 text-right tabular-nums font-medium',
                        amount > 0 ? 'text-green-700' : amount < 0 ? 'text-red-700' : 'text-gray-900',
                      ].join(' ')}
                    >
                      {amount > 0 ? '+' : ''}
                      {formatUSD(amount)}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}

// ---------------- Deposit / Withdrawal forms ----------------

interface RequestFormProps {
  type: FundRequestType
  title: string
  submitLabel: string
  help: string
}

function RequestForm({ type, title, submitLabel, help }: RequestFormProps) {
  const queryClient = useQueryClient()
  const toast = useToast()
  const [amount, setAmount] = useState('')
  const [note, setNote] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  const submit = useMutation({
    mutationFn: async (input: { type: FundRequestType; amount: number; note: string | null }) => {
      const response = await api.post<{ data: FundRequest }>('/fund-requests', input)
      return response.data.data
    },
    onSuccess: (row) => {
      setAmount('')
      setNote('')
      setFieldErrors({})
      toast.show(`Submitted ${type} request for ${formatUSD(Number(row.amount))}.`)
      queryClient.invalidateQueries({ queryKey: ['fund-requests'] })
    },
    onError: (err) => {
      const fields = extractFieldErrors(err)
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        toast.show(extractErrorMessage(err, `Could not submit ${type} request.`), 'error')
      }
    },
  })

  function handleSubmit(e: FormEvent) {
    e.preventDefault()
    const parsed = Number(amount)
    if (!Number.isFinite(parsed) || parsed <= 0) {
      setFieldErrors({ amount: 'Enter an amount greater than zero.' })
      return
    }
    submit.mutate({ type, amount: parsed, note: note.trim() ? note.trim() : null })
  }

  return (
    <section className="bg-white rounded-lg shadow p-4 sm:p-6 space-y-4">
      <header>
        <h2 className="text-lg font-semibold text-gray-900">{title}</h2>
        <p className="text-sm text-gray-500 mt-1">{help}</p>
      </header>

      <form onSubmit={handleSubmit} className="space-y-3" noValidate>
        <div>
          <label htmlFor={`${type}-amount`} className="block text-sm font-medium text-gray-700">
            Amount (USD)
          </label>
          <input
            id={`${type}-amount`}
            type="number"
            inputMode="decimal"
            min="0.01"
            step="0.01"
            value={amount}
            onChange={(e) => setAmount(e.target.value)}
            aria-invalid={Boolean(fieldErrors.amount)}
            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          />
          {fieldErrors.amount && (
            <p className="mt-1 text-sm text-red-600">{fieldErrors.amount}</p>
          )}
        </div>

        <div>
          <label htmlFor={`${type}-note`} className="block text-sm font-medium text-gray-700">
            Note <span className="text-gray-400 font-normal">(optional)</span>
          </label>
          <input
            id={`${type}-note`}
            type="text"
            maxLength={1000}
            value={note}
            onChange={(e) => setNote(e.target.value)}
            className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
          />
          {fieldErrors.note && (
            <p className="mt-1 text-sm text-red-600">{fieldErrors.note}</p>
          )}
        </div>

        <button
          type="submit"
          disabled={submit.isPending || !amount}
          className="w-full inline-flex justify-center items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          {submit.isPending ? 'Submitting…' : submitLabel}
        </button>
      </form>
    </section>
  )
}

// ---------------- Past requests list ----------------

interface RequestsListProps {
  title: string
  loading: boolean
  error: string | null
  rows: FundRequest[]
}

function RequestsList({ title, loading, error, rows }: RequestsListProps) {
  return (
    <section className="bg-white rounded-lg shadow">
      <header className="px-4 sm:px-6 py-4 border-b border-gray-200">
        <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
          {title}
        </h2>
      </header>
      {loading ? (
        <div className="p-4 space-y-2">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-10 rounded bg-gray-100 animate-pulse" />
          ))}
        </div>
      ) : error ? (
        <div className="p-6 text-sm text-red-600" role="alert">
          {error}
        </div>
      ) : rows.length === 0 ? (
        <div className="p-8 text-center text-sm text-gray-500">
          No requests yet.
        </div>
      ) : (
        <ul className="divide-y divide-gray-200">
          {rows.map((row) => (
            <li key={row.id} className="px-4 sm:px-6 py-3 flex items-center justify-between gap-3">
              <div className="min-w-0">
                <div className="font-medium text-gray-900 tabular-nums">
                  {formatUSD(Number(row.amount))}
                </div>
                <div className="text-xs text-gray-500 truncate">
                  {formatDate(row.created_at)}
                  {row.note ? ` · ${row.note}` : ''}
                </div>
              </div>
              <StatusBadge status={row.status} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

function StatusBadge({ status }: { status: RequestStatus }) {
  const styles: Record<RequestStatus, string> = {
    pending: 'bg-amber-100 text-amber-800',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700',
  }
  return (
    <span
      className={[
        'inline-flex items-center rounded px-2 py-0.5 text-xs font-medium',
        styles[status],
      ].join(' ')}
    >
      {status}
    </span>
  )
}
