import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import classList from 'flarum/common/utils/classList';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { priceLabel } from '../common/money';
import * as api from './api';
import type { Listing, StripeStatus } from './api';

interface SellersTabAttrs extends ComponentAttrs {
  /** The page's own setting fields and Save button. */
  settings: () => Mithril.Children;
  stripe: StripeStatus;
  onStripe: (status: StripeStatus) => void;
}

/**
 * Members who sell: the switch and rules (on the page's settings form), Stripe
 * Connect for members, and the listings waiting for review.
 */
export default class SellersTab extends Component<SellersTabAttrs> {
  rows: Listing[] | null = null;
  all = false;
  busy: number | 'stripe' | null = null;
  stripeError = '';

  oninit(vnode: Mithril.Vnode<SellersTabAttrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    api.listings(this.all).then(({ data }) => {
      this.rows = data;
      m.redraw();
    });
  }

  view() {
    const stripe = this.attrs.stripe;
    const mode = stripe[stripe.mode];

    return (
      <div className="KioskSellers">
        <p className="helpText">{t('admin.sellers.intro')}</p>
        {this.attrs.settings()}

        <section className="KioskSellers-stripe">
          <h3>{t('admin.sellers.stripe_title')}</h3>
          <p className="helpText">{t('admin.sellers.stripe_help')}</p>
          {mode.sellers ? (
            <p className="KioskSuccess">{t('admin.sellers.stripe_ready', { mode: stripe.mode })}</p>
          ) : (
            <Button className="Button" loading={this.busy === 'stripe'} disabled={!mode.connected} onclick={() => this.connectStripe()}>
              {t('admin.sellers.stripe_connect')}
            </Button>
          )}
          {this.stripeError ? <p className="KioskWarning">{this.stripeError}</p> : null}
        </section>

        <section>
          <div className="KioskSellers-head">
            <h3>{t(this.all ? 'admin.sellers.all_listings' : 'admin.sellers.queue')}</h3>
            <Button
              className="Button Button--link"
              onclick={() => {
                this.all = !this.all;
                this.rows = null;
                this.load();
              }}
            >
              {t(this.all ? 'admin.sellers.show_queue' : 'admin.sellers.show_all')}
            </Button>
          </div>
          {this.rows === null ? (
            <LoadingIndicator />
          ) : this.rows.length ? (
            <ul className="KioskSellers-list">{this.rows.map((l) => this.row(l))}</ul>
          ) : (
            <p className="helpText">{t(this.all ? 'admin.sellers.none' : 'admin.sellers.queue_empty')}</p>
          )}
        </section>
      </div>
    );
  }

  row(l: Listing): Mithril.Children {
    return (
      <li className={classList('KioskSellers-row', { 'KioskSellers-row--pending': l.review === 'pending' })}>
        <div className="KioskSellers-main">
          <strong>{l.name}</strong>{' '}
          {l.seller ? (
            <a href={app.forum.attribute('baseUrl') + '/u/' + l.seller.username} target="_blank" rel="noopener">
              {l.seller.displayName}
            </a>
          ) : null}
          <div className="helpText">
            {l.prices.map((p) => priceLabel(p)).join(' · ')}
            {' · '}
            {l.unlocks.map((u) => u.label || u.type + ' #' + u.targetId).join(', ')}
          </div>
          {l.description ? <p className="KioskSellers-description">{l.description}</p> : null}
          {l.review === 'rejected' && l.reviewNote ? <p className="KioskWarning">{l.reviewNote}</p> : null}
        </div>
        <div className="KioskSellers-actions">
          {l.review !== null ? (
            <Button className="Button Button--primary" loading={this.busy === l.id} onclick={() => this.review(l, 'approve')}>
              {t('admin.sellers.approve')}
            </Button>
          ) : (
            <span className="KioskSuccess">{t('admin.sellers.approved')}</span>
          )}
          {l.review !== 'rejected' ? (
            <Button className="Button" onclick={() => this.review(l, 'reject')}>
              {t('admin.sellers.reject')}
            </Button>
          ) : null}
        </div>
      </li>
    );
  }

  review(l: Listing, action: 'approve' | 'reject') {
    let note = '';
    if (action === 'reject') {
      const given = prompt(extractText(t('admin.sellers.reject_prompt')));
      if (given === null) return;
      note = given;
    }
    this.busy = l.id;
    api.reviewListing(l.id, action, note).then(
      () => {
        this.busy = null;
        this.load();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }

  connectStripe() {
    this.busy = 'stripe';
    this.stripeError = '';
    api.connectStripe({ sellers: true }).then(
      ({ data }) => {
        this.busy = null;
        if (!data.ok) this.stripeError = extractText(t('admin.sellers.stripe_failed', { error: data.error || '' }));
        this.attrs.onStripe(data.status);
        m.redraw();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }
}
