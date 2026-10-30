import app from 'flarum/admin/app';
import ExtensionPage from 'flarum/admin/components/ExtensionPage';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import Select from 'flarum/common/components/Select';
import extractText from 'flarum/common/utils/extractText';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { date, money, priceLabel } from '../common/money';
import type { Product, Purchase } from '../common/api';
import * as api from './api';
import type { Overview, PayPalStatus, StripeStatus, WebhookRow } from './api';
import ProductModal from './ProductModal';
import GrantModal from './GrantModal';
import CouponsTab from './CouponsTab';
import CryptoCards from './CryptoCards';
import Sortable from './sortable';
import { TEMPLATES } from './templates';

type Tab = 'overview' | 'products' | 'purchases' | 'coupons' | 'payments' | 'webhooks' | 'settings';
const TABS: Tab[] = ['overview', 'products', 'purchases', 'coupons', 'payments', 'webhooks', 'settings'];

const PAYPAL_APPS = 'https://developer.paypal.com/dashboard/applications';
const STRIPE_KEYS = 'https://dashboard.stripe.com/apikeys';

/**
 * Kiosk's admin: a setup checklist until the store is ready, then the
 * numbers; products; purchases (grant, cancel, end, refund); PayPal; the
 * webhook log; settings. Every number is read from local rows, so the page
 * opens instantly and still works while PayPal is down.
 */
export default class KioskPage extends ExtensionPage {
  tab: Tab = 'overview';
  overview: Overview | null = null;
  products: Product[] | null = null;
  purchases: Purchase[] | null = null;
  purchasesNext: number | null = null;
  webhookRows: WebhookRow[] | null = null;
  filter = { q: '', status: '' };
  busy: string | null = null;
  sortable = new Sortable<Product>(
    () => this.products || [],
    () => this.saveOrder()
  );

  // PayPal form
  mode: 'sandbox' | 'live' = 'sandbox';
  clientId = '';
  secret = '';
  connectResult: { ok: boolean; step?: string; error?: string } | null = null;

  // Stripe form
  stripeKey = '';
  stripeResult: { ok: boolean; step?: string; error?: string; mode?: string } | null = null;

  oninit(vnode: Mithril.Vnode<any, this>) {
    super.oninit(vnode);
    const tab = m.route.param('tab') as Tab;
    if (TABS.includes(tab)) this.tab = tab;
    this.loadOverview();
    this.loadTab();
  }

  loadOverview() {
    return api.overview().then(({ data }) => {
      this.overview = data;
      if (!this.clientId) {
        this.mode = data.paypal.mode;
        this.clientId = data.paypal[this.mode].clientId;
      }
      m.redraw();
    });
  }

  loadTab() {
    if (this.tab === 'products' || this.tab === 'overview' || this.tab === 'coupons') this.loadProducts();
    if (this.tab === 'purchases') this.loadPurchases();
    if (this.tab === 'webhooks') this.loadWebhooks();
  }

  loadProducts() {
    return api.products().then(({ data }) => {
      this.products = data;
      m.redraw();
    });
  }

  loadPurchases(more = false) {
    const params: Record<string, unknown> = { q: this.filter.q, status: this.filter.status };
    if (more && this.purchasesNext) params.offset = this.purchasesNext;
    return api.purchases(params).then(({ data, meta }) => {
      this.purchases = more ? [...(this.purchases || []), ...data] : data;
      this.purchasesNext = meta.next;
      m.redraw();
    });
  }

  loadWebhooks() {
    return api.webhooks().then(({ data }) => {
      this.webhookRows = data;
      m.redraw();
    });
  }

  content() {
    return (
      <div className="KioskAdmin">
        <div className="container">
          <nav className="KioskTabs" role="tablist">
            {TABS.map((tab) => (
              <Button
                className={classList('Button Button--flat', { active: this.tab === tab })}
                role="tab"
                aria-selected={this.tab === tab}
                onclick={() => {
                  this.tab = tab;
                  this.loadTab();
                }}
              >
                {t('admin.tabs.' + tab)}
                {tab === 'webhooks' && this.overview?.stats.failedWebhooks ? (
                  <span className="KioskBadge">{this.overview.stats.failedWebhooks}</span>
                ) : null}
              </Button>
            ))}
          </nav>
          <div className="KioskAdmin-body">{this.overview ? this.tabBody() : <LoadingIndicator />}</div>
        </div>
      </div>
    );
  }

