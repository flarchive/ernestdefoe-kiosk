import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import SessionDropdown from 'flarum/forum/components/SessionDropdown';
import LinkButton from 'flarum/common/components/LinkButton';
import { t } from '../common/t';
import { PurchaseNotification } from './notifications';
import lockedTags from './lockedTags';
import teaser from './teaser';
import tagActions from './tagActions';
import ads from './ads';

export { default as ProductCard } from './components/ProductCard';
export { default as LockedNotice } from './components/LockedNotice';

/*
 * Everything here runs on every page for every visitor, so it is only the
 * wiring: three routes, a nav link, a dropdown link, the notification and
 * the admin's ads (nothing at all when there are none).
 * The pages are their own chunks, fetched the first time one is opened.
 */
app.initializers.add('ernestdefoe-kiosk', () => {
  app.routes['kiosk.store'] = { path: '/store', component: () => import('./pages/StorePage') };
  app.routes['kiosk.purchases'] = { path: '/store/purchases', component: () => import('./pages/PurchasesPage') };
  app.routes['kiosk.return'] = { path: '/store/return/:number', component: () => import('./pages/ReturnPage') };

  app.notificationComponents.kioskPurchase = PurchaseNotification;

  // Harmless without flarum/tags: the list is empty and its TagsPage never loads.
  lockedTags();
  teaser();
  tagActions();
  ads();

  extend(IndexSidebar.prototype, 'navItems', function (items) {
    if (app.forum.attribute('kioskShowNav') === false) return;

    items.add(
      'kiosk-store',
      // Active on My purchases and the return page too, so the phone header
      // (which titles the page from the active nav item) says Store, not All Discussions.
      <LinkButton href={app.route('kiosk.store')} icon="fas fa-store" active={/^\/store(\/|$|\?)/.test(m.route.get() || '')}>
        {t('forum.nav')}
      </LinkButton>,
      5
    );
  });

  extend(SessionDropdown.prototype, 'items', function (items) {
    items.add(
      'kiosk-purchases',
      <LinkButton href={app.route('kiosk.purchases')} icon="fas fa-receipt">
        {t('forum.purchases.title')}
      </LinkButton>,
      55
    );
  });

  extend('flarum/forum/components/NotificationGrid', 'notificationTypes', function (items: any) {
    items.add('kioskPurchase', { name: 'kioskPurchase', icon: 'fas fa-receipt', label: t('forum.settings.notify') });
  });
});
