import app from 'flarum/forum/app';
import { checkout } from '../../common/api';
import type { CheckoutOptions, Price, Product, StoreMeta } from '../../common/api';

/**
 * Buy a price: sign in first if needed, ask the terms and the provider only
 * when there is something to ask, then hand over to the provider's page.
 */
export function buy(product: Product, price: Price, meta: StoreMeta, options: CheckoutOptions = {}): Promise<void> | null {
  if (!app.session.user) {
    // 🚨 Flarum 2 modals are lazy chunks: a static import is undefined.
    app.modal.show(() => import('flarum/forum/components/LogInModal'));
    return null;
  }

  // Only the providers that can take this price: crypto can't renew a subscription.
  const usable = { ...meta, providers: meta.providers.filter((p) => price.kind !== 'recurring' || p.recurring) };

  if (!usable.providers.length) {
    app.alerts.show({ type: 'error' }, app.translator.trans('ernestdefoe-kiosk.forum.errors.provider_unavailable'));
    return null;
  }

  if (usable.termsUrl || usable.providers.length > 1) {
    app.modal.show(() => import('./CheckoutModal'), { product, price, meta: usable, options });
    return null;
  }

  return go(price, usable.providers[0]?.key || 'paypal', options);
}

export function go(price: Price, provider: string, options: CheckoutOptions = {}): Promise<void> {
  return checkout(price.id, provider, options).then(({ data }) => {
    window.location.href = data.url;
  });
}
