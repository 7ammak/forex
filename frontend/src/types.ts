export interface MeResponse {
  user: {
    id: number
    name: string
    email: string
    role: 'user' | 'admin'
    status: 'active' | 'suspended'
  }
  balance: number
  available_balance: number
}

export type TransactionType =
  | 'admin_credit'
  | 'admin_debit'
  | 'trade_stake'
  | 'trade_payout'
  | 'deposit_approved'
  | 'withdrawal_approved'

export interface Transaction {
  id: number
  user_id: number
  type: TransactionType
  amount: string
  reference_type: string | null
  reference_id: number | null
  note: string | null
  created_at: string
}

export type RequestStatus = 'pending' | 'approved' | 'rejected'

export interface DepositRequest {
  id: number
  user_id: number
  amount: string
  status: RequestStatus
  reviewed_by: number | null
  note: string | null
  created_at: string
}

export interface WithdrawalRequest {
  id: number
  user_id: number
  amount: string
  status: RequestStatus
  reviewed_by: number | null
  note: string | null
  created_at: string
}

export interface AuditLogEntry {
  id: number
  actor_id: number | null
  action: string
  target_type: string | null
  target_id: number | null
  meta: Record<string, unknown> | null
  created_at: string
  actor: {
    id: number
    name: string
    email: string
  } | null
}

export interface AdminTradeRow {
  id: number
  user_id: number
  currency_pair_id: number
  direction: 'buy' | 'sell'
  stake: string
  status: 'open' | 'closed'
  outcome: 'win' | 'loss' | null
  pnl: string | null
  opened_at: string
  resolved_at: string | null
  resolved_by: number | null
  created_at: string
  user?: { id: number; name: string; email: string }
  currency_pair?: { id: number; symbol: string; base: string; quote: string }
}

export interface AdminDepositRow {
  id: number
  user_id: number
  amount: string
  status: 'pending' | 'approved' | 'rejected'
  reviewed_by: number | null
  note: string | null
  created_at: string
  user?: { id: number; name: string; email: string }
}

export interface AdminWithdrawalRow {
  id: number
  user_id: number
  amount: string
  status: 'pending' | 'approved' | 'rejected'
  reviewed_by: number | null
  note: string | null
  created_at: string
  user?: { id: number; name: string; email: string }
}

export interface CurrencyPair {
  id: number
  symbol: string
  base: string
  quote: string
  is_active: boolean
}

export interface Trade {
  id: number
  user_id: number
  currency_pair_id: number
  direction: 'buy' | 'sell'
  stake: string
  status: 'open' | 'closed'
  outcome: 'win' | 'loss' | null
  pnl: string | null
  opened_at: string
  resolved_at: string | null
  resolved_by: number | null
  created_at: string
  currency_pair?: {
    id: number
    symbol: string
    base: string
    quote: string
  }
}
