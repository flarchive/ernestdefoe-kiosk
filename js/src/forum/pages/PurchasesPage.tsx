import app from 'flarum/forum/app';
import Page from 'flarum/common/components/Page';
import PageStructure from 'flarum/forum/components/PageStructure';
import IndexSidebar from 'flarum/forum/components/IndexSidebar';
import Button from 'flarum/common/components/Button';
import LinkButton from 'flarum/common/components/LinkButton';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import extractText from 'flarum/common/utils/extractText';
import classList from 'flarum/common/utils/classList';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { date, money, priceLabel } from '../../common/money';
import { addSeat, cancel, goTo, myPurchases, removeSeat, store, switchPlan } from '../../common/api';
import type { Price, Purchase } from '../../common/api';

/** /store/purchases — what the member holds, when it renews or ends, and their payments. */
export default class PurchasesPage extends Page {
  purchases: Purchase[] = [];
  loading = true;
  busy: string | null = null;
  /** Each product's other recurring prices, for switching plan. */
  plans: Record<number, Price[]> = {};
  seatName: Record<string, string> = {};
  switchTo: Record<string, number> = {};

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    this.bodyClass = 'App--kiosk';

    if (!app.session.user) {
      m.route.set(app.route('kiosk.store'));
      return;
    }

    const title = extractText(t('forum.purchases.title'));
    app.setTitle(title);
    app.history.push('kiosk.purchases', title);

