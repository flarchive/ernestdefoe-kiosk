import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { money, priceLabel } from '../../common/money';
import { goTo, selling } from '../../common/api';
import type { Listing, Sale, Selling, SellerProviderStatus } from '../../common/api';

/** The fields each provider asks a member for. Stripe and BTCPay send them to the provider's own page instead. */
const FIELDS: Record<string, string[]> = {
  // PayPal: just an email by default; their own app (keys) for subscriptions.
  paypal: ['email'],
  paypalApp: ['clientId', 'secret'],
  btcpay: ['url'],
  nowpayments: ['apiKey', 'ipnSecret'],
  stripe: [],
};

/**
 * /store/selling — a member's own shop: where they're paid, what they sell,
 * and what they've sold. Only for members the forum lets sell.
 */
export default class SellingPage extends Page {
  data: Selling | null = null;
  failed = false;
  busy: string | null = null;
  /** Typed into a provider's form, by provider then field. */
  fields: Record<string, Record<string, string>> = {};
  terms = '';
  /** PayPal with their own app's keys instead of an email. */
  paypalApp = false;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const title = extractText(t('forum.selling.title'));
    app.setTitle(title);
    app.history.push('kiosk.selling', title);

    const back = m.route.param('connected');
    if (back) {
      app.alerts.show(
        { type: back === 'failed' ? 'error' : 'success' },
        t(back === 'failed' ? 'forum.selling.connect_failed' : 'forum.selling.connected')
      );
    }

