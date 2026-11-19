import app from 'flarum/admin/app';
import { extend } from 'flarum/common/extend';
import KioskPage from './KioskPage';
import SellThis from './SellThis';

export { default as ProductModal } from './ProductModal';

app.initializers.add('ernestdefoe-kiosk', () => {
  app.registry
    .for('ernestdefoe-kiosk')
    .registerPage(KioskPage)
    .registerPermission(
      { icon: 'fas fa-store', label: app.translator.trans('ernestdefoe-kiosk.admin.permissions.read_paid'), permission: 'kiosk.readPaid' },
      'moderate',
      60
    )
    .registerPermission(
      { icon: 'fas fa-cash-register', label: app.translator.trans('ernestdefoe-kiosk.admin.permissions.sell'), permission: 'kiosk.sell' },
      'start',
      -10
    )
    .registerPermission(
      { icon: 'fas fa-user-check', label: app.translator.trans('ernestdefoe-kiosk.admin.permissions.sell_trusted'), permission: 'kiosk.sellTrusted' },
      'start',
      -11
    )
    .registerPermission(
      { icon: 'fas fa-eye-slash', label: app.translator.trans('ernestdefoe-kiosk.admin.permissions.ad_free'), permission: 'kiosk.adFree' },
      'view',
      -10
    );

  // 🚨 Both editors are lazy chunks in Flarum 2: extend them by module name,
  // never by importing the class (it is undefined at boot).
  extend('ext:flarum/tags/admin/components/EditTagModal', 'fields', function (this: any, items: any) {
    if (!this.tag?.exists) return;
    items.add('kiosk', <SellThis type="tag" id={Number(this.tag.id())} name={this.tag.name()} />, -5);
  });

  extend('flarum/admin/components/EditGroupModal', 'fields', function (this: any, items: any) {
    const group = this.group;
    // Admins, guests and members are never for sale.
    if (!group?.exists || ['1', '2', '3'].includes(group.id())) return;
    items.add('kiosk', <SellThis type="group" id={Number(group.id())} name={group.namePlural()} />, -5);
  });
});
