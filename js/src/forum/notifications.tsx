import app from 'flarum/forum/app';
import Notification from 'flarum/forum/components/Notification';
import { t } from '../common/t';

/** Receipts, failed renewals, renewal reminders and ended access: all point at the member's purchases. */
export class PurchaseNotification extends Notification {
  kind(): string {
    return ((this.attrs.notification.content() as any)?.kind as string) || 'receipt';
  }

  icon() {
    return (
      {
        receipt: 'fas fa-receipt',
        payment_failed: 'fas fa-triangle-exclamation',
        reminder: 'fas fa-calendar-day',
        ended: 'fas fa-lock',
        gift: 'fas fa-gift',
        seat: 'fas fa-users',
        awaiting: 'fas fa-building-columns',
        transfer_canceled: 'fas fa-ban',
        transfer_expired: 'fas fa-ban',
      }[this.kind()] || 'fas fa-store'
    );
  }

  href() {
    // An order waiting for a bank transfer: straight to how to pay it.
    const number = (this.attrs.notification.content() as any)?.number;
    return number ? app.route('kiosk.return', { number }) : app.route('kiosk.purchases');
  }

  content() {
    return t('forum.notifications.' + this.kind(), { product: (this.attrs.notification.content() as any)?.product || '' });
  }

  excerpt() {
    return null;
  }
}
