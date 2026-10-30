import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { date } from '../../common/money';
import { confirm, purchase } from '../../common/api';
import type { Purchase } from '../../common/api';

/**
 * /store/return/K-… — where PayPal sends the buyer back. It asks the server
 * to check with PayPal (never trusting the query string), then waits a little
 * for a subscription's activation to arrive.
 */
export default class ReturnPage extends Page {
  purchase: Purchase | null = null;
  phase: 'checking' | 'done' | 'waiting' | 'cancelled' | 'failed' = 'checking';
  tries = 0;
  timer = 0;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    app.setTitle(extractText(t('forum.return.title')));

    const number = m.route.param('number');

    if (m.route.param('cancelled')) {
      this.phase = 'cancelled';
      return;
    }

    confirm(number).then(
      ({ data }) => this.settle(data),
      () => {
        this.phase = 'failed';
        m.redraw();
      }
    );
  }

  onremove(vnode: Mithril.VnodeDOM) {
    super.onremove(vnode);
    clearTimeout(this.timer);
  }

  settle(p: Purchase) {
    this.purchase = p;

    if (p.status === 'active' || p.status === 'canceled') {
      this.phase = 'done';
      // What they bought may have changed what they can see.
      app.store.find('users', String(app.session.user!.id()));
    } else if (this.tries++ < 15) {
      this.phase = 'waiting';
      this.timer = window.setTimeout(() => purchase(p.number).then(({ data }) => this.settle(data)), 2000);
    } else {
      this.phase = 'waiting';
    }

    m.redraw();
  }

  view() {
    return (
      <PageStructure className="KioskReturnPage" sidebar={() => <IndexSidebar />}>
        <div className="KioskReturn">{this.body()}</div>
      </PageStructure>
    );
  }

  body(): Mithril.Children {
    const p = this.purchase;

    switch (this.phase) {
      case 'checking':
        return [<LoadingIndicator display="block" />, <p>{t('forum.return.checking')}</p>];

      case 'cancelled':
        return [
          <h2>{t('forum.return.cancelled_title')}</h2>,
          <p>{t('forum.return.cancelled_text')}</p>,
          <LinkButton className="Button" href={app.route('kiosk.store')}>
            {t('forum.return.back_to_store')}
          </LinkButton>,
        ];

      case 'failed':
        return [
          <h2>{t('forum.return.failed_title')}</h2>,
          <p>{t('forum.return.failed_text')}</p>,
          <LinkButton className="Button" href={app.route('kiosk.purchases')}>
            {t('forum.purchases.title')}
          </LinkButton>,
        ];

      case 'waiting':
        return [<LoadingIndicator display="block" />, <h2>{t('forum.return.waiting_title')}</h2>, <p>{t('forum.return.waiting_text')}</p>];

      default:
        return [
          <Icon name="fas fa-circle-check" className="KioskReturn-icon" />,
          <h2>{t('forum.return.done_title', { product: p?.productName })}</h2>,
          p?.periodEnd ? (
            <p>{t(p.kind === 'recurring' ? 'forum.return.renews' : 'forum.return.until', { date: date(p.periodEnd) })}</p>
          ) : (
            <p>{t('forum.return.forever')}</p>
          ),
          p?.unlocks.some((u) => u.url) ? (
            <div className="KioskReturn-links">
              {p.unlocks
                .filter((u) => u.url)
                .map((u) => (
                  <a className="Button Button--primary" href={u.url!}>
                    {t('forum.return.go_to', { name: u.label })}
                  </a>
                ))}
            </div>
          ) : null,
          <LinkButton className="Button" href={app.route('kiosk.purchases')}>
            {t('forum.purchases.title')}
          </LinkButton>,
        ];
    }
  }
}
