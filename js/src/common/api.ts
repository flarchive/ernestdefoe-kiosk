import app from 'flarum/common/app';

export type Kind = 'one_off' | 'fixed_term' | 'recurring' | 'custom';
export type Interval = 'day' | 'week' | 'month' | 'year';

export interface Price {
  id: number;
  amountCents: number;
  currency: string;
  kind: Kind;
  interval: Interval | null;
  intervalCount: number;
  termDays: number | null;
  trialDays: number;
  seats: number;
}

export interface Unlock {
  type: string;
  targetId: number;
  label: string | null;
  url: string | null;
  missing?: boolean;
  teaser?: boolean | null;
}

export interface Product {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  benefits: string[];
  icon: string | null;
  color: string | null;
  hidden: boolean;
  unlocks: Unlock[];
  prices: Price[];
  /** Why the viewer cannot buy it now, or null. */
  refusal: null | 'unavailable' | 'sold_out' | 'login' | 'not_eligible';
  // Admin only:
  active?: boolean;
  buyerGroupIds?: number[];
  stock?: number | null;
  soldCount?: number;
  availableFrom?: string | null;
  availableUntil?: string | null;
  benefitsText?: string;
}

export interface Payment {
  id: number;
  amountCents: number;
  currency: string;
  status: 'completed' | 'refunded' | 'reversed';
  refundedCents: number;
  createdAt: string;
}

export interface Purchase {
  id: number;
  number: string;
  productId: number | null;
  productName: string;
  amountCents: number;
  currency: string;
  kind: Kind;
  interval: Interval | null;
  intervalCount: number;
  termDays: number | null;
  provider: string;
  mode: string | null;
  status: 'pending' | 'active' | 'canceled' | 'ended' | 'expired' | 'refunded';
  live: boolean;
  periodStart: string | null;
  periodEnd: string | null;
  cancelAtPeriodEnd: boolean;
  createdAt: string;
  paidAt: string | null;
  canCancel: boolean;
  priceId: number | null;
  /** owner: theirs; giver: a gift they gave; seat: a team seat they were given. */
  role: 'owner' | 'giver' | 'seat';
  giftedBy: Person | null;
  giftedTo: Person | null;
  /** Who holds it, when that isn't the viewer. */
  owner: Person | null;
  couponCode: string | null;
  seats: number;
  seatHolders: Person[];
  unlocks: Unlock[];
  payments: Payment[];
  // Admin only:
  note?: string | null;
  providerRef?: string | null;
  user?: { id: number; username: string; displayName: string; avatarUrl: string | null; email: string } | null;
}

export interface Person {
  id: number;
  username: string;
  displayName: string;
}

export interface StoreMeta {
  owned: number[];
  providers: { key: string; mode: string; recurring: boolean }[];
  termsUrl: string | null;
}

const url = (path: string) => app.forum.attribute<string>('apiUrl') + '/kiosk' + path;

export function call<T>(method: string, path: string, body?: object, params?: Record<string, unknown>): Promise<T> {
  return app.request<T>({ method, url: url(path), body: body ? { data: body } : undefined, params });
}

export const store = (lookup?: { type: string; id: number }) => call<{ data: Product[]; meta: StoreMeta }>('GET', '/store', undefined, lookup);
export interface CheckoutOptions {
  amount?: string;
  context?: { type: string; id: number };
  coupon?: string;
  giftTo?: string;
}

export const checkout = (priceId: number, provider: string, options: CheckoutOptions = {}) =>
  call<{ data: { url: string; number: string } }>('POST', '/checkout', { priceId, provider, ...options });
export const bump = (discussionId: number) => call<{ data: { bumpedAt: string; bumpsLeft: number } }>('POST', `/discussions/${discussionId}/bump`);
export const tips = (discussionId: number) =>
  call<{ data: { canTip: boolean; raised: { currency: string; totalCents: number; backers: number }[] } }>(
    'GET',
    `/discussions/${discussionId}/tips`
  );
export const myPurchases = () => call<{ data: Purchase[] }>('GET', '/purchases');
export const purchase = (number: string) => call<{ data: Purchase }>('GET', `/purchases/${number}`);
export const confirm = (number: string) => call<{ data: Purchase }>('POST', `/purchases/${number}/confirm`);
export const cancel = (number: string) => call<{ data: Purchase }>('POST', `/purchases/${number}/cancel`);
export const switchPlan = (number: string, priceId: number) =>
  call<{ data: Purchase | { url: string } }>('POST', `/purchases/${number}/switch`, { priceId });
export const addSeat = (number: string, username: string) => call<{ data: Purchase }>('POST', `/purchases/${number}/seats`, { username });
export const removeSeat = (number: string, userId: number) => call<{ data: Purchase }>('DELETE', `/purchases/${number}/seats/${userId}`);
export const checkCoupon = (priceId: number, code: string) =>
  call<{ data: { amountCents: number; currency: string } }>('POST', '/coupons/check', { priceId, code });
