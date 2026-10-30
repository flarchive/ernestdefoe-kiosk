import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../common/t';
import type { Product } from '../common/api';
import { grant } from './api';

interface Attrs extends IFormModalAttrs {
  products: Product[];
  onsaved: () => void;
}

/** Give a product to a member: a comp, a prize, a moderator's perk. No money, never renews. */
export default class GrantModal extends FormModal<Attrs> {
  user = '';
  productId = '';
  days = '0';
  note = '';

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    this.productId = String(this.attrs.products[0]?.id || '');
  }

  className() {
    return 'KioskGrantModal Modal--small';
  }

  title() {
    return t('admin.grant.title');
  }

  content() {
    const products: Record<string, string> = {};
    this.attrs.products.forEach((p) => (products[String(p.id)] = p.name));
    const lengths: Record<string, string> = {};
    ['0', '7', '30', '90', '365'].forEach((d) => (lengths[d] = extractText(t('admin.grant.length_' + d))));

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>{t('admin.grant.user')}</label>
            <input
              className="FormControl"
              value={this.user}
              placeholder={extractText(t('admin.grant.user_placeholder'))}
              oninput={(e: InputEvent) => (this.user = (e.target as HTMLInputElement).value)}
            />
          </div>
          <div className="Form-group">
            <label>{t('admin.grant.product')}</label>
            <Select options={products} value={this.productId} onchange={(v: string) => (this.productId = v)} />
          </div>
          <div className="Form-group">
            <label>{t('admin.grant.length')}</label>
            <Select options={lengths} value={this.days} onchange={(v: string) => (this.days = v)} />
          </div>
          <div className="Form-group">
            <label>{t('admin.grant.note')}</label>
            <input
              className="FormControl"
              value={this.note}
              maxlength={191}
              oninput={(e: InputEvent) => (this.note = (e.target as HTMLInputElement).value)}
            />
          </div>
          <div className="Form-group">
            <Button type="submit" className="Button Button--primary" loading={this.loading} disabled={!this.user.trim() || !this.productId}>
              {t('admin.grant.submit')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    grant({ user: this.user.trim(), productId: Number(this.productId), days: Number(this.days), note: this.note || undefined }).then(
      () => {
        this.attrs.onsaved();
        this.hide();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }
}