    myPurchases().then(
      ({ data }) => {
        this.purchases = data;
        this.loading = false;
        m.redraw();
        // Only fetched when something here could change plan.
        if (data.some((p) => this.canSwitch(p))) {
          store().then(({ data: products }) => {
            products.forEach((pr) => (this.plans[pr.id] = pr.prices.filter((x) => x.kind === 'recurring')));
            m.redraw();
          });
        }
      },
      () => {
        this.loading = false;
        m.redraw();
      }
    );
  }

  view() {
    return (
      <PageStructure className="KioskPurchasesPage" sidebar={() => <IndexSidebar />}>
        <div className="KioskPageHead">
          <h2 className="KioskPageHead-title">{t('forum.purchases.title')}</h2>
          <LinkButton className="Button" icon="fas fa-store" href={app.route('kiosk.store')}>
            {t('forum.store.title')}
          </LinkButton>
        </div>

        {this.loading ? (
          <LoadingIndicator display="block" />
        ) : this.purchases.length ? (
          <ul className="KioskPurchases">{this.purchases.map((p) => this.row(p))}</ul>
        ) : (
          <p className="KioskEmpty">{t('forum.purchases.empty')}</p>
        )}
      </PageStructure>
    );
  }

  row(p: Purchase): Mithril.Children {
    return (
      <li className={classList('KioskPurchase', { 'KioskPurchase--live': p.live })}>
        <div className="KioskPurchase-main">
          <h3 className="KioskPurchase-name">{p.productName}</h3>
          <span className={'KioskStatus KioskStatus--' + (p.live ? 'live' : p.status)}>
            {t('lib.status.' + (p.live ? (p.status === 'canceled' ? 'canceled' : 'active') : p.status))}
          </span>
        </div>
        <p className="KioskPurchase-terms">
          {p.role === 'seat' ? null : [p.provider === 'grant' && !p.couponCode ? t('forum.purchases.gift') : priceLabel(p), ' · ']}
          {this.when(p)}
        </p>
        {this.whose(p)}
        {p.unlocks.length ? (
          <div className="KioskCard-unlocks">
            {p.unlocks.map((u) =>
              u.url && p.live ? (
                <a className="KioskChip" href={u.url}>
                  {u.label}
                </a>
              ) : (
                <span className="KioskChip">{u.label || t('forum.purchases.removed')}</span>
              )
            )}
          </div>
        ) : null}
        {p.payments.length ? (
          <details className="KioskPurchase-payments">
            <summary>{t('forum.purchases.payments', { count: p.payments.length })}</summary>
            <ul>
              {p.payments.map((pay) => (
                <li>
                  {date(pay.createdAt)} — {money(pay.amountCents, pay.currency)}
                  {pay.status !== 'completed' ? ' (' + extractText(t('lib.payment_status.' + pay.status)) + ')' : ''}
                </li>
              ))}
            </ul>
            <small className="KioskPurchase-number">{t('forum.purchases.number', { number: p.number })}</small>
          </details>
        ) : null}
        {this.seats(p)}
        {this.plansFor(p)}
        {p.canCancel && p.role === 'owner' ? (
          <Button className="Button Button--link KioskPurchase-cancel" loading={this.busy === p.number} onclick={() => this.cancel(p)}>
            {t('forum.purchases.cancel')}
          </Button>
        ) : null}
      </li>
    );
  }

  /** A gift either way, or a seat on someone's team. */
  whose(p: Purchase): Mithril.Children {
    const line =
      p.role === 'giver' && p.giftedTo
        ? t('forum.purchases.gave_to', { name: p.giftedTo.displayName })
        : p.role === 'seat' && p.owner
          ? t('forum.purchases.seat_from', { name: p.owner.displayName })
          : p.giftedBy
            ? t('forum.purchases.gift_from', { name: p.giftedBy.displayName })
            : null;

    return line ? <p className="KioskPurchase-whose">{line}</p> : null;
  }

  seats(p: Purchase): Mithril.Children {
    if (p.role !== 'owner' || p.seats <= 1 || !p.live) return null;
    const full = p.seatHolders.length >= p.seats - 1;

    return (
      <div className="KioskPurchase-seats">
        <p className="KioskPurchase-seatsHead">{t('forum.purchases.seats_used', { used: p.seatHolders.length + 1, count: p.seats })}</p>
        <ul className="KioskPurchase-seatList">
          {p.seatHolders.map((h) => (
            <li>
              <span>{h.displayName}</span>
              <Button
                className="Button Button--icon Button--link"
                icon="fas fa-xmark"
                aria-label={extractText(t('forum.purchases.remove_seat', { name: h.displayName }))}
                onclick={() => this.update(p, () => removeSeat(p.number, h.id))}
              />
            </li>
          ))}
        </ul>
        {full ? null : (
          <div className="KioskCard-field">
            <input
              className="FormControl"
              aria-label={extractText(t('forum.purchases.add_seat_label'))}
              placeholder={extractText(t('forum.purchases.add_seat_label'))}
              autocapitalize="none"
              value={this.seatName[p.number] || ''}
              oninput={(e: InputEvent) => (this.seatName[p.number] = (e.target as HTMLInputElement).value)}
            />
            <Button
              className="Button"
              loading={this.busy === p.number}
              disabled={!(this.seatName[p.number] || '').trim()}
              onclick={() =>
                this.update(p, () => addSeat(p.number, this.seatName[p.number].trim().replace(/^@/, ''))).then(() => (this.seatName[p.number] = ''))
              }
            >
              {t('forum.purchases.add_seat')}
            </Button>
          </div>
        )}
      </div>
    );
  }

  canSwitch(p: Purchase): boolean {
    return p.role === 'owner' && p.kind === 'recurring' && p.status === 'active' && !p.cancelAtPeriodEnd && p.provider !== 'grant' && !!p.productId;
  }

  plansFor(p: Purchase): Mithril.Children {
    const others = this.canSwitch(p) ? (this.plans[p.productId!] || []).filter((x) => x.id !== p.priceId) : [];
    if (!others.length) return null;
    const chosen = this.switchTo[p.number] || others[0].id;

    return (
      <div className="KioskCard-field KioskPurchase-switch">
        <select
          className="FormControl"
          aria-label={extractText(t('forum.purchases.switch_label'))}
          value={String(chosen)}
          onchange={(e: Event) => (this.switchTo[p.number] = Number((e.target as HTMLSelectElement).value))}
        >
          {others.map((x) => (
            <option value={String(x.id)}>{priceLabel(x)}</option>
          ))}
        </select>
        <Button
          className="Button"
          loading={this.busy === p.number}
          onclick={() => {
            if (!confirm(extractText(t('forum.purchases.switch_confirm_' + (p.provider === 'stripe' ? 'stripe' : 'paypal'))))) return;
            this.update(p, () => switchPlan(p.number, chosen));
          }}
        >
          {t('forum.purchases.switch')}
        </Button>
      </div>
    );
  }

  /** Run an action on one purchase and put its new state in the list (or go where the provider says). */
  update(p: Purchase, action: () => Promise<{ data: Purchase | { url: string } }>): Promise<void> {
    this.busy = p.number;
    return action().then(
      ({ data }) => {
        if ('url' in data) {
          goTo(data.url);
          return;
        }
        this.purchases = this.purchases.map((x) => (x.number === data.number ? data : x));
        this.busy = null;
        m.redraw();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }

  when(p: Purchase): Mithril.Children {
    if (!p.periodEnd) return t('forum.purchases.forever');
    if (!p.live) return t('forum.purchases.ended_on', { date: date(p.periodEnd) });
    if (p.kind === 'recurring' && p.status === 'active') return t('forum.purchases.renews_on', { date: date(p.periodEnd) });

    return t('forum.purchases.until', { date: date(p.periodEnd) });
  }

  cancel(p: Purchase) {
    if (!confirm(extractText(t('forum.purchases.cancel_confirm', { date: date(p.periodEnd) })))) return;

    this.busy = p.number;
    cancel(p.number).then(
      ({ data }) => {
        this.purchases = this.purchases.map((x) => (x.number === data.number ? data : x));
        this.busy = null;
        m.redraw();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }
}
