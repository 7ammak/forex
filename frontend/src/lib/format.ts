/**
 * Format a number-ish value as a USD currency string.
 * Accepts string/number/null and returns `fallback` for anything non-finite.
 */
export function formatUSD(
  value: number | string | null | undefined,
  fallback = '—',
): string {
  if (value === null || value === undefined || value === '') return fallback
  const n = typeof value === 'number' ? value : Number(value)
  if (!Number.isFinite(n)) return fallback
  return n.toLocaleString(undefined, {
    style: 'currency',
    currency: 'USD',
  })
}

/** Same number with a leading + on positive values, used for P&L. */
export function formatSignedUSD(
  value: number | string | null | undefined,
  fallback = '—',
): string {
  if (value === null || value === undefined || value === '') return fallback
  const n = typeof value === 'number' ? value : Number(value)
  if (!Number.isFinite(n)) return fallback
  return `${n > 0 ? '+' : ''}${formatUSD(n)}`
}
