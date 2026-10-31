import app from 'flarum/admin/app';
import { call } from '../common/api';
import type { Product, Purchase } from '../common/api';

export interface UnlockType {
  key: string;
  label: string;
  icon: string;
}

export interface ProviderMode {
  clientId: string;
  hasSecret: boolean;
  connected: boolean;
}

export interface PayPalStatus {
  mode: 'sandbox' | 'live';
  lastWebhookAt: string | null;
  sandbox: ProviderMode;
  live: ProviderMode;
}

export interface StripeStatus {
  mode: 'test' | 'live';
  lastWebhookAt: string | null;
  test: { keyHint: string | null; connected: boolean; sellers?: boolean };
  live: { keyHint: string | null; connected: boolean; sellers?: boolean };
}

export interface Overview {
  paypal: PayPalStatus;
  stripe: StripeStatus;
  btcpay: CryptoStatus['btcpay'];
  nowpayments: CryptoStatus['nowpayments'];
  webhookUrl: string;
  stripeWebhookUrl: string;
  checklist: { provider: boolean; product: boolean; sale: boolean; scheduler: boolean };
  stats: {
    activeMembers: number;
    activeSubscriptions: number;
    mrr: Record<string, number>;
    thisMonth: { currency: string; totalCents: number; count: number }[];
    failedWebhooks: number;
  };
  unlockTypes: UnlockType[];
}

export interface WebhookRow {
  id: number;
  provider: string;
  eventId: string;
  type: string;
  receivedAt: string;
  processedAt: string | null;
  error: string | null;
}

export const overview = () => call<{ data: Overview }>('GET', '/admin/overview');
export const products = () => call<{ data: Product[] }>('GET', '/admin/products');
export const saveProduct = (data: Record<string, unknown>, id?: number) =>
  call<{ data: Product }>(id ? 'PATCH' : 'POST', id ? `/admin/products/${id}` : '/admin/products', data);
export const deleteProduct = (id: number) => call<void>('DELETE', `/admin/products/${id}`);
export const orderProducts = (ids: number[]) => call<void>('POST', '/admin/products/order', { ids });
export const unlockTypes = () => call<{ data: UnlockType[] }>('GET', '/admin/unlocks');
export const searchUnlocks = (type: string, q: string) =>
  call<{ data: { id: number; label: string }[] }>('GET', '/admin/unlocks', undefined, { type, q });
export const purchases = (params: Record<string, unknown>) =>
  call<{ data: Purchase[]; meta: { next: number | null } }>('GET', '/admin/purchases', undefined, params);
export const purchaseAction = (id: number, action: 'cancel' | 'end' | 'refund') =>
  call<{ data: Purchase }>('POST', `/admin/purchases/${id}/${action}`);
export const grant = (data: { user: string; productId: number; days: number; note?: string }) =>
  call<{ data: Purchase }>('POST', '/admin/grants', data);
export const webhooks = (failed = false) => call<{ data: WebhookRow[] }>('GET', '/admin/webhooks', undefined, failed ? { failed: '1' } : undefined);
export const replay = (id: number) => call<{ data: WebhookRow[] }>('POST', `/admin/webhooks/${id}/replay`);
export const connectPayPal = (data: { mode: string; clientId?: string; secret?: string; use?: boolean }) =>
  call<{ data: { ok: boolean; mode: string; step?: string; error?: string; status: PayPalStatus } }>('POST', '/admin/paypal', data);
export const connectStripe = (data: { key?: string; mode?: string; use?: boolean; sellers?: boolean }) =>
  call<{ data: { ok: boolean; mode: string; step?: string; error?: string; status: StripeStatus } }>('POST', '/admin/stripe', data);

export interface Coupon {
  id: number;
  code: string;
  kind: 'percent' | 'amount';
  value: number;
  currency: string | null;
  productIds: number[];
  maxUses: number | null;
  usedCount: number;
  expiresAt: string | null;
}

export const coupons = () => call<{ data: Coupon[] }>('GET', '/admin/coupons');
export const createCoupon = (body: Record<string, unknown>) => call<{ data: Coupon }>('POST', '/admin/coupons', body);
export const deleteCoupon = (id: number) => call<void>('DELETE', `/admin/coupons/${id}`);

export interface CryptoStatus {
  btcpay: { connected: boolean; url: string | null; storeId: string | null; lastWebhookAt: string | null };
  nowpayments: { connected: boolean; sandbox: boolean; keyHint: string | null; lastWebhookAt: string | null };
}

export const connectBtcpay = (url: string) => call<{ data: { url: string } }>('POST', '/admin/btcpay', { url });
export const connectNowpayments = (body: { apiKey: string; ipnSecret: string; sandbox: boolean }) =>
  call<{ data: CryptoStatus }>('POST', '/admin/nowpayments', body);
export const disconnectCrypto = (key: 'btcpay' | 'nowpayments') => call<{ data: CryptoStatus }>('POST', `/admin/${key}/disconnect`);

/** Where an ad can go, in the order the admin sees them. Mirrors Model\Ad::LOCATIONS. */
export const LOCATIONS = [
  'header',
  'below_header',
  'sidebar',
  'between_discussions',
  'after_first_post',
  'between_posts',
  'discussion_sidebar',
  'above_footer',
  'footer',
] as const;

export interface AdRow {
  id: number;
  name: string;
  location: string;
  kind: 'image' | 'html';
  imageUrl: string | null;
  imageWidth: number | null;
  imageHeight: number | null;
  linkUrl: string | null;
  altText: string | null;
  html: string | null;
  audience: 'everyone' | 'guests' | 'members';
  active: boolean;
  startsAt: string | null;
  endsAt: string | null;
  impressions: number;
  clicks: number;
}

export const ads = () => call<{ data: AdRow[] }>('GET', '/admin/ads');
export const saveAd = (body: Record<string, unknown>, id?: number) =>
  call<{ data: AdRow }>(id ? 'PATCH' : 'POST', id ? `/admin/ads/${id}` : '/admin/ads', body);
export const deleteAd = (id: number) => call<void>('DELETE', `/admin/ads/${id}`);
export const uploadAdImage = (id: number, file: File) => {
  const body = new FormData();
  body.append('image', file);
  return app.request<{ data: AdRow }>({
    method: 'POST',
    url: app.forum.attribute('apiUrl') + `/kiosk/admin/ads/${id}/image`,
    body,
    serialize: (raw: FormData) => raw,
  });
};

export type Listing = import('../common/api').Listing;
export const listings = (all = false) => call<{ data: Listing[] }>('GET', '/admin/listings', undefined, all ? { review: 'all' } : undefined);
export const reviewListing = (id: number, action: 'approve' | 'reject', note = '') =>
  call<{ data: Listing[] }>('POST', `/admin/listings/${id}`, { action, note });