  tabBody(): Mithril.Children {
    switch (this.tab) {
      case 'products':
        return this.productsTab();
      case 'purchases':
        return this.purchasesTab();
      case 'coupons':
        return <CouponsTab products={this.products} />;
      case 'payments':
        return this.paymentsTab();
      case 'webhooks':
        return this.webhooksTab();
      case 'settings':
        return this.settingsTab();
      default:
        return this.overviewTab();
    }
  }

  // ─── Overview ────────────────────────────────────────────────────────────

  overviewTab(): Mithril.Children {
    const o = this.overview!;
    const done = o.checklist.provider && o.checklist.product && o.checklist.sale && o.checklist.scheduler;

    return (
      <div>
        {done ? null : this.checklist(o)}
        <div className="KioskStats">
          {this.stat('fas fa-users', o.stats.activeMembers, t('admin.overview.active_members'))}
          {this.stat('fas fa-rotate', o.stats.activeSubscriptions, t('admin.overview.active_subscriptions'))}
          {this.stat(
            'fas fa-chart-line',
            Object.entries(o.stats.mrr)
              .map(([c, v]) => money(v, c))
              .join(' · ') || money(0, 'USD'),
            t('admin.overview.mrr')
          )}
          {this.stat(
            'fas fa-sack-dollar',
            o.stats.thisMonth.map((r) => money(r.totalCents, r.currency)).join(' · ') || money(0, 'USD'),
            t('admin.overview.this_month')
          )}
        </div>
        {o.stats.failedWebhooks ? (
          <p className="KioskWarning">
            <Icon name="fas fa-triangle-exclamation" /> {t('admin.overview.failed_webhooks', { count: o.stats.failedWebhooks })}{' '}
            <Button className="Button Button--link" onclick={() => this.go('webhooks')}>
              {t('admin.overview.view_webhooks')}
            </Button>
          </p>
        ) : null}
      </div>
    );
  }

  checklist(o: Overview): Mithril.Children {
    const step = (key: string, ok: boolean, body: Mithril.Children) => (
      <li className={classList('KioskStep', { 'KioskStep--done': ok })}>
        <Icon name={ok ? 'fas fa-circle-check' : 'far fa-circle'} className="KioskStep-icon" />
        <div className="KioskStep-body">
          <h4>{t('admin.setup.' + key + '.title')}</h4>
          {ok ? null : body}
        </div>
      </li>
    );
    const types = o.unlockTypes.map((x) => x.key);

    return (
      <section className="KioskSetup">
        <h3>{t('admin.setup.title')}</h3>
        <p className="helpText">{t('admin.setup.intro')}</p>
        <ol className="KioskSteps">
          {step(
            'provider',
            o.checklist.provider,
            <div>
              <p>{t('admin.setup.provider.text')}</p>
              <Button className="Button Button--primary" onclick={() => this.go('payments')}>
                {t('admin.setup.provider.button')}
              </Button>
            </div>
          )}
          {step(
            'product',
            o.checklist.product,
            <div>
              <p>{t('admin.setup.product.text')}</p>
              <div className="KioskTemplates">
                {TEMPLATES.filter((tpl) => types.includes(tpl.type)).map((tpl) => (
                  <button type="button" className="KioskTemplate" onclick={() => this.newProduct(tpl.key)}>
                    <Icon name={tpl.icon} />
                    <strong>{t('admin.templates.' + tpl.key + '.name')}</strong>
                    <span>{t('admin.templates.' + tpl.key + '.text')}</span>
                  </button>
                ))}
              </div>
            </div>
          )}
          {step(
            'sale',
            o.checklist.sale,
            <div>
              <p>{t(o.paypal.mode === 'sandbox' ? 'admin.setup.sale.text_sandbox' : 'admin.setup.sale.text_live')}</p>
              <a className="Button" href={app.forum.attribute<string>('baseUrl') + '/store'} target="_blank" rel="noopener">
                {t('admin.setup.sale.button')}
              </a>
            </div>
          )}
          {step(
            'scheduler',
            o.checklist.scheduler,
            <div>
              <p>{t('admin.setup.scheduler.text')}</p>
              <code className="KioskCode">* * * * * cd /path/to/flarum && php flarum schedule:run</code>
            </div>
          )}
        </ol>
      </section>
    );
  }

