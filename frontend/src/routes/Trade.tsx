import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  CartesianGrid,
  Line,
  LineChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts'
import { api, extractErrorMessage, extractFieldErrors } from '../lib/api'
import { formatUSD } from '../lib/format'
import { useToast } from '../components/Toast'
import type { CurrencyPair, MeResponse, Trade } from '../types'

/**
 * Approximate starting spot price per pair so the chart looks realistic.
 * Outcomes are admin-resolved — this is purely cosmetic.
 */
function seedPrice(symbol: string): number {
  switch (symbol) {
    case 'EURUSD': return 1.085
    case 'GBPUSD': return 1.270
    case 'USDJPY': return 149.5
    case 'AUDUSD': return 0.662
    case 'USDCAD': return 1.358
    default: return 1.0
  }
}

function timeLabel(): string {
  return new Date().toLocaleTimeString(undefined, {
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  })
}

// ---------- page ----------

export default function TradePage() {
  const queryClient = useQueryClient()
  const toast = useToast()

  const pairsQuery = useQuery({
    queryKey: ['pairs'],
    queryFn: async () => (await api.get<{ data: CurrencyPair[] }>('/pairs')).data.data,
    staleTime: 5 * 60 * 1000,
  })

  const meQuery = useQuery({
    queryKey: ['me'],
    queryFn: async () => (await api.get<MeResponse>('/me')).data,
  })

  const openTradesQuery = useQuery({
    queryKey: ['trades', { status: 'open' }],
    queryFn: async () =>
      (await api.get<{ data: Trade[] }>('/trades', { params: { status: 'open' } })).data.data,
  })

  const [selectedPairId, setSelectedPairId] = useState<number | null>(null)
  const [direction, setDirection] = useState<'buy' | 'sell'>('buy')
  const [stake, setStake] = useState('')
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})

  // Default to the first pair as soon as we have a list.
  useEffect(() => {
    if (selectedPairId === null && pairsQuery.data && pairsQuery.data.length > 0) {
      setSelectedPairId(pairsQuery.data[0].id)
    }
  }, [pairsQuery.data, selectedPairId])

  const selectedPair = useMemo(
    () => pairsQuery.data?.find((p) => p.id === selectedPairId) ?? null,
    [pairsQuery.data, selectedPairId],
  )

  // ---------- simulated price (random walk, reset per pair) ----------
  const [series, setSeries] = useState<Array<{ t: string; price: number }>>([])

  useEffect(() => {
    if (!selectedPair) {
      setSeries([])
      return
    }
    const seed = seedPrice(selectedPair.symbol)
    setSeries([{ t: timeLabel(), price: seed }])

    const id = window.setInterval(() => {
      setSeries((prev) => {
        const last = prev[prev.length - 1]?.price ?? seed
        const drift = (Math.random() - 0.5) * last * 0.001
        const next = Math.max(0, last + drift)
        const arr = [...prev, { t: timeLabel(), price: Number(next.toFixed(5)) }]
        return arr.length > 60 ? arr.slice(-60) : arr
      })
    }, 1000)

    return () => window.clearInterval(id)
  }, [selectedPair])

  // ---------- ticket math ----------
  const parsedStake = useMemo(() => {
    const n = Number(stake)
    return Number.isFinite(n) && n > 0 ? n : null
  }, [stake])

  const available = meQuery.data ? Number(meQuery.data.available_balance) : null
  const afterStake = available !== null && parsedStake !== null ? available - parsedStake : available
  const stakeExceedsBalance = available !== null && parsedStake !== null && parsedStake > available

  // ---------- submit ----------
  const openTrade = useMutation({
    mutationFn: async (input: {
      currency_pair_id: number
      direction: 'buy' | 'sell'
      stake: number
    }) => (await api.post<{ data: Trade }>('/trades', input)).data.data,
    onSuccess: (trade) => {
      setStake('')
      setFieldErrors({})
      toast.show(
        `Opened ${trade.direction.toUpperCase()} ${trade.currency_pair?.symbol ?? `pair #${trade.currency_pair_id}`} for ${formatUSD(Number(trade.stake))}.`,
      )
      // Anything keyed by 'trades' (incl. open-only) and 'me' (for fresh balance).
      queryClient.invalidateQueries({ queryKey: ['trades'] })
      queryClient.invalidateQueries({ queryKey: ['me'] })
    },
    onError: (err) => {
      const fields = extractFieldErrors(err)
      setFieldErrors(fields)
      if (Object.keys(fields).length === 0) {
        toast.show(extractErrorMessage(err, 'Could not open trade.'), 'error')
      }
    },
  })

  function handleSubmit(e: FormEvent) {
    e.preventDefault()
    if (!selectedPair || parsedStake === null) return
    setFieldErrors({})
    openTrade.mutate({
      currency_pair_id: selectedPair.id,
      direction,
      stake: parsedStake,
    })
  }

  const submitDisabled =
    !selectedPair ||
    parsedStake === null ||
    stakeExceedsBalance ||
    openTrade.isPending

  return (
    <div className="space-y-6">
      <header>
        <h1 className="text-2xl font-semibold text-gray-900">Trade</h1>
        <p className="text-sm text-gray-500 mt-1">
          Choose a pair, set your stake, and open a position.
        </p>
      </header>

      {/* Pair selection */}
      <PairList
        pairs={pairsQuery.data}
        loading={pairsQuery.isLoading}
        error={pairsQuery.isError ? extractErrorMessage(pairsQuery.error) : null}
        selectedId={selectedPairId}
        onSelect={setSelectedPairId}
      />

      {/* Chart + Ticket */}
      <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <section className="lg:col-span-2 bg-white rounded-lg shadow p-4 sm:p-6">
          <div className="flex items-center justify-between mb-3">
            <div>
              <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
                Live price (simulated)
              </h2>
              <p className="text-lg font-semibold text-gray-900">
                {selectedPair?.symbol ?? '—'}
              </p>
            </div>
            <div className="text-right">
              <div className="text-xs text-gray-500">Last</div>
              <div className="text-lg font-semibold tabular-nums text-gray-900">
                {series.length > 0
                  ? series[series.length - 1].price.toFixed(selectedPair?.symbol === 'USDJPY' ? 3 : 5)
                  : '—'}
              </div>
            </div>
          </div>
          <div className="h-64 sm:h-72">
            {selectedPair ? (
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={series} margin={{ left: 4, right: 4, top: 6, bottom: 0 }}>
                  <CartesianGrid strokeDasharray="3 3" stroke="#e5e7eb" />
                  <XAxis dataKey="t" minTickGap={40} tick={{ fontSize: 11 }} />
                  <YAxis
                    domain={['auto', 'auto']}
                    tickFormatter={(v: number) =>
                      v.toFixed(selectedPair.symbol === 'USDJPY' ? 2 : 4)
                    }
                    width={60}
                    tick={{ fontSize: 11 }}
                  />
                  <Tooltip
                    contentStyle={{ fontSize: 12 }}
                    formatter={(v) => [Number(v).toFixed(5), 'Price']}
                  />
                  <Line
                    type="monotone"
                    dataKey="price"
                    stroke="#2563eb"
                    strokeWidth={2}
                    dot={false}
                    isAnimationActive={false}
                  />
                </LineChart>
              </ResponsiveContainer>
            ) : (
              <div className="h-full flex items-center justify-center text-gray-400 text-sm">
                Select a pair to see the chart
              </div>
            )}
          </div>
        </section>

        <section className="bg-white rounded-lg shadow p-4 sm:p-6">
          <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide">
            Trade ticket
          </h2>

          <form onSubmit={handleSubmit} className="mt-4 space-y-4" noValidate>
            <div>
              <span className="block text-sm font-medium text-gray-700 mb-1">Direction</span>
              <div className="grid grid-cols-2 gap-2">
                <button
                  type="button"
                  onClick={() => setDirection('buy')}
                  className={[
                    'rounded-md py-2 text-sm font-medium border',
                    direction === 'buy'
                      ? 'bg-green-600 border-green-600 text-white'
                      : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                  ].join(' ')}
                >
                  Buy
                </button>
                <button
                  type="button"
                  onClick={() => setDirection('sell')}
                  className={[
                    'rounded-md py-2 text-sm font-medium border',
                    direction === 'sell'
                      ? 'bg-red-600 border-red-600 text-white'
                      : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50',
                  ].join(' ')}
                >
                  Sell
                </button>
              </div>
            </div>

            <div>
              <label htmlFor="stake" className="block text-sm font-medium text-gray-700">
                Stake (USD)
              </label>
              <input
                id="stake"
                type="number"
                inputMode="decimal"
                min="0.01"
                step="0.01"
                value={stake}
                onChange={(e) => setStake(e.target.value)}
                aria-invalid={Boolean(fieldErrors.stake)}
                aria-describedby={fieldErrors.stake ? 'stake-error' : undefined}
                className="mt-1 block w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
              />
              {fieldErrors.stake ? (
                <p id="stake-error" className="mt-1 text-sm text-red-600">
                  {fieldErrors.stake}
                </p>
              ) : stakeExceedsBalance ? (
                <p className="mt-1 text-sm text-red-600">
                  Stake exceeds your available balance.
                </p>
              ) : null}
            </div>

            <dl className="rounded bg-gray-50 px-3 py-2 text-sm space-y-1">
              <div className="flex justify-between">
                <dt className="text-gray-600">Available</dt>
                <dd className="tabular-nums">
                  {available !== null ? formatUSD(available) : meQuery.isLoading ? '…' : '—'}
                </dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-gray-600">After this stake</dt>
                <dd
                  className={[
                    'tabular-nums',
                    afterStake !== null && afterStake < 0 ? 'text-red-600' : 'text-gray-900',
                  ].join(' ')}
                >
                  {afterStake !== null ? formatUSD(afterStake) : '—'}
                </dd>
              </div>
            </dl>

            <button
              type="submit"
              disabled={submitDisabled}
              className="w-full inline-flex justify-center items-center rounded-md bg-blue-600 px-4 py-2 text-sm font-medium text-white shadow hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {openTrade.isPending
                ? 'Opening…'
                : selectedPair
                  ? `${direction === 'buy' ? 'Buy' : 'Sell'} ${selectedPair.symbol}`
                  : 'Select a pair'}
            </button>
          </form>
        </section>
      </div>

      {/* Open trades */}
      <OpenTrades
        trades={openTradesQuery.data}
        loading={openTradesQuery.isLoading}
        error={openTradesQuery.isError ? extractErrorMessage(openTradesQuery.error) : null}
      />
    </div>
  )
}

