import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Switch from 'flarum/common/components/Switch';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { CURRENCIES } from '../../common/money';
import { selling } from '../../common/api';
import type { Interval, Listing, OwnItem, Selling } from '../../common/api';

interface ListingModalAttrs extends IFormModalAttrs {
  listing: Listing | null;
  /** A new listing for one of their things, chosen already (Depot's "Sell this file"). */
  preset?: { type: string; targetId: number } | null;
  onsave: (data: Selling) => void;
}

interface PriceRow {
  id?: number;
  amount: string;
  currency: string;
  kind: 'one_off' | 'recurring';
  interval: Interval;
}

/**
 * A member's listing: what it's called, which of their own things it opens
 * (their Depot files, courses, albums…), and what it costs. The forum's
 * extras (buyer groups, stock, dates) are the admin's, not here.
 */
export default class ListingModal extends FormModal<ListingModalAttrs> {
  name = '';
  description = '';
  active = true;
  items: OwnItem[] = [];
  chosen: { type: string; targetId: number; label: string }[] = [];
  prices: PriceRow[] = [];
  query = '';
  searching = false;

  oninit(vnode: Mithril.Vnode<ListingModalAttrs, this>) {
    super.oninit(vnode);
    const l = this.attrs.listing;

    this.name = l?.name || '';
    this.description = l?.description || '';
    this.active = l ? l.active : true;
    this.chosen = (l?.unlocks || []).map((u) => ({ type: u.type, targetId: u.targetId, label: u.label || '#' + u.targetId }));
    this.prices = (l?.prices || [])
      .filter((p) => p.kind === 'one_off' || p.kind === 'recurring')
      .map((p) => ({
        id: p.id,
        amount: String(p.amountCents / 100),
        currency: p.currency,
        kind: p.kind as PriceRow['kind'],
        interval: (p.interval || 'month') as Interval,
      }));
    if (!this.prices.length) this.prices.push({ amount: '', currency: 'USD', kind: 'one_off', interval: 'month' });

    this.search();
  }

  className() {
    return 'KioskListingModal Modal--medium';
  }

  title() {
    return t(this.attrs.listing ? 'forum.selling.edit_listing' : 'forum.selling.new_listing');
  }

  search() {
    this.searching = true;
    selling.items(this.query).then(
      ({ data }) => {
        this.items = data;
        this.searching = false;
        const preset = this.attrs.preset;
        const item = preset && !this.chosen.length ? data.find((i) => i.type === preset.type && i.id === preset.targetId) : null;
        if (item) {
          this.chosen.push({ type: item.type, targetId: item.id, label: item.label });
          this.name ||= item.label;
        }
        m.redraw();
      },
      () => {
        this.searching = false;
        m.redraw();
      }
    );
  }

