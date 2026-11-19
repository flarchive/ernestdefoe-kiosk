import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { date, money } from '../common/money';
import { transfers } from '../common/api';
import * as api from './api';
import type { AwaitingTransfer } from './api';

interface TransfersTabAttrs extends ComponentAttrs {
  /** Something changed: the page refreshes its numbers. */
  onchange: () => void;
}

/**
 * Orders waiting for a bank transfer, oldest first: the admin checks the
 * bank, finds the reference and presses Mark paid (or Cancel).
 */
export default class TransfersTab extends Component<TransfersTabAttrs> {
  rows: AwaitingTransfer[] | null = null;
  busy: string | null = null;

  oninit(vnode: Mithril.Vnode<TransfersTabAttrs, this>) {
    super.oninit(vnode);
    this.load();
  }

  load() {
    api.awaitingTransfers().then(({ data }) => {
      this.rows = data;
      this.busy = null;
      m.redraw();
    });
  }

  view() {
    if (!this.rows) return <LoadingIndicator />;

    return (
      <div className="KioskTransfers">
        <p className="helpText">{t('admin.transfers.help')}</p>
        {this.rows.length ? (
          <div className="KioskTableWrap">
            <table className="KioskTable">
              <thead>
                <tr>
                  <th>{t('admin.purchases.member')}</th>
                  <th>{t('admin.purchases.product')}</th>
                  <th>{t('admin.transfers.reference')}</th>
                  <th>{t('admin.transfers.ordered')}</th>
                  <th>{t('admin.transfers.expires')}</th>
                  <th>{t('admin.transfers.proof')}</th>
                  <th />
                </tr>
              </thead>
              <tbody>{this.rows.map((r) => this.row(r))}</tbody>
            </table>
          </div>
        ) : (
          <p className="KioskEmpty">{t('admin.transfers.empty')}</p>
        )}
      </div>
    );
  }

  row(r: AwaitingTransfer): Mithril.Children {
    const tr = r.transfer!;
    const amount = money(r.amountCents, r.currency);
    const name = r.user?.displayName || '';

    return (
      <tr>
        <td>
          {r.user ? (
            <a href={app.forum.attribute<string>('baseUrl') + '/u/' + r.user.username} target="_blank" rel="noopener">
              {name}
            </a>
          ) : (
            '—'
          )}
        </td>
        <td>
          {r.productName}
          <small className="KioskTable-sub">{amount}</small>
          {r.seller ? <small className="KioskTable-sub">{t('admin.transfers.paid_to', { name: r.seller.displayName })}</small> : null}
        </td>
        <td data-label={extractText(t('admin.transfers.reference'))}>
          <code className="KioskTransfers-reference">{tr.reference}</code>
        </td>
        <td data-label={extractText(t('admin.transfers.ordered'))}>{humanTime(new Date(r.createdAt))}</td>
        <td data-label={extractText(t('admin.transfers.expires'))}>{tr.expiresAt ? date(tr.expiresAt) : t('admin.transfers.never')}</td>
        <td data-label={extractText(t('admin.transfers.proof'))}>{this.proof(r)}</td>
        <td className="KioskTable-actions">
          <Button
            className="Button Button--primary"
            loading={this.busy === r.number + 'paid'}
            onclick={() =>
              this.act(r, 'paid', t('admin.transfers.mark_paid_confirm', { amount, reference: tr.reference, name, product: r.productName }))
            }
          >
            {t('admin.transfers.mark_paid')}
          </Button>
          <Button
            className="Button Button--link"
            loading={this.busy === r.number + 'cancel'}
            onclick={() => this.act(r, 'cancel', t('admin.transfers.cancel_confirm', { name, product: r.productName }))}
          >
            {t('admin.transfers.cancel')}
          </Button>
        </td>
      </tr>
    );
  }

  /** What the buyer sent: a note, a file, or nothing. */
  proof(r: AwaitingTransfer): Mithril.Children {
    const proof = r.transfer!.proof;
    if (!proof) return '—';

    return [
      proof.note ? <span className="KioskTransfers-note">{proof.note}</span> : null,
      proof.file ? (
        <a href={transfers.proofUrl(r.number)} target="_blank" rel="noopener">
          {t('admin.transfers.proof_file')}
        </a>
      ) : null,
    ];
  }

  act(r: AwaitingTransfer, action: 'paid' | 'cancel', question: Mithril.Children) {
    if (!confirm(extractText(question))) return;
    this.busy = r.number + action;
    transfers[action](r.number).then(
      () => {
        this.load();
        this.attrs.onchange();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }
}