// ---------- subcomponents ----------

interface PairListProps {
  pairs: CurrencyPair[] | undefined
  loading: boolean
  error: string | null
  selectedId: number | null
  onSelect: (id: number) => void
}

function PairList({ pairs, loading, error, selectedId, onSelect }: PairListProps) {
  return (
    <section className="bg-white rounded-lg shadow p-4 sm:p-5">
      <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide mb-3">
        Currency pairs
      </h2>
      {loading ? (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="h-12 rounded bg-gray-100 animate-pulse" />
          ))}
        </div>
      ) : error ? (
        <p className="text-sm text-red-600" role="alert">{error}</p>
      ) : !pairs || pairs.length === 0 ? (
        <p className="text-sm text-gray-500">No active pairs available.</p>
      ) : (
        <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
          {pairs.map((pair) => {
            const active = pair.id === selectedId
            return (
              <button
                key={pair.id}
                type="button"
                onClick={() => onSelect(pair.id)}
                className={[
                  'rounded-md border px-3 py-2 text-sm text-left transition-colors',
                  active
                    ? 'border-blue-500 bg-blue-50 text-blue-700'
                    : 'border-gray-200 hover:border-gray-300 hover:bg-gray-50 text-gray-800',
                ].join(' ')}
                aria-pressed={active}
              >
                <div className="font-semibold tabular-nums">{pair.symbol}</div>
                <div className="text-xs text-gray-500">
                  {pair.base} / {pair.quote}
                </div>
              </button>
            )
          })}
        </div>
      )}
    </section>
  )
}