    // ?sell=depot-file:12 — from Depot's "Sell this file": open a new listing for it.
    const [type, id] = String(m.route.param('sell') || '').split(':');
    this.load(selling.get()).then(() => {
      if (type && Number(id) && this.data?.providers.some((p) => p.status === 'connected')) {
        this.edit(null, { type, targetId: Number(id) });
      }
    });
  }

  load(request: Promise<{ data: Selling; meta?: { redirect: string | null } }>, busy: string | null = null) {
    this.busy = busy;
    return request.then(
      ({ data, meta }) => {
        if (meta?.redirect) {
          goTo(meta.redirect);
          return;
        }
        this.data = data;
        this.terms = data.terms;
        this.busy = null;
        m.redraw();
      },
      () => {
        this.failed = !this.data;
        this.busy = null;
        m.redraw();
      }
    );
  }

  view() {
    return (
      <PageStructure className="KioskSellingPage" sidebar={() => <IndexSidebar />}>
        <div className="KioskPageHead">
          <h2 className="KioskPageHead-title">{t('forum.selling.title')}</h2>
          <LinkButton className="Button" icon="fas fa-store" href={app.route('kiosk.store')}>
            {t('forum.store.title')}
          </LinkButton>
        </div>

        {this.data ? (
          this.content(this.data)
        ) : this.failed ? (
          <p className="KioskEmpty">{t('forum.selling.unavailable')}</p>
        ) : (
          <LoadingIndicator display="block" />
        )}
      </PageStructure>
    );
  }

  content(data: Selling): Mithril.Children {
    const connected = data.providers.some((p) => p.status === 'connected');

    return [
      <section className="KioskSelling-section">
        <h3>{t('forum.selling.accounts')}</h3>
        <p className="helpText">{t('forum.selling.accounts_help')}</p>
        <div className="KioskSelling-accounts">{data.providers.map((p) => this.account(p))}</div>
        {data.feePercent > 0 && data.providers.some((p) => p.key === 'stripe') ? (
          <p className="helpText">{t('forum.selling.fee', { percent: data.feePercent })}</p>
        ) : null}
      </section>,

      <section className="KioskSelling-section">
        <h3>{t('forum.selling.terms')}</h3>
        <p className="helpText">{t('forum.selling.terms_help')}</p>
        <textarea
          className="FormControl"
          rows={3}
          maxlength={2000}
          value={this.terms}
          oninput={(e: InputEvent) => (this.terms = (e.target as HTMLTextAreaElement).value)}
        />
        <Button
          className="Button KioskSelling-save"
          loading={this.busy === 'terms'}
          disabled={this.terms === data.terms}
          onclick={() => this.load(selling.terms(this.terms), 'terms')}
        >
          {t('forum.selling.save_terms')}
        </Button>
      </section>,

      <section className="KioskSelling-section">
        <div className="KioskSelling-head">
          <h3>{t('forum.selling.listings')}</h3>
          <Button className="Button Button--primary" icon="fas fa-plus" disabled={!connected} onclick={() => this.edit(null)}>
            {t('forum.selling.new_listing')}
          </Button>
        </div>
        {!connected ? <p className="helpText">{t('forum.selling.connect_first')}</p> : null}
        {data.trusted ? null : <p className="helpText">{t('forum.selling.review_help')}</p>}
        {data.listings.length ? (
          <ul className="KioskPurchases">{data.listings.map((l) => this.listing(l))}</ul>
        ) : (
          <p className="KioskEmpty">{t('forum.selling.no_listings')}</p>
        )}
      </section>,

      <section className="KioskSelling-section">
        <h3>{t('forum.selling.sales')}</h3>
        {data.sales.length ? (
          <ul className="KioskPurchases">{data.sales.map((s) => this.sale(s))}</ul>
        ) : (
          <p className="KioskEmpty">{t('forum.selling.no_sales')}</p>
        )}
      </section>,
    ];
  }

  account(p: SellerProviderStatus): Mithril.Children {
    const app_ = p.key === 'paypal' && this.paypalApp;
    const fields = FIELDS[app_ ? 'paypalApp' : p.key] || [];
    const values = (this.fields[app_ ? 'paypalApp' : p.key] ??= {});

    return (
      <div className={classList('KioskPurchase', 'KioskSelling-account', { 'KioskPurchase--live': p.status === 'connected' })}>
        <div className="KioskPurchase-main">
          <h4 className="KioskPurchase-name">{t('lib.providers.' + p.key)}</h4>
          <span className={classList('KioskStatus', { 'KioskStatus--live': p.status === 'connected' })}>
            {t('forum.selling.status.' + (p.status || 'none'))}
          </span>
        </div>
        {p.hint ? <p className="KioskPurchase-terms">{p.hint}</p> : null}
        {p.status === 'connected' ? (
          p.byEmail ? (
            <p className="helpText">{t('forum.selling.help.paypal_email_limits')}</p>
          ) : null
        ) : (
          <p className="helpText">{t('forum.selling.help.' + (app_ ? 'paypal_app' : p.key))}</p>
        )}

        {p.status === 'connected' ? null : (
          <div className="KioskSelling-fields">
            {fields.map((f) => (
              <input
                className="FormControl"
                type={f === 'url' ? 'url' : f === 'email' ? 'email' : f === 'clientId' ? 'text' : 'password'}
                autocomplete="off"
                placeholder={extractText(t('forum.selling.field.' + f))}
                aria-label={extractText(t('forum.selling.field.' + f))}
                value={values[f] || ''}
                oninput={(e: InputEvent) => (values[f] = (e.target as HTMLInputElement).value)}
              />
            ))}
          </div>
        )}

        {p.key === 'paypal' && p.status !== 'connected' ? (
          <Button className="Button Button--link KioskSelling-switch" onclick={() => (this.paypalApp = !this.paypalApp)}>
            {t(this.paypalApp ? 'forum.selling.paypal_use_email' : 'forum.selling.paypal_use_app')}
          </Button>
        ) : null}

        <div className="KioskSelling-actions">
          {p.status === 'connected' ? null : (
            <Button
              className="Button Button--primary"
              loading={this.busy === p.key}
              disabled={fields.some((f) => !(values[f] || '').trim())}
              onclick={() => this.load(selling.connect(p.key, values), p.key)}
            >
              {t(p.key === 'stripe' || p.key === 'btcpay' ? 'forum.selling.continue_to' : 'forum.selling.connect', {
                provider: t('lib.providers.' + p.key),
              })}
            </Button>
          )}
          {p.status ? (
            <Button className="Button Button--link" onclick={() => this.removeAccount(p)}>
              {t('forum.selling.remove')}
            </Button>
          ) : null}
        </div>
      </div>
    );
  }

  removeAccount(p: SellerProviderStatus) {
    if (!confirm(extractText(t('forum.selling.remove_confirm', { provider: t('lib.providers.' + p.key) })))) return;
    this.busy = p.key;
    selling.remove(p.key).then(() => this.load(selling.get()));
  }

  listing(l: Listing): Mithril.Children {
    const state = l.review === 'pending' ? 'pending' : l.review === 'rejected' ? 'rejected' : l.active ? 'on_sale' : 'off';

    return (
      <li className={classList('KioskPurchase', { 'KioskPurchase--live': state === 'on_sale' })}>
        <div className="KioskPurchase-main">
          <h4 className="KioskPurchase-name">{l.name}</h4>
          <span className={classList('KioskStatus', { 'KioskStatus--live': state === 'on_sale' })}>{t('forum.selling.state.' + state)}</span>
        </div>
        <p className="KioskPurchase-terms">
          {l.prices.map((p) => priceLabel(p)).join(' · ')}
          {' · '}
          {t('forum.selling.sold_count', { count: l.sold })}
        </p>
        {l.review === 'rejected' && l.reviewNote ? <p className="KioskCard-refusal">{l.reviewNote}</p> : null}
        <div className="KioskSelling-actions">
          <Button className="Button" icon="fas fa-pen" onclick={() => this.edit(l)}>
            {t('forum.selling.edit')}
          </Button>
          <Button className="Button Button--link" onclick={() => this.removeListing(l)}>
            {t('forum.selling.delete')}
          </Button>
        </div>
      </li>
    );
  }

  removeListing(l: Listing) {
    if (!confirm(extractText(t('forum.selling.delete_confirm', { name: l.name })))) return;
    selling.remove_listing(l.id).then(() => this.load(selling.get()));
  }

  edit(listing: Listing | null, preset: { type: string; targetId: number } | null = null) {
    app.modal.show(() => import('../components/ListingModal'), {
      listing,
      preset,
      onsave: (data: Selling) => {
        this.data = data;
        m.redraw();
      },
    });
  }

  sale(s: Sale): Mithril.Children {
    return (
      <li className={classList('KioskPurchase', { 'KioskPurchase--live': s.status === 'active' })}>
        <div className="KioskPurchase-main">
          <h4 className="KioskPurchase-name">{s.productName}</h4>
          <span className={classList('KioskStatus', 'KioskStatus--' + s.status)}>{t('forum.purchases.status.' + s.status)}</span>
        </div>
        <p className="KioskPurchase-terms">
          {money(s.amountCents, s.currency)}
          {s.feeCents ? [' · ', t('forum.selling.fee_taken', { amount: money(s.feeCents, s.currency) })] : null}
          {' · '}
          {s.buyer ? <a href={app.route('user', { username: s.buyer.username })}>{s.buyer.displayName}</a> : t('forum.selling.deleted_buyer')}
          {' · '}
          {humanTime(new Date(s.createdAt))}
          {' · '}
          {t('lib.providers.' + s.provider)}
        </p>
        <small className="KioskPurchase-number">{t('forum.purchases.number', { number: s.number })}</small>
        {s.refundable ? (
          <div className="KioskSelling-actions">
            <Button className="Button Button--link" loading={this.busy === s.number} onclick={() => this.refund(s)}>
              {t('forum.selling.refund')}
            </Button>
          </div>
        ) : null}
      </li>
    );
  }

  refund(s: Sale) {
    if (!confirm(extractText(t('forum.selling.refund_confirm', { amount: money(s.amountCents, s.currency) })))) return;
    this.load(selling.refund(s.number), s.number);
  }
}
