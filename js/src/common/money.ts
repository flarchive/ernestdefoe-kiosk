import app from 'flarum/common/app';
import extractText from 'flarum/common/utils/extractText';
import { t } from './t';
import type { Kind, Interval } from './api';

const ZERO_DECIMAL = ['JPY', 'HUF', 'TWD', 'KRW', 'CLP', 'VND', 'ISK', 'UGX', 'XAF', 'XOF'];

/** 1999, "USD" → "$19.99" in the reader's locale. */
export function money(cents: number, currency: string): string {
  const decimals = ZERO_DECIMAL.includes(currency) ? 0 : 2;
  const value = decimals ? cents / 100 : cents;

  try {
    return new Intl.NumberFormat(app.translator.getLocale() || undefined, { style: 'currency', currency, minimumFractionDigits: decimals }).format(
      value
    );
  } catch {
    return `${value.toFixed(decimals)} ${currency}`;
  }
}

/** "$5 / month", "$50 / year", "$10 for 30 days", "$120 once" — plain words, no billing jargon. */
export function priceLabel(p: {
  amountCents: number;
  currency: string;
  kind: Kind;
  interval: Interval | null;
  intervalCount: number;
  termDays: number | null;
}): string {
  const amount = money(p.amountCents, p.currency);

  if (p.kind === 'recurring') {
    return extractText(
      t(p.intervalCount > 1 ? `lib.price.every_n_${p.interval}` : `lib.price.per_${p.interval}`, { amount, count: p.intervalCount })
    );
  }
  if (p.kind === 'fixed_term') return extractText(t('lib.price.for_days', { amount, count: p.termDays || 0 }));
  if (p.kind === 'custom') return extractText(t('lib.price.custom', { amount }));

  return extractText(t('lib.price.once', { amount }));
}

/** A date in the reader's locale, or '' for none. */
export function date(iso: string | null | undefined): string {
  if (!iso) return '';
  const d = new Date(iso);

  return isNaN(d.getTime()) ? '' : d.toLocaleDateString(app.translator.getLocale() || undefined, { year: 'numeric', month: 'long', day: 'numeric' });
}

/** Every currency Kiosk takes (Money::CURRENCIES). */
export const CURRENCIES = [
  'USD',
  'EUR',
  'GBP',
  'CAD',
  'AUD',
  'NZD',
  'CHF',
  'SEK',
  'NOK',
  'DKK',
  'PLN',
  'CZK',
  'HUF',
  'JPY',
  'MXN',
  'BRL',
  'SGD',
  'HKD',
  'TWD',
  'ILS',
  'PHP',
  'THB',
  'MYR',
];