interface OpenTradesProps {
  trades: Trade[] | undefined
  loading: boolean
  error: string | null
}

function OpenTrades({ trades, loading, error }: OpenTradesProps) {
  return (
    <section className="bg-white rounded-lg shadow p-4 sm:p-6">
      <h2 className="text-sm font-medium text-gray-500 uppercase tracking-wide mb-3">
        Open trades
      </h2>
      {loading ? (
        <div className="space-y-2">
          {Array.from({ length: 3 }).map((_, i) => (
            <div key={i} className="h-12 rounded bg-gray-100 animate-pulse" />
          ))}
        </div>
      ) : error ? (
        <p className="text-sm text-red-600" role="alert">{error}</p>
      ) : !trades || trades.length === 0 ? (
        <p className="text-sm text-gray-500">No open trades right now.</p>
      ) : (
        <ul className="divide-y divide-gray-200">
          {trades.map((trade) => (
            <li
              key={trade.id}
              className="flex items-center justify-between py-3 gap-3"
            >
              <div className="flex items-center gap-3 min-w-0">
                <span
                  className={[
                    'inline-flex items-center justify-center w-12 h-7 rounded text-xs font-semibold',
                    trade.direction === 'buy'
                      ? 'bg-green-100 text-green-700'
                      : 'bg-red-100 text-red-700',
                  ].join(' ')}
                >
                  {trade.direction.toUpperCase()}
                </span>
                <div className="min-w-0">
                  <div className="font-medium text-gray-900">
                    {trade.currency_pair?.symbol ?? `pair #${trade.currency_pair_id}`}
                  </div>
                  <div className="text-xs text-gray-500 truncate">
                    Opened {new Date(trade.opened_at).toLocaleString()}
                  </div>
                </div>
              </div>
              <div className="text-right">
                <div className="text-sm font-medium tabular-nums text-gray-900">
                  {formatUSD(Number(trade.stake))}
                </div>
                <div className="text-xs text-amber-600">Awaiting result</div>
              </div>
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}
