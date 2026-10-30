import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Select from 'flarum/common/components/Select';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { date, money } from '../common/money';
import type { Product } from '../common/api';
import * as api from './api';
import type { Coupon } from './api';
import { CURRENCIES } from './ProductModal';

export interface CouponsTabAttrs extends ComponentAttrs {
  products: Product[] | null;
}

/** Discount codes: a percent or an amount off, for every product or some, with an optional limit and end date. */
export default class CouponsTab extends Component<CouponsTabAttrs> {
  coupons: Coupon[] | null = null;
  form = { code: '', kind: 'percent', value: '', currency: 'USD', productId: '', maxUses: '', expiresAt: '' };
  busy = false;

  oninit(vnode: Mithril.Vnode<CouponsTabAttrs, this>) {
    super.oninit(vnode);
    api.coupons().then(({ data }) => {
      this.coupons = data;
      m.redraw();
    });
  }

  view() {
    const products = this.attrs.products || [];
    const currencies: Record<string, string> = {};
    CURRENCIES.forEach((c) => (currencies[c] = c));
    const productOptions: Record<string, string> = { '': extractText(t('admin.coupons.all_products')) };
    products.forEach((p) => (productOptions[String(p.id)] = p.name));
    const f = this.form;

    return (
      <div className="KioskCoupons">
        <form className="KioskCouponForm" onsubmit={(e: Event) => this.create(e)}>
          <label>
            <span>{t('admin.coupons.code')}</span>
            <input
              className="FormControl"
              value={f.code}
              placeholder="WELCOME10"
              oninput={(e: InputEvent) => (f.code = (e.target as HTMLInputElement).value.toUpperCase())}
            />
          </label>
          <label>
            <span>{t('admin.coupons.discount')}</span>
            <span className="KioskCouponForm-pair">
              <input
                className="FormControl"
                type="number"
                inputmode="decimal"
                min="0"
                step={f.kind === 'percent' ? '1' : '0.01'}
                value={f.value}
                oninput={(e: InputEvent) => (f.value = (e.target as HTMLInputElement).value)}
              />
              <Select
                options={{ percent: '%', ...currencies }}
                value={f.kind === 'percent' ? 'percent' : f.currency}
                onchange={(v: string) => {
                  f.kind = v === 'percent' ? 'percent' : 'amount';
                  if (v !== 'percent') f.currency = v;
                }}
              />
            </span>
          </label>
          <label>
            <span>{t('admin.coupons.product')}</span>
            <Select options={productOptions} value={f.productId} onchange={(v: string) => (f.productId = v)} />
          </label>
          <label>
            <span>{t('admin.coupons.max_uses')}</span>
            <input
              className="FormControl"
              type="number"
              min="0"
              placeholder={extractText(t('admin.coupons.unlimited'))}
              value={f.maxUses}
              oninput={(e: InputEvent) => (f.maxUses = (e.target as HTMLInputElement).value)}
            />
          </label>
          <label>
            <span>{t('admin.coupons.expires')}</span>
            <input
              className="FormControl"
              type="date"
              value={f.expiresAt}
              oninput={(e: InputEvent) => (f.expiresAt = (e.target as HTMLInputElement).value)}
            />
          </label>
          <Button className="Button Button--primary" type="submit" loading={this.busy} disabled={!f.code.trim() || !f.value}>
            {t('admin.coupons.create')}
          </Button>
        </form>
        <p className="helpText">{t('admin.coupons.help')}</p>

        {!this.coupons ? (
          <LoadingIndicator />
        ) : !this.coupons.length ? (
          <p className="KioskEmpty">{t('admin.coupons.empty')}</p>
        ) : (
          <div className="KioskTableWrap">
            <table className="KioskTable">
              <thead>
                <tr>
                  <th>{t('admin.coupons.code')}</th>
                  <th>{t('admin.coupons.discount')}</th>
                  <th>{t('admin.coupons.used')}</th>
                  <th>{t('admin.coupons.expires')}</th>
                  <th />
                </tr>
              </thead>
              <tbody>
                {this.coupons.map((c) => (
                  <tr>
                    <td>
                      <code>{c.code}</code>
                      {c.productIds.length ? (
                        <small className="KioskTable-sub">
                          {c.productIds.map((id) => products.find((p) => p.id === id)?.name || '#' + id).join(', ')}
                        </small>
                      ) : null}
                    </td>
                    <td>{c.kind === 'percent' ? c.value + '%' : money(c.value, c.currency || 'USD')}</td>
                    <td>{c.maxUses ? c.usedCount + ' / ' + c.maxUses : c.usedCount}</td>
                    <td>{c.expiresAt ? date(c.expiresAt) : '—'}</td>
                    <td className="KioskTable-actions">
                      <Button
                        className="Button Button--icon"
                        icon="fas fa-trash"
                        aria-label={extractText(t('admin.coupons.delete'))}
                        onclick={() => {
                          if (!confirm(extractText(t('admin.coupons.delete_confirm', { code: c.code })))) return;
                          api.deleteCoupon(c.id).then(() => {
                            this.coupons = (this.coupons || []).filter((x) => x.id !== c.id);
                            m.redraw();
                          });
                        }}
                      />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    );
  }

  create(e: Event) {
    e.preventDefault();
    const f = this.form;
    this.busy = true;
    api
      .createCoupon({
        code: f.code.trim(),
        kind: f.kind,
        value: f.value,
        currency: f.currency,
        productIds: f.productId ? [Number(f.productId)] : [],
        maxUses: f.maxUses ? Number(f.maxUses) : null,
        expiresAt: f.expiresAt || null,
      })
      .then(
        ({ data }) => {
          this.coupons = [data, ...(this.coupons || [])];
          this.form = { ...this.form, code: '', value: '', maxUses: '', expiresAt: '' };
          this.busy = false;
          m.redraw();
        },
        () => {
          this.busy = false;
          m.redraw();
        }
      );
  }
}
