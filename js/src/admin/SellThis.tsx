import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { priceLabel } from '../common/money';
import type { Product } from '../common/api';
import { products, unlockTypes } from './api';
import type { UnlockType } from './api';
import ProductModal from './ProductModal';

interface Attrs extends ComponentAttrs {
  type: string;
  id: number;
  name: string;
}

/**
 * "Sell this" inside the tag and group editors: what already sells this
 * item, and a button that opens a product with it filled in. Where most
 * owners start, so most never need to open the Products tab.
 */
export default class SellThis extends Component<Attrs> {
  products: Product[] | null = null;
  types: UnlockType[] = [];

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    Promise.all([products(), unlockTypes()]).then(([p, types]) => {
      this.products = p.data.filter((x) => x.unlocks.some((u) => u.type === this.attrs.type && u.targetId === this.attrs.id));
      this.types = types.data;
      m.redraw();
    });
  }

  view() {
    return (
      <fieldset className="KioskSellThis">
        <legend>{t('admin.sell_this.legend')}</legend>
        {this.products === null ? (
          <LoadingIndicator size="small" display="inline" />
        ) : (
          <div>
            {this.products.length ? (
              <ul className="KioskSellThis-list">
                {this.products.map((p) => (
                  <li>
                    <strong>{p.name}</strong> — {p.prices.map((x) => priceLabel(x)).join(' / ')}{' '}
                    <Button className="Button Button--link" onclick={() => this.open(p)}>
                      {t('admin.products.edit')}
                    </Button>
                  </li>
                ))}
              </ul>
            ) : (
              <p className="helpText">{t('admin.sell_this.help_' + this.attrs.type)}</p>
            )}
            <Button className="Button" icon="fas fa-store" onclick={() => this.open()}>
              {this.products.length ? t('admin.sell_this.another') : t('admin.sell_this.button')}
            </Button>
          </div>
        )}
      </fieldset>
    );
  }

  open(product?: Product) {
    const currency = String(app.data.settings['ernestdefoe-kiosk.currency'] || 'USD');

    // Stacked over the tag or group editor, which stays open underneath.
    app.modal.show(
      ProductModal,
      {
        product,
        types: this.types,
        preset: product
          ? undefined
          : {
              name: this.attrs.name,
              unlocks: [{ type: this.attrs.type, targetId: this.attrs.id, label: this.attrs.name }],
              prices: [{ kind: 'recurring', amount: '5', interval: 'month', currency }],
            },
        onsaved: () => this.load(),
      },
      true
    );
  }
}
