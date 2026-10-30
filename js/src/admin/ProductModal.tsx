import app from 'flarum/admin/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { priceLabel } from '../common/money';
import type { Kind, Interval, Product } from '../common/api';
import { saveProduct, searchUnlocks } from './api';
import type { UnlockType } from './api';

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

interface PriceRow {
  id?: number;
  kind: Kind;
  amount: string;
  currency: string;
  interval: Interval;
  intervalCount: number;
  termDays: number;
  trialDays: number;
  seats: number;
}

interface UnlockRow {
  type: string;
  targetId: number;
  label: string;
  /** Tags only: show titles to everyone (true) or hide the tag (false). */
  teaser?: boolean;
}

export interface ProductModalAttrs extends IFormModalAttrs {
  product?: Product;
  types: UnlockType[];
  /** A starting point for a new product: from a template or a "Sell this" button. */
  preset?: { name?: string; icon?: string; pickType?: string; unlocks?: UnlockRow[]; prices?: Partial<PriceRow>[] };
  onsaved: (product: Product) => void;
}

/** One form for a product: what it is, what it unlocks, and its prices. */
export default class ProductModal extends FormModal<ProductModalAttrs> {
  name = '';
  description = '';
  benefits = '';
  icon = '';
  color = '';
  active = true;
  hidden = false;
  stock = '';
  buyerGroupIds: number[] = [];
  unlocks: UnlockRow[] = [];
  prices: PriceRow[] = [];

  pickType = '';
  query = '';
  results: { id: number; label: string }[] = [];
  searching = 0;
  timer = 0;

  oninit(vnode: Mithril.Vnode<ProductModalAttrs, this>) {
    super.oninit(vnode);
    const p = this.attrs.product;
    const preset = this.attrs.preset || {};
    const currency = String(app.data.settings['ernestdefoe-kiosk.currency'] || 'USD');

    this.name = p?.name ?? preset.name ?? '';
    this.description = p?.description ?? '';
    this.benefits = p?.benefitsText ?? '';
    this.icon = p?.icon ?? preset.icon ?? 'fas fa-star';
    this.color = p?.color ?? '';
    this.active = p?.active ?? true;
    this.hidden = p?.hidden ?? false;
    this.stock = p?.stock != null ? String(p.stock) : '';
    this.buyerGroupIds = p?.buyerGroupIds ?? [];
    this.unlocks = p
      ? p.unlocks.map((u) => ({ type: u.type, targetId: u.targetId, label: u.label || '#' + u.targetId, teaser: u.teaser ?? undefined }))
      : preset.unlocks || [];
    this.prices = p
      ? p.prices.map((x) => ({
          id: x.id,
          kind: x.kind,
          amount: String(x.amountCents / (['JPY', 'HUF', 'TWD'].includes(x.currency) ? 1 : 100)),
          currency: x.currency,
          interval: x.interval || 'month',
          intervalCount: x.intervalCount,
          termDays: x.termDays || 30,
          trialDays: x.trialDays,
          seats: x.seats || 1,
        }))
      : (preset.prices || [{}]).map(
          (x) =>
            ({
              kind: 'recurring',
              amount: '5',
              currency,
              interval: 'month',
              intervalCount: 1,
              termDays: 30,
              trialDays: 0,
              seats: 1,
              ...x,
            }) as PriceRow
        );

    this.pickType = preset.pickType || preset.unlocks?.[0]?.type || this.attrs.types[0]?.key || '';
  }

  className() {
    return 'KioskProductModal Modal--large';
  }

