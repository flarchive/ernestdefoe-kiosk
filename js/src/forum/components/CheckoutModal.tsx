import app from 'flarum/forum/app';
import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { priceLabel } from '../../common/money';
import type { CheckoutOptions, Price, Product, StoreMeta } from '../../common/api';
import { go } from './buy';

interface Attrs extends IFormModalAttrs {
  product: Product;
  price: Price;
  meta: StoreMeta;
  options?: CheckoutOptions;
}

/** Only shown when there is a choice to make: which provider, or terms to accept. */
export default class CheckoutModal extends FormModal<Attrs> {
  provider = '';
  agreed = false;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.provider = this.attrs.meta.providers[0]?.key || '';
  }

  className() {
    return 'KioskCheckoutModal Modal--small';
  }

  title() {
    return this.attrs.product.name;
  }

  content() {
    const { meta, price } = this.attrs;

    return (
      <div className="Modal-body">
        <p className="KioskCheckoutModal-price">{priceLabel(price)}</p>
        {meta.providers.length > 1 ? (
          <fieldset className="Form-group">
            <legend>{t('forum.checkout.pay_with')}</legend>
            {meta.providers.map((p) => (
              <label className="checkbox">
                <input type="radio" name="provider" checked={this.provider === p.key} onchange={() => (this.provider = p.key)} />
                {t('lib.providers.' + p.key)}
              </label>
            ))}
          </fieldset>
        ) : null}
        {meta.termsUrl ? (
          <div className="Form-group">
            <label className="checkbox">
              <input type="checkbox" checked={this.agreed} onchange={(e: Event) => (this.agreed = (e.target as HTMLInputElement).checked)} />
              {t('forum.checkout.agree', { a: <a href={meta.termsUrl} target="_blank" rel="noopener" /> })}
            </label>
          </div>
        ) : null}
        <div className="Form-group">
          <Button type="submit" className="Button Button--primary Button--block" loading={this.loading} disabled={!!meta.termsUrl && !this.agreed}>
            {t('forum.checkout.continue', { provider: t('lib.providers.' + this.provider) })}
          </Button>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    go(this.attrs.price, this.provider, this.attrs.options || {}).catch(() => {
      this.loading = false;
      m.redraw();
    });
  }
}
