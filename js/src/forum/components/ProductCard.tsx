import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import LinkButton from 'flarum/common/components/LinkButton';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { money, priceLabel } from '../../common/money';
import { checkCoupon } from '../../common/api';
import type { CheckoutOptions, Price, Product, StoreMeta } from '../../common/api';
import { buy } from './buy';

export interface ProductCardAttrs extends ComponentAttrs {
  product: Product;
  meta: StoreMeta;
  highlight?: boolean;
  /** What a tip backs: passed through to checkout. */
  context?: CheckoutOptions['context'];
}

/**
 * A product as a buyer sees it: what it is, what it opens, its prices and a
 * Buy button. Exported for other extensions (ext:ernestdefoe/kiosk/forum/components/ProductCard),
 * so every Buy button on the forum looks and works the same.
 */
export default class ProductCard extends Component<ProductCardAttrs> {
  selected: Price | null = null;
  busy = false;
  amount = '';
  /** Which extra field is open under the price. */
  extra: null | 'coupon' | 'gift' = null;
  coupon = '';
  /** The coupon's price for the selected price, once checked. */
  discounted: { priceId: number; amountCents: number; currency: string } | null = null;
  couponError = '';
  giftTo = '';

  oninit(vnode: Mithril.Vnode<ProductCardAttrs, this>) {
    super.oninit(vnode);
    this.selected = this.attrs.product.prices[0] || null;
  }

  view() {
    const { product, meta, highlight } = this.attrs;
    const owned = meta.owned.includes(product.id) && !product.prices.some((p) => p.kind === 'custom');
    const style = product.color ? { '--kiosk-accent': product.color } : undefined;

    return (
      <article
        className={classList('KioskCard', { 'KioskCard--highlight': highlight, 'KioskCard--owned': owned })}
        style={style}
        id={'kiosk-' + product.slug}
      >
        <header className="KioskCard-head">
          <span className="KioskCard-icon" aria-hidden="true">
            <Icon name={product.icon || 'fas fa-star'} />
          </span>
          <h3 className="KioskCard-name">{product.name}</h3>
        </header>

        {product.description ? <p className="KioskCard-description">{product.description}</p> : null}

        {product.benefits.length ? (
          <ul className="KioskCard-benefits">
            {product.benefits.map((b) => (
              <li>
                <Icon name="fas fa-check" />
                <span>{b}</span>
              </li>
            ))}
          </ul>
        ) : null}

        {product.unlocks.some((u) => u.label) ? (
          <div className="KioskCard-unlocks">
            <span className="KioskCard-unlocksLabel">{t('forum.card.includes')}</span>
            {product.unlocks
              .filter((u) => u.label)
              .map((u) =>
                u.url ? (
                  <a className="KioskChip" href={u.url}>
                    {u.label}
                  </a>
                ) : (
                  <span className="KioskChip">{u.label}</span>
                )
              )}
          </div>
        ) : null}

        <div className="KioskCard-foot">
          {owned ? null : product.prices.length > 1 ? (
            <div className="KioskCard-prices" role="radiogroup" aria-label={String(t('forum.card.choose_price'))}>
              {product.prices.map((price) => (
                <button
                  type="button"
                  role="radio"
                  aria-checked={this.selected?.id === price.id}
                  className={classList('KioskPrice', { 'KioskPrice--selected': this.selected?.id === price.id })}
                  onclick={() => (this.selected = price)}
                >
                  {priceLabel(price)}
                  {price.trialDays ? <small>{t('forum.card.trial', { count: price.trialDays })}</small> : null}
                  {price.seats > 1 ? <small>{t('forum.card.seats', { count: price.seats })}</small> : null}
                </button>
              ))}
            </div>
          ) : this.selected ? (
            <p className="KioskCard-price">
              {priceLabel(this.selected)}
              {this.selected.trialDays ? <small>{t('forum.card.trial', { count: this.selected.trialDays })}</small> : null}
              {this.selected.seats > 1 ? <small>{t('forum.card.seats', { count: this.selected.seats })}</small> : null}
            </p>
          ) : null}

          {this.selected?.kind === 'custom' && !owned ? (
            <label className="KioskCard-amount">
              <span>{t('forum.card.your_amount')}</span>
              <input
                className="FormControl"
                type="number"
                inputmode="decimal"
                min={this.selected.amountCents / 100}
                step="0.01"
                placeholder={String(this.selected.amountCents / 100)}
                value={this.amount}
                oninput={(e: InputEvent) => (this.amount = (e.target as HTMLInputElement).value)}
              />
            </label>
          ) : null}

          {this.extras(owned)}

          {this.action(owned)}
        </div>
      </article>
    );
  }

  giftable(): boolean {
    const kind = this.selected?.kind;
    return (kind === 'one_off' || kind === 'fixed_term') && !this.attrs.product.unlocks.some((u) => ['tip', 'bump', 'post'].includes(u.type));
  }