  content() {
    return (
      <div className="Modal-body">
        <div className="Form-group">
          <label for="kiosk-listing-name">{t('forum.selling.name')}</label>
          <input
            id="kiosk-listing-name"
            className="FormControl"
            maxlength={150}
            value={this.name}
            oninput={(e: InputEvent) => (this.name = (e.target as HTMLInputElement).value)}
          />
        </div>

        <div className="Form-group">
          <label for="kiosk-listing-description">{t('forum.selling.description')}</label>
          <textarea
            id="kiosk-listing-description"
            className="FormControl"
            rows={3}
            maxlength={5000}
            value={this.description}
            oninput={(e: InputEvent) => (this.description = (e.target as HTMLTextAreaElement).value)}
          />
        </div>

        <div className="Form-group">
          <label>{t('forum.selling.unlocks')}</label>
          <p className="helpText">{t('forum.selling.unlocks_help')}</p>
          <div className="KioskCard-unlocks">
            {this.chosen.map((c, i) => (
              <span className="KioskChip">
                {c.label}{' '}
                <button
                  type="button"
                  className="Button Button--icon Button--link"
                  aria-label={extractText(t('forum.selling.remove_item'))}
                  onclick={() => this.chosen.splice(i, 1)}
                >
                  <Icon name="fas fa-times" />
                </button>
              </span>
            ))}
          </div>
          <input
            className="FormControl"
            placeholder={extractText(t('forum.selling.find_item'))}
            value={this.query}
            oninput={(e: InputEvent) => {
              this.query = (e.target as HTMLInputElement).value;
              this.search();
            }}
          />
          <ul className="KioskListingModal-items">
            {this.items
              .filter((item) => !this.chosen.some((c) => c.type === item.type && c.targetId === item.id))
              .map((item) => (
                <li>
                  <button
                    type="button"
                    className="Button Button--link"
                    onclick={() => this.chosen.push({ type: item.type, targetId: item.id, label: item.label })}
                  >
                    <Icon name="fas fa-plus" /> {item.label} <small>{item.typeLabel}</small>
                  </button>
                </li>
              ))}
            {!this.searching && !this.items.length ? <li className="helpText">{t('forum.selling.no_items')}</li> : null}
          </ul>
        </div>

        <div className="Form-group">
          <label>{t('forum.selling.prices')}</label>
          {this.prices.map((p, i) => (
            <div className="KioskListingModal-price">
              <input
                className="FormControl"
                type="number"
                inputmode="decimal"
                min="0.01"
                step="0.01"
                aria-label={extractText(t('forum.selling.amount'))}
                placeholder={extractText(t('forum.selling.amount'))}
                value={p.amount}
                oninput={(e: InputEvent) => (p.amount = (e.target as HTMLInputElement).value)}
              />
              <select
                className="FormControl"
                aria-label={extractText(t('forum.selling.currency'))}
                value={p.currency}
                onchange={(e: Event) => (p.currency = (e.target as HTMLSelectElement).value)}
              >
                {CURRENCIES.map((c) => (
                  <option value={c}>{c}</option>
                ))}
              </select>
              <select
                className="FormControl"
                aria-label={extractText(t('forum.selling.kind'))}
                value={p.kind === 'one_off' ? 'one_off' : p.interval}
                onchange={(e: Event) => {
                  const v = (e.target as HTMLSelectElement).value;
                  p.kind = v === 'one_off' ? 'one_off' : 'recurring';
                  if (v !== 'one_off') p.interval = v as Interval;
                }}
              >
                <option value="one_off">{t('forum.selling.kinds.one_off')}</option>
                <option value="month">{t('forum.selling.kinds.month')}</option>
                <option value="year">{t('forum.selling.kinds.year')}</option>
              </select>
              {this.prices.length > 1 ? (
                <Button
                  className="Button Button--icon Button--link"
                  icon="fas fa-times"
                  aria-label={extractText(t('forum.selling.remove_price'))}
                  onclick={() => this.prices.splice(i, 1)}
                />
              ) : null}
            </div>
          ))}
          {this.prices.length < 5 ? (
            <Button
              className="Button Button--link"
              icon="fas fa-plus"
              onclick={() => this.prices.push({ amount: '', currency: this.prices[0]?.currency || 'USD', kind: 'recurring', interval: 'month' })}
            >
              {t('forum.selling.add_price')}
            </Button>
          ) : null}
        </div>

        {this.attrs.listing ? (
          <div className="Form-group">
            <Switch state={this.active} onchange={(v: boolean) => (this.active = v)}>
              {t('forum.selling.on_sale')}
            </Switch>
          </div>
        ) : null}

        <div className="Form-group">
          <Button className="Button Button--primary" type="submit" loading={this.loading} disabled={!this.name.trim() || !this.chosen.length}>
            {t(this.attrs.listing ? 'forum.selling.save' : 'forum.selling.submit')}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;

    selling
      .save(this.attrs.listing?.id || null, {
        name: this.name.trim(),
        description: this.description.trim(),
        active: this.active,
        unlocks: this.chosen.map((c) => ({ type: c.type, targetId: c.targetId })),
        prices: this.prices.map((p) => ({
          id: p.id,
          amount: p.amount,
          currency: p.currency,
          kind: p.kind,
          interval: p.kind === 'recurring' ? p.interval : null,
        })),
      })
      .then(
        ({ data }) => {
          this.attrs.onsave(data);
          this.hide();
        },
        (error) => {
          this.loading = false;
          this.onerror(error);
        }
      );
  }
}
