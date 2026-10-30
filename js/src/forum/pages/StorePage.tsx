import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { store } from '../../common/api';
import type { Product, StoreMeta } from '../../common/api';
import ProductCard from '../components/ProductCard';

/** /store — everything for sale, grouped by what it opens. */
export default class StorePage extends Page {
  products: Product[] = [];
  meta: StoreMeta = { owned: [], providers: [], termsUrl: null };
  loading = true;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    const title = extractText(t('forum.store.title'));
    app.setTitle(title);
    app.history.push('kiosk.store', title);

    store().then(
      ({ data, meta }) => {
        this.products = data;
        this.meta = meta;
        this.loading = false;
        m.redraw();
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  onupdate() {
    // /store?product=gold scrolls to that card once it has drawn.
    const slug = m.route.param('product');
    if (slug && !this.scrolled && !this.loading) {
      this.scrolled = true;
      document.getElementById('kiosk-' + slug)?.scrollIntoView({ block: 'center' });
    }
  }

  scrolled = false;

  view() {
    const highlight = m.route.param('product');

    return (
      <PageStructure className="KioskStorePage" sidebar={() => <IndexSidebar />}>
        <div className="KioskPageHead">
          <h2 className="KioskPageHead-title">{t('forum.store.title')}</h2>
          {app.session.user ? (
            <LinkButton className="Button" icon="fas fa-receipt" href={app.route('kiosk.purchases')}>
              {t('forum.purchases.title')}
            </LinkButton>
          ) : null}
        </div>

        {this.loading ? (
          <LoadingIndicator display="block" />
        ) : this.products.length ? (
          <div className="KioskGrid">
            {this.products.map((product) => (
              <ProductCard product={product} meta={this.meta} highlight={product.slug === highlight} />
            ))}
          </div>
        ) : (
          <p className="KioskEmpty">{t('forum.store.empty')}</p>
        )}
      </PageStructure>
    );
  }
}