  /** "Have a coupon?" and "Give as a gift": links that open one small field each. */
  extras(owned: boolean): Mithril.Children {
    const { product, meta } = this.attrs;
    if (!app.session.user || !this.selected || product.refusal || !meta.providers.length) return null;

    const canCoupon = !owned && this.selected.kind !== 'custom';
    const canGift = this.giftable();
    if (!canCoupon && !canGift) return null;

    const discount = this.discounted?.priceId === this.selected.id ? this.discounted : null;

    return (
      <div className="KioskCard-extras">
        <div className="KioskCard-extraLinks">
          {canCoupon ? (
            <button
              type="button"
              className="Button Button--link KioskCard-extraLink"
              aria-expanded={this.extra === 'coupon'}
              onclick={() => this.toggle('coupon')}
            >
              {t('forum.card.coupon')}
            </button>
          ) : null}
          {canGift ? (
            <button
              type="button"
              className="Button Button--link KioskCard-extraLink"
              aria-expanded={this.extra === 'gift'}
              onclick={() => this.toggle('gift')}
            >
              <Icon name="fas fa-gift" /> {t('forum.card.gift')}
            </button>
          ) : null}
        </div>

        {this.extra === 'coupon' ? (
          <div className="KioskCard-field">
            <input
              className="FormControl"
              aria-label={String(t('forum.card.coupon_label'))}
              placeholder={String(t('forum.card.coupon_label'))}
              autocapitalize="characters"
              value={this.coupon}
              oninput={(e: InputEvent) => {
                this.coupon = (e.target as HTMLInputElement).value;
                this.discounted = null;
                this.couponError = '';
              }}
            />
            <Button className="Button" disabled={!this.coupon.trim()} onclick={() => this.applyCoupon()}>
              {t('forum.card.apply')}
            </Button>
          </div>
        ) : null}
        {this.extra === 'coupon' && discount ? (
          <p className="KioskCard-note">{t('forum.card.coupon_total', { amount: money(discount.amountCents, discount.currency) })}</p>
        ) : null}
        {this.extra === 'coupon' && this.couponError ? <p className="KioskCard-note KioskCard-note--error">{this.couponError}</p> : null}

        {this.extra === 'gift' ? (
          <div className="KioskCard-field">
            <input
              className="FormControl"
              aria-label={String(t('forum.card.gift_label'))}
              placeholder={String(t('forum.card.gift_label'))}
              autocapitalize="none"
              value={this.giftTo}
              oninput={(e: InputEvent) => (this.giftTo = (e.target as HTMLInputElement).value)}
            />
          </div>
        ) : null}
      </div>
    );
  }

  toggle(which: 'coupon' | 'gift') {
    this.extra = this.extra === which ? null : which;
  }

  applyCoupon() {
    const price = this.selected;
    if (!price) return;
    checkCoupon(price.id, this.coupon.trim()).then(
      ({ data }) => {
        this.discounted = { priceId: price.id, ...data };
        m.redraw();
      },
      (e: any) => {
        this.couponError = e?.response?.errors?.[0]?.detail || String(t('forum.errors.coupon'));
        m.redraw();
      }
    );
  }

  action(owned: boolean): Mithril.Children {
    const { product, meta } = this.attrs;

    if (owned && !(this.extra === 'gift' && this.giftTo.trim())) {
      return (
        <LinkButton className="Button KioskCard-button" icon="fas fa-circle-check" href={app.route('kiosk.purchases')}>
          {t('forum.card.owned')}
        </LinkButton>
      );
    }

    if (product.refusal && product.refusal !== 'login') {
      return <p className="KioskCard-refusal">{t('forum.card.refusal.' + product.refusal)}</p>;
    }

    if (!meta.providers.length) {
      return <p className="KioskCard-refusal">{t('forum.card.refusal.no_provider')}</p>;
    }

    return (
      <Button
        className="Button Button--primary KioskCard-button"
        loading={this.busy}
        disabled={!this.selected}
        onclick={() => {
          const options: CheckoutOptions = {};
          if (this.selected?.kind === 'custom') options.amount = this.amount || String(this.selected.amountCents / 100);
          if (this.attrs.context) options.context = this.attrs.context;
          if (this.extra === 'coupon' && this.coupon.trim()) options.coupon = this.coupon.trim();
          if (this.extra === 'gift' && this.giftTo.trim()) options.giftTo = this.giftTo.trim().replace(/^@/, '');
          const started = this.selected && buy(product, this.selected, meta, options);
          if (!started) return;
          this.busy = true;
          // Success leaves the page for the provider's; only a failure comes back here.
          started.catch(() => {
            this.busy = false;
            m.redraw();
          });
        }}
      >
        {!app.session.user
          ? t('forum.card.login_to_buy')
          : this.extra === 'gift' && this.giftTo.trim()
            ? t('forum.card.buy_gift')
            : t('forum.card.buy')}
      </Button>
    );
  }
}