  stat(icon: string, value: Mithril.Children, label: Mithril.Children) {
    return (
      <div className="KioskStat">
        <Icon name={icon} />
        <strong>{value}</strong>
        <span>{label}</span>
      </div>
    );
  }

  go(tab: Tab) {
    this.tab = tab;
    this.loadTab();
  }

  // ─── Products ────────────────────────────────────────────────────────────

  productsTab(): Mithril.Children {
    if (!this.products) return <LoadingIndicator />;

    return (
      <div>
        <div className="KioskToolbar">
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.newProduct()}>
            {t('admin.products.new')}
          </Button>
        </div>
        {this.products.length ? (
          <ul className="KioskProductList" data-sortable>
            {this.products.map((p, i) => (
              <li
                {...this.sortable.row(i)}
                className={classList('KioskProductRow', this.sortable.row(i).className, { 'KioskProductRow--off': !p.active })}
              >
                <span {...this.sortable.handle(i)} aria-label={extractText(t('admin.products.drag'))}>
                  <Icon name="fas fa-grip-vertical" />
                </span>
                <Icon name={p.icon || 'fas fa-star'} className="KioskProductRow-icon" />
                <div className="KioskProductRow-main">
                  <strong>{p.name}</strong>
                  <span className="KioskProductRow-meta">
                    {p.unlocks.map((u) => u.label || t('admin.products.missing')).join(', ')} · {p.prices.map((x) => priceLabel(x)).join(' / ')}
                  </span>
                </div>
                <span className="KioskProductRow-badges">
                  {!p.active ? <span className="KioskTag">{t('admin.products.off_sale')}</span> : null}
                  {p.hidden ? <span className="KioskTag">{t('admin.products.hidden')}</span> : null}
                  <span className="KioskTag">{t('admin.products.sold', { count: p.soldCount || 0 })}</span>
                </span>
                <Button className="Button" onclick={() => this.editProduct(p)}>
                  {t('admin.products.edit')}
                </Button>
                <Button
                  className="Button Button--icon"
                  icon="fas fa-trash"
                  aria-label={extractText(t('admin.products.delete'))}
                  onclick={() => this.deleteProduct(p)}
                />
              </li>
            ))}
          </ul>
        ) : (
          <p className="KioskEmpty">{t('admin.products.empty')}</p>
        )}
      </div>
    );
  }

  newProduct(template?: string) {
    const tpl = TEMPLATES.find((x) => x.key === template);
    const currency = String(app.data.settings['ernestdefoe-kiosk.currency'] || 'USD');

    app.modal.show(ProductModal, {
      types: this.overview!.unlockTypes,
      preset: tpl
        ? {
            name: extractText(t('admin.templates.' + tpl.key + '.name')),
            icon: tpl.productIcon,
            pickType: tpl.type,
            prices: tpl.prices.map((p) => ({ ...p, currency }) as any),
          }
        : undefined,
      onsaved: () => {
        this.loadProducts();
        this.loadOverview();
      },
    });
  }

  editProduct(product: Product) {
    app.modal.show(ProductModal, { product, types: this.overview!.unlockTypes, onsaved: () => this.loadProducts() });
  }

  deleteProduct(product: Product) {
    if (!confirm(extractText(t('admin.products.delete_confirm', { name: product.name })))) return;
    api.deleteProduct(product.id).then(() => this.loadProducts());
  }

  saveOrder() {
    if (this.products) api.orderProducts(this.products.map((p) => p.id));
  }

  // ─── Purchases ───────────────────────────────────────────────────────────

  purchasesTab(): Mithril.Children {
    const statuses: Record<string, string> = { '': extractText(t('admin.purchases.all')) };
    ['active', 'canceled', 'ended', 'refunded', 'pending', 'expired'].forEach((s) => (statuses[s] = extractText(t('lib.status.' + s))));

    return (
      <div>
        <div className="KioskToolbar">
          <input
            className="FormControl KioskToolbar-search"
            type="search"
            placeholder={extractText(t('admin.purchases.search'))}
            value={this.filter.q}
            oninput={(e: InputEvent) => (this.filter.q = (e.target as HTMLInputElement).value)}
            onkeydown={(e: KeyboardEvent) => {
              if (e.key === 'Enter') this.loadPurchases();
            }}
          />
          <Select
            options={statuses}
            value={this.filter.status}
            onchange={(v: string) => {
              this.filter.status = v;
              this.loadPurchases();
            }}
          />
          <Button className="Button" icon="fas fa-gift" onclick={() => this.grant()}>
            {t('admin.purchases.grant')}
          </Button>
        </div>
        {!this.purchases ? (
          <LoadingIndicator />
        ) : this.purchases.length ? (
          <div className="KioskTableWrap">
            <table className="KioskTable">
              <thead>
                <tr>
                  <th>{t('admin.purchases.member')}</th>
                  <th>{t('admin.purchases.product')}</th>
                  <th>{t('admin.purchases.status')}</th>
                  <th>{t('admin.purchases.terms')}</th>
                  <th>{t('admin.purchases.until')}</th>
                  <th />
                </tr>
              </thead>
              <tbody>{this.purchases.map((p) => this.purchaseRow(p))}</tbody>
            </table>
            {this.purchasesNext ? (
              <Button className="Button" onclick={() => this.loadPurchases(true)}>
                {t('admin.purchases.more')}
              </Button>
            ) : null}
          </div>
        ) : (
          <p className="KioskEmpty">{t('admin.purchases.empty')}</p>
        )}
      </div>
    );
  }

  purchaseRow(p: Purchase): Mithril.Children {
    const act = (action: 'cancel' | 'end' | 'refund') => {
      if (!confirm(extractText(t('admin.purchases.confirm_' + action, { name: p.user?.displayName || '', product: p.productName })))) return;
      this.busy = p.number + action;
      api.purchaseAction(p.id, action).then(
        ({ data }) => {
          this.purchases = (this.purchases || []).map((x) => (x.id === data.id ? data : x));
          this.busy = null;
          this.loadOverview();
        },
        () => {
          this.busy = null;
          m.redraw();
        }
      );
    };

    return (
      <tr>
        <td>
          {p.user ? (
            <a href={app.forum.attribute<string>('baseUrl') + '/u/' + p.user.username} target="_blank" rel="noopener">
              {p.user.displayName}
            </a>
          ) : (
            '—'
          )}
        </td>
        <td>
          {p.productName}
          <small className="KioskTable-sub">{p.number}</small>
        </td>
        <td>
          <span className={'KioskStatus KioskStatus--' + p.status}>{t('lib.status.' + p.status)}</span>
          {p.mode === 'sandbox' ? <span className="KioskTag">{t('admin.purchases.test')}</span> : null}
        </td>
        <td>{p.provider === 'grant' ? t('admin.purchases.granted') : priceLabel(p)}</td>
        <td>{p.periodEnd ? date(p.periodEnd) : t('admin.purchases.forever')}</td>
        <td className="KioskTable-actions">
          {p.canCancel ? (
            <Button className="Button Button--link" loading={this.busy === p.number + 'cancel'} onclick={() => act('cancel')}>
              {t('admin.purchases.cancel')}
            </Button>
          ) : null}
          {p.live ? (
            <Button className="Button Button--link" loading={this.busy === p.number + 'end'} onclick={() => act('end')}>
              {t('admin.purchases.end')}
            </Button>
          ) : null}
          {p.provider !== 'grant' && p.payments.some((x) => x.status === 'completed') ? (
            <Button className="Button Button--link" loading={this.busy === p.number + 'refund'} onclick={() => act('refund')}>
              {t('admin.purchases.refund')}
            </Button>
          ) : null}
        </td>
      </tr>
    );
  }

  grant() {
    const open = (products: Product[]) =>
      app.modal.show(GrantModal, {
        products,
        onsaved: () => {
          this.loadPurchases();
          this.loadOverview();
        },
      });
    this.products ? open(this.products) : this.loadProducts().then(() => open(this.products || []));
  }

  // ─── Payments ────────────────────────────────────────────────────────────

  paymentsTab(): Mithril.Children {
    return (
      <div className="KioskProviders">
        {this.paypalCard()}
        {this.stripeCard()}
        <CryptoCards status={{ btcpay: this.overview!.btcpay, nowpayments: this.overview!.nowpayments }} onchange={() => this.loadOverview()} />
      </div>
    );
  }

  stripeCard(): Mithril.Children {
    const s: StripeStatus = this.overview!.stripe;
    const current = s[s.mode];
    const other = s.mode === 'live' ? 'test' : 'live';
    const typed = this.stripeKey.startsWith('sk_live_') || this.stripeKey.startsWith('rk_live_') ? 'live' : this.stripeKey ? 'test' : null;

    return (
      <section className="KioskProvider">
        <header className="KioskProvider-head">
          <Icon name="fab fa-stripe" />
          <h3>Stripe</h3>
          <span className={classList('KioskStatus', current.connected ? 'KioskStatus--active' : 'KioskStatus--pending')}>
            {current.connected ? t('admin.payments.connected') : t('admin.payments.not_connected')}
          </span>
          {current.connected ? (
            s.mode === 'live' ? (
              <span className="KioskTag KioskTag--live">{t('admin.payments.live_now')}</span>
            ) : (
              <span className="KioskTag">{t('admin.payments.test_now')}</span>
            )
          ) : null}
        </header>

        <p className="helpText">{t('admin.stripe.help', { a: <a href={STRIPE_KEYS} target="_blank" rel="noopener" /> })}</p>

        <div className="Form-group">
          <label>{t('admin.stripe.key')}</label>
          <input
            className="FormControl"
            type="password"
            autocomplete="new-password"
            value={this.stripeKey}
            placeholder={current.keyHint ? extractText(t('admin.stripe.key_saved', { hint: current.keyHint })) : 'sk_test_…'}
            oninput={(e: InputEvent) => (this.stripeKey = (e.target as HTMLInputElement).value.trim())}
          />
          {typed ? <small className="KioskNote">{t('admin.stripe.detected_' + typed)}</small> : null}
        </div>

        <div className="KioskToolbar">
          <Button
            className="Button Button--primary"
            loading={this.busy === 'stripe'}
            disabled={!this.stripeKey && !current.keyHint}
            onclick={() => this.connectStripe()}
          >
            {current.connected && !this.stripeKey ? t('admin.payments.reconnect') : t('admin.payments.connect')}
          </Button>
          {s[other].connected ? (
            <Button className="Button" loading={this.busy === 'stripe-use'} onclick={() => this.connectStripe(other)}>
              {t(other === 'live' ? 'admin.payments.go_live' : 'admin.payments.back_to_test')}
            </Button>
          ) : null}
        </div>

        {this.stripeResult && !this.stripeResult.ok ? (
          <p className="KioskWarning">
            <Icon name="fas fa-triangle-exclamation" /> {t('admin.stripe.failed.' + (this.stripeResult.step || 'unreachable'))}
            {this.stripeResult.error ? <small className="KioskWarning-detail">{this.stripeResult.error}</small> : null}
          </p>
        ) : this.stripeResult?.ok ? (
          <p className="KioskSuccess">
            <Icon name="fas fa-circle-check" /> {t('admin.stripe.ok')}
          </p>
        ) : null}

        <dl className="KioskFacts">
          <dt>{t('admin.payments.webhook')}</dt>
          <dd>
            <code>{this.overview!.stripeWebhookUrl}</code>
            <small>{t('admin.stripe.webhook_help')}</small>
          </dd>
          <dt>{t('admin.stripe.last_webhook')}</dt>
          <dd>{s.lastWebhookAt ? new Date(s.lastWebhookAt).toLocaleString() : t('admin.payments.never')}</dd>
        </dl>
      </section>
    );
  }

  connectStripe(switchTo?: 'test' | 'live') {
    this.busy = switchTo ? 'stripe-use' : 'stripe';
    this.stripeResult = null;
    const data = switchTo ? { mode: switchTo, use: true } : this.stripeKey ? { key: this.stripeKey } : { mode: this.overview!.stripe.mode };

    api.connectStripe(data).then(
      ({ data }) => {
        this.stripeResult = data;
        this.overview!.stripe = data.status;
        this.stripeKey = '';
        this.busy = null;
        this.loadOverview();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }

  paypalCard(): Mithril.Children {
    const s: PayPalStatus = this.overview!.paypal;
    const mine = s[this.mode];

    return (
      <section className="KioskProvider">
        <header className="KioskProvider-head">
          <Icon name="fab fa-paypal" />
          <h3>{t('lib.providers.paypal')}</h3>
          <span className={classList('KioskStatus', mine.connected ? 'KioskStatus--active' : 'KioskStatus--pending')}>
            {mine.connected ? t('admin.payments.connected') : t('admin.payments.not_connected')}
          </span>
          {s.mode === 'live' ? (
            <span className="KioskTag KioskTag--live">{t('admin.payments.live_now')}</span>
          ) : (
            <span className="KioskTag">{t('admin.payments.test_now')}</span>
          )}
        </header>

        <div className="KioskModeSwitch" role="radiogroup">
          {(['sandbox', 'live'] as const).map((mode) => (
            <button
              type="button"
              role="radio"
              aria-checked={this.mode === mode}
              className={classList('KioskPrice', { 'KioskPrice--selected': this.mode === mode })}
              onclick={() => {
                this.mode = mode;
                this.clientId = s[mode].clientId;
                this.secret = '';
                this.connectResult = null;
              }}
            >
              {t('admin.payments.mode.' + mode)}
            </button>
          ))}
        </div>

        <p className="helpText">{t('admin.payments.help_' + this.mode, { a: <a href={PAYPAL_APPS} target="_blank" rel="noopener" /> })}</p>

        <div className="Form-group">
          <label>{t('admin.payments.client_id')}</label>
          <input
            className="FormControl"
            value={this.clientId}
            autocomplete="off"
            oninput={(e: InputEvent) => (this.clientId = (e.target as HTMLInputElement).value.trim())}
          />
        </div>
        <div className="Form-group">
          <label>{t('admin.payments.secret')}</label>
          <input
            className="FormControl"
            type="password"
            autocomplete="new-password"
            value={this.secret}
            placeholder={mine.hasSecret ? extractText(t('admin.payments.secret_saved')) : ''}
            oninput={(e: InputEvent) => (this.secret = (e.target as HTMLInputElement).value.trim())}
          />
        </div>

        <div className="KioskToolbar">
          <Button
            className="Button Button--primary"
            loading={this.busy === 'connect'}
            disabled={!this.clientId || (!this.secret && !mine.hasSecret)}
            onclick={() => this.connect(s.mode !== this.mode ? false : true)}
          >
            {mine.connected ? t('admin.payments.reconnect') : t('admin.payments.connect')}
          </Button>
          {s.mode !== this.mode && mine.connected ? (
            <Button className="Button" loading={this.busy === 'use'} onclick={() => this.connect(true)}>
              {t(this.mode === 'live' ? 'admin.payments.go_live' : 'admin.payments.back_to_test')}
            </Button>
          ) : null}
        </div>

        {this.connectResult && !this.connectResult.ok ? (
          <p className="KioskWarning">
            <Icon name="fas fa-triangle-exclamation" /> {t('admin.payments.failed.' + (this.connectResult.step || 'unreachable'))}
            {this.connectResult.error ? <small className="KioskWarning-detail">{this.connectResult.error}</small> : null}
          </p>
        ) : this.connectResult?.ok ? (
          <p className="KioskSuccess">
            <Icon name="fas fa-circle-check" /> {t('admin.payments.ok')}
          </p>
        ) : null}

        <dl className="KioskFacts">
          <dt>{t('admin.payments.webhook')}</dt>
          <dd>
            <code>{this.overview!.webhookUrl}</code>
            <small>{t('admin.payments.webhook_help')}</small>
          </dd>
          <dt>{t('admin.payments.last_webhook')}</dt>
          <dd>{s.lastWebhookAt ? new Date(s.lastWebhookAt).toLocaleString() : t('admin.payments.never')}</dd>
        </dl>
      </section>
    );
  }

  connect(use: boolean) {
    this.busy = use && this.overview!.paypal.mode !== this.mode ? 'use' : 'connect';
    this.connectResult = null;

    api.connectPayPal({ mode: this.mode, clientId: this.clientId, secret: this.secret || undefined, use }).then(
      ({ data }) => {
        this.connectResult = data;
        this.overview!.paypal = data.status;
        this.secret = '';
        this.busy = null;
        this.loadOverview();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }

  // ─── Webhooks ────────────────────────────────────────────────────────────

  webhooksTab(): Mithril.Children {
    if (!this.webhookRows) return <LoadingIndicator />;
    if (!this.webhookRows.length) return <p className="KioskEmpty">{t('admin.webhooks.empty')}</p>;

    return (
      <div className="KioskTableWrap">
        <table className="KioskTable">
          <thead>
            <tr>
              <th>{t('admin.webhooks.received')}</th>
              <th>{t('admin.webhooks.event')}</th>
              <th>{t('admin.webhooks.result')}</th>
              <th />
            </tr>
          </thead>
          <tbody>
            {this.webhookRows.map((w) => (
              <tr>
                <td>{new Date(w.receivedAt).toLocaleString()}</td>
                <td>
                  <code>{w.type}</code>
                  <small className="KioskTable-sub">{w.eventId}</small>
                </td>
                <td>
                  {w.processedAt ? (
                    <span className="KioskStatus KioskStatus--active">{t('admin.webhooks.done')}</span>
                  ) : (
                    <span className="KioskStatus KioskStatus--refunded" title={w.error || ''}>
                      {t('admin.webhooks.failed')}
                    </span>
                  )}
                  {w.error ? <small className="KioskTable-sub">{w.error}</small> : null}
                </td>
                <td>
                  {w.processedAt ? null : (
                    <Button
                      className="Button"
                      loading={this.busy === 'wh' + w.id}
                      onclick={() => {
                        this.busy = 'wh' + w.id;
                        api.replay(w.id).then(({ data }) => {
                          this.webhookRows = (this.webhookRows || []).map((x) => (x.id === w.id ? data[0] : x));
                          this.busy = null;
                          this.loadOverview();
                        });
                      }}
                    >
                      {t('admin.webhooks.replay')}
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    );
  }

  // ─── Settings ────────────────────────────────────────────────────────────

  settingsTab(): Mithril.Children {
    const currencies: Record<string, string> = {};
    ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'PLN', 'JPY', 'MXN', 'BRL', 'SGD', 'HKD'].forEach(
      (c) => (currencies[c] = c)
    );

    return (
      <div className="Form KioskSettings">
        {this.buildSettingComponent({
          setting: 'ernestdefoe-kiosk.currency',
          type: 'select',
          options: currencies,
          default: 'USD',
          label: t('admin.settings.currency'),
          help: t('admin.settings.currency_help'),
        })}
        {this.buildSettingComponent({
          setting: 'ernestdefoe-kiosk.grace_days',
          type: 'number',
          min: 0,
          max: 30,
          label: t('admin.settings.grace_days'),
          help: t('admin.settings.grace_days_help'),
        })}
        {this.buildSettingComponent({
          setting: 'ernestdefoe-kiosk.terms_url',
          type: 'url',
          label: t('admin.settings.terms_url'),
          help: t('admin.settings.terms_url_help'),
        })}
        {this.buildSettingComponent({ setting: 'ernestdefoe-kiosk.show_nav', type: 'boolean', label: t('admin.settings.show_nav') })}
        {this.buildSettingComponent({
          setting: 'ernestdefoe-kiosk.stripe_tax',
          type: 'boolean',
          label: t('admin.settings.stripe_tax'),
          help: t('admin.settings.stripe_tax_help'),
        })}
        {this.submitButton()}
      </div>
    );
  }
}
