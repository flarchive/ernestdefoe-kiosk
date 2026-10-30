import Component, { type ComponentAttrs } from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { store } from '../../common/api';
import type { Product, StoreMeta } from '../../common/api';
import ProductCard from './ProductCard';

export interface LockedNoticeAttrs extends ComponentAttrs {
  /** An unlock type's key: "tag", "group", "syllabus-course"… */
  type: string;
  id: number;
  /** What to call the item in the heading. */
  name?: string;
  /** Heading instead of "{name} is for members". */
  title?: Mithril.Children;
  /** What a purchase is for (a tip backs a discussion). */
  context?: { type: string; id: number };
}

/**
 * "This is for members — here is how to join", for any locked item. Other
 * extensions render it where their item would be:
 *
 *     import LockedNotice from 'ext:ernestdefoe/kiosk/forum/components/LockedNotice';
 *     <LockedNotice type="syllabus-course" id={course.id} name={course.title} />
 *
 * It asks Kiosk which products unlock the item (local rows, one request) and
 * renders nothing at all when none do, so it is safe to drop in anywhere.
 */
export default class LockedNotice extends Component<LockedNoticeAttrs> {
  products: Product[] | null = null;
  meta: StoreMeta | null = null;

  oninit(vnode: Mithril.Vnode<LockedNoticeAttrs, this>) {
    super.oninit(vnode);
    store({ type: this.attrs.type, id: this.attrs.id }).then(
      ({ data, meta }) => {
        this.products = data;
        this.meta = meta;
        m.redraw();
      },
      () => {
        this.products = [];
        m.redraw();
      }
    );
  }

  view() {
    if (this.products === null) return <LoadingIndicator display="block" />;
    if (!this.products.length || !this.meta) return null;

    return (
      <section className="KioskLocked">
        <h2 className="KioskLocked-title">
          <Icon name="fas fa-lock" />
          {this.attrs.title || (this.attrs.name ? t('forum.locked.title_named', { name: this.attrs.name }) : t('forum.locked.title'))}
        </h2>
        <div className="KioskGrid">
          {this.products.map((product) => (
            <ProductCard product={product} meta={this.meta!} context={this.attrs.context} />
          ))}
        </div>
      </section>
    );
  }
}