  title() {
    return this.attrs.product ? t('admin.product.edit_title') : t('admin.product.new_title');
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="KioskFormRow">
            <div className="Form-group KioskFormRow-grow">
              <label>{t('admin.product.name')}</label>
              <input
                className="FormControl"
                value={this.name}
                maxlength={150}
                oninput={(e: InputEvent) => (this.name = (e.target as HTMLInputElement).value)}
              />
            </div>
            <div className="Form-group">
              <label>{t('admin.product.icon')}</label>
              <div className="KioskIconField">
                <Icon name={this.icon || 'fas fa-star'} />
                <input
                  className="FormControl"
                  value={this.icon}
                  placeholder="fas fa-crown"
                  oninput={(e: InputEvent) => (this.icon = (e.target as HTMLInputElement).value)}
                />
              </div>
            </div>
            <div className="Form-group">
              <label>{t('admin.product.color')}</label>
              <input
                className="FormControl KioskColor"
                type="color"
                value={this.color || '#b45309'}
                oninput={(e: InputEvent) => (this.color = (e.target as HTMLInputElement).value)}
              />
            </div>
          </div>

          <div className="Form-group">
            <label>{t('admin.product.description')}</label>
            <textarea
              className="FormControl"
              rows={2}
              value={this.description}
              oninput={(e: InputEvent) => (this.description = (e.target as HTMLTextAreaElement).value)}
            />
          </div>

          <div className="Form-group">
            <label>{t('admin.product.benefits')}</label>
            <div className="helpText">{t('admin.product.benefits_help')}</div>
            <textarea
              className="FormControl"
              rows={3}
              value={this.benefits}
              oninput={(e: InputEvent) => (this.benefits = (e.target as HTMLTextAreaElement).value)}
            />
          </div>

          <fieldset className="KioskFieldset">
            <legend>{t('admin.product.unlocks')}</legend>
            <div className="helpText">{t('admin.product.unlocks_help')}</div>
            {this.unlocks.length ? (
              <ul className="KioskUnlockList">
                {this.unlocks.map((u, i) => (
                  <li>
                    <Icon name={this.type(u.type)?.icon || 'fas fa-cube'} />
                    <span className="KioskUnlockList-type">{this.type(u.type)?.label || u.type}</span>
                    <strong>{u.label}</strong>
                    {u.type === 'tag' ? (
                      <Switch state={u.teaser !== false} onchange={(v: boolean) => (u.teaser = v)}>
                        {t('admin.product.teaser')}
                      </Switch>
                    ) : null}
                    <Button
                      className="Button Button--icon Button--link"
                      icon="fas fa-xmark"
                      aria-label={extractText(t('admin.product.remove'))}
                      onclick={() => this.unlocks.splice(i, 1)}
                    />
                  </li>
                ))}
              </ul>
            ) : null}
            {this.picker()}
            {this.unlocks.some((x) => x.type === 'tag') ? <p className="KioskNote">{t('admin.product.teaser_help')}</p> : null}
          </fieldset>

          <fieldset className="KioskFieldset">
            <legend>{t('admin.product.prices')}</legend>
            {this.prices.map((p, i) => this.priceRow(p, i))}
            {this.prices.length < 10 ? (
              <Button
                className="Button"
                icon="fas fa-plus"
                onclick={() => this.prices.push({ ...this.prices[this.prices.length - 1], id: undefined } as PriceRow)}
              >
                {t('admin.product.add_price')}
              </Button>
            ) : null}
            {this.attrs.product ? <p className="KioskNote">{t('admin.product.price_note')}</p> : null}
          </fieldset>

          <fieldset className="KioskFieldset">
            <legend>{t('admin.product.availability')}</legend>
            <Switch state={this.active} onchange={(v: boolean) => (this.active = v)}>
              {t('admin.product.active')}
            </Switch>
            <Switch state={this.hidden} onchange={(v: boolean) => (this.hidden = v)}>
              {t('admin.product.hidden')}
            </Switch>
            <div className="helpText">{t('admin.product.hidden_help')}</div>
            <div className="Form-group">
              <label>{t('admin.product.stock')}</label>
              <input
                className="FormControl KioskNarrow"
                type="number"
                min={0}
                value={this.stock}
                placeholder={extractText(t('admin.product.unlimited'))}
                oninput={(e: InputEvent) => (this.stock = (e.target as HTMLInputElement).value)}
              />
            </div>
            <div className="Form-group">
              <label>{t('admin.product.buyers')}</label>
              <div className="helpText">{t('admin.product.buyers_help')}</div>
              <div className="KioskChecks">
                {(app.store.all('groups') as any[])
                  .filter((g) => !['1', '2', '3'].includes(g.id()))
                  .map((g) => {
                    const id = Number(g.id());
                    return (
                      <label className="checkbox">
                        <input
                          type="checkbox"
                          checked={this.buyerGroupIds.includes(id)}
                          onchange={(e: Event) =>
                            (this.buyerGroupIds = (e.target as HTMLInputElement).checked
                              ? [...this.buyerGroupIds, id]
                              : this.buyerGroupIds.filter((x) => x !== id))
                          }
                        />
                        {g.namePlural()}
                      </label>
                    );
                  })}
              </div>
            </div>
          </fieldset>

          <div className="Form-group">
            <Button
              type="submit"
              className="Button Button--primary"
              loading={this.loading}
              disabled={!this.name.trim() || !this.unlocks.length || !this.prices.length}
            >
              {t('admin.product.save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  type(key: string): UnlockType | undefined {
    return this.attrs.types.find((x) => x.key === key);
  }

  picker(): Mithril.Children {
    const options: Record<string, string> = {};
    this.attrs.types.forEach((x) => (options[x.key] = x.label));

    return (
      <div className="KioskPicker">
        <Select
          options={options}
          value={this.pickType}
          onchange={(v: string) => {
            this.pickType = v;
            this.results = [];
            this.search();
          }}
        />
        <div className="KioskPicker-search">
          <input
            className="FormControl"
            type="search"
            value={this.query}
            placeholder={extractText(t('admin.product.search', { type: this.type(this.pickType)?.label || '' }))}
            onfocus={() => this.search()}
            onblur={() => {
              // Late enough for a click on a result to land first.
              window.setTimeout(() => this.close(), 200);
            }}
            onkeydown={(e: KeyboardEvent) => {
              // 🚨 A block, not `e.key === 'Escape' && …`: Mithril treats a
              // handler returning false as preventDefault, which ate every keystroke.
              if (e.key === 'Escape') {
                e.preventDefault();
                this.close();
              }
            }}
            oninput={(e: InputEvent) => {
              this.query = (e.target as HTMLInputElement).value;
              clearTimeout(this.timer);
              this.timer = window.setTimeout(() => this.search(), 250);
            }}
          />
          {this.results.length ? (
            <ul className="KioskPicker-results Dropdown-menu">
              {this.results
                .filter((r) => !this.unlocks.some((x) => x.type === this.pickType && x.targetId === r.id))
                .map((r) => (
                  <li>
                    <button type="button" onclick={() => this.pick(r)}>
                      {r.label}
                    </button>
                  </li>
                ))}
            </ul>
          ) : null}
        </div>
      </div>
    );
  }

  pick(r: { id: number; label: string }) {
    this.unlocks.push({ type: this.pickType, targetId: r.id, label: r.label, teaser: this.pickType === 'tag' ? true : undefined });
    this.query = '';
    this.close();
  }

  /** Close the results, and make any search still on its way land nowhere. */
  close() {
    clearTimeout(this.timer);
    this.searching++;
    this.results = [];
    m.redraw();
  }

  search() {
    if (!this.pickType) return;
    const ask = ++this.searching;
    searchUnlocks(this.pickType, this.query).then(({ data }) => {
      if (ask !== this.searching) return;
      this.results = data;
      m.redraw();
    });
  }

  priceRow(p: PriceRow, i: number): Mithril.Children {
    const kinds: Record<string, string> = {};
    ['recurring', 'one_off', 'fixed_term', 'custom'].forEach((k) => (kinds[k] = extractText(t('admin.product.kind.' + k))));
    const intervals: Record<string, string> = {};
    ['day', 'week', 'month', 'year'].forEach((k) => (intervals[k] = extractText(t('admin.product.interval.' + k))));
    const currencies: Record<string, string> = {};
    CURRENCIES.forEach((c) => (currencies[c] = c));
    const cents = Math.round(parseFloat(p.amount || '0') * (['JPY', 'HUF', 'TWD'].includes(p.currency) ? 1 : 100));

    return (
      <div className="KioskPriceRow">
        <div className="KioskPriceRow-main">
          <Select options={kinds} value={p.kind} onchange={(v: Kind) => (p.kind = v)} />
          <input
            className="FormControl KioskNarrow"
            type="number"
            min="0"
            step="0.01"
            value={p.amount}
            aria-label={extractText(t('admin.product.amount'))}
            oninput={(e: InputEvent) => (p.amount = (e.target as HTMLInputElement).value)}
          />
          <Select options={currencies} value={p.currency} onchange={(v: string) => (p.currency = v)} />
          <span className="KioskPriceRow-preview">
            {cents > 0
              ? priceLabel({
                  amountCents: cents,
                  currency: p.currency,
                  kind: p.kind,
                  interval: p.interval,
                  intervalCount: p.intervalCount,
                  termDays: p.termDays,
                })
              : ''}
          </span>
          {this.prices.length > 1 ? (
            <Button
              className="Button Button--icon Button--link"
              icon="fas fa-xmark"
              aria-label={extractText(t('admin.product.remove'))}
              onclick={() => this.prices.splice(i, 1)}
            />
          ) : null}
        </div>
        {p.kind === 'recurring' ? (
          <div className="KioskPriceRow-terms">
            <span>{t('admin.product.every')}</span>
            <input
              className="FormControl KioskTiny"
              type="number"
              min={1}
              value={p.intervalCount}
              aria-label={extractText(t('admin.product.every'))}
              oninput={(e: InputEvent) => (p.intervalCount = Math.max(1, parseInt((e.target as HTMLInputElement).value) || 1))}
            />
            <Select options={intervals} value={p.interval} onchange={(v: Interval) => (p.interval = v)} />
            <span className="KioskPriceRow-gap">{t('admin.product.trial')}</span>
            <input
              className="FormControl KioskTiny"
              type="number"
              min={0}
              max={365}
              value={p.trialDays}
              aria-label={extractText(t('admin.product.trial'))}
              oninput={(e: InputEvent) => (p.trialDays = Math.max(0, parseInt((e.target as HTMLInputElement).value) || 0))}
            />
            <span>{t('admin.product.days')}</span>
          </div>
        ) : p.kind === 'fixed_term' ? (
          <div className="KioskPriceRow-terms">
            <span>{t('admin.product.for')}</span>
            <input
              className="FormControl KioskTiny"
              type="number"
              min={1}
              max={3650}
              value={p.termDays}
              aria-label={extractText(t('admin.product.for'))}
              oninput={(e: InputEvent) => (p.termDays = Math.max(1, parseInt((e.target as HTMLInputElement).value) || 1))}
            />
            <span>{t('admin.product.days')}</span>
          </div>
        ) : null}
        {p.kind !== 'custom' ? (
          <div className="KioskPriceRow-terms">
            <span>{t('admin.product.seats')}</span>
            <input
              className="FormControl KioskTiny"
              type="number"
              min={1}
              max={1000}
              value={p.seats}
              aria-label={extractText(t('admin.product.seats'))}
              oninput={(e: InputEvent) => (p.seats = Math.min(1000, Math.max(1, parseInt((e.target as HTMLInputElement).value) || 1)))}
            />
            <span className="KioskPriceRow-hint">{t('admin.product.seats_help')}</span>
          </div>
        ) : null}
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    saveProduct(
      {
        name: this.name,
        description: this.description,
        benefits: this.benefits,
        icon: this.icon,
        color: this.color,
        active: this.active,
        hidden: this.hidden,
        stock: this.stock === '' ? null : Number(this.stock),
        buyerGroupIds: this.buyerGroupIds,
        unlocks: this.unlocks.map((x) => ({ type: x.type, targetId: x.targetId, ...(x.type === 'tag' ? { teaser: x.teaser !== false } : {}) })),
        prices: this.prices.map((x) => ({
          id: x.id,
          kind: x.kind,
          amount: x.amount,
          currency: x.currency,
          interval: x.interval,
          intervalCount: x.intervalCount,
          termDays: x.termDays,
          trialDays: x.trialDays,
          seats: x.seats,
        })),
      },
      this.attrs.product?.id
    ).then(
      ({ data }) => {
        this.attrs.onsaved(data);
        this.hide();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}
