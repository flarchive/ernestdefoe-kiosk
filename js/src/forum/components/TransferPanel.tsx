import app from 'flarum/forum/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import humanTime from 'flarum/common/helpers/humanTime';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../../common/t';
import { date, money } from '../../common/money';
import { transfers } from '../../common/api';
import type { Purchase } from '../../common/api';

export interface TransferPanelAttrs extends ComponentAttrs {
  purchase: Purchase;
  onchange: (p: Purchase | null) => void;
}

/**
 * An order waiting for a bank transfer, as its payer sees it: how much, where
 * to, the reference to quote, an optional proof of payment, and Cancel.
 */
export default class TransferPanel extends Component<TransferPanelAttrs> {
  file: File | null = null;
  note = '';
  busy: 'proof' | 'cancel' | null = null;
  copied = false;

  view() {
    const p = this.attrs.purchase;
    const tr = p.transfer!;

    return (
      <section className="KioskTransfer">
        <h2 className="KioskTransfer-title">
          <Icon name="fas fa-building-columns" /> {t('forum.transfer.title')}
        </h2>
        <p>{t('forum.transfer.waiting', { product: p.productName })}</p>

        <dl className="KioskTransfer-facts">
          <dt>{t('forum.transfer.amount')}</dt>
          <dd className="KioskTransfer-amount">{money(p.amountCents, p.currency)}</dd>
          <dt>{t('forum.transfer.pay_to')}</dt>
          <dd className="KioskTransfer-details">{tr.details}</dd>
          <dt>{t('forum.transfer.reference')}</dt>
          <dd>
            <code className="KioskTransfer-reference">{tr.reference}</code>{' '}
            <Button
              className="Button Button--link KioskTransfer-copy"
              icon={this.copied ? 'fas fa-check' : 'far fa-copy'}
              onclick={() => this.copy(tr.reference)}
            >
              {t(this.copied ? 'forum.transfer.copied' : 'forum.transfer.copy')}
            </Button>
            <small className="helpText">{t('forum.transfer.reference_help')}</small>
          </dd>
        </dl>

        {tr.instructions ? <p className="KioskTransfer-instructions">{tr.instructions}</p> : null}
        {tr.expiresAt ? <p className="KioskTransfer-deadline">{t('forum.transfer.expires', { date: date(tr.expiresAt) })}</p> : null}
        <p className="helpText">{t('forum.transfer.next')}</p>

        {this.proofForm()}

        <Button className="Button Button--link KioskTransfer-cancel" loading={this.busy === 'cancel'} onclick={() => this.cancel()}>
          {t('forum.transfer.cancel')}
        </Button>
      </section>
    );
  }

  /** An optional proof of payment: a picture or PDF, and/or a note. */
  proofForm(): Mithril.Children {
    const p = this.attrs.purchase;
    const tr = p.transfer!;

    return (
      <form className="KioskTransfer-proof" onsubmit={(e: SubmitEvent) => this.send(e)}>
        <h3>{t('forum.transfer.proof_title')}</h3>
        <p className="helpText">{t('forum.transfer.proof_help')}</p>
        {tr.proof ? (
          <p className="KioskSuccess">
            <Icon name="fas fa-circle-check" /> {t('forum.transfer.proof_sent', { ago: humanTime(new Date(tr.proof.at)) })}{' '}
            {tr.proof.file ? (
              <a href={transfers.proofUrl(p.number)} target="_blank" rel="noopener">
                {t('forum.transfer.proof_view')}
              </a>
            ) : null}
          </p>
        ) : null}
        <label className="KioskTransfer-label">
          {t('forum.transfer.proof_file')}
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp,application/pdf"
            onchange={(e: Event) => (this.file = (e.target as HTMLInputElement).files?.[0] || null)}
          />
        </label>
        <label className="KioskTransfer-label">
          {t('forum.transfer.proof_note')}
          <textarea
            className="FormControl"
            rows={2}
            maxlength={500}
            value={this.note}
            oninput={(e: InputEvent) => (this.note = (e.target as HTMLTextAreaElement).value)}
          />
        </label>
        <Button type="submit" className="Button" loading={this.busy === 'proof'} disabled={!this.file && !this.note.trim()}>
          {t('forum.transfer.proof_send')}
        </Button>
      </form>
    );
  }

  copy(text: string) {
    navigator.clipboard?.writeText(text).then(() => {
      this.copied = true;
      m.redraw();
    });
  }

  send(e: SubmitEvent) {
    e.preventDefault();
    this.busy = 'proof';
    transfers.proof(this.attrs.purchase.number, this.file, this.note).then(
      ({ data }) => {
        this.busy = null;
        this.file = null;
        this.note = '';
        (e.target as HTMLFormElement).reset();
        this.attrs.onchange(data);
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }

  cancel() {
    if (!confirm(extractText(t('forum.transfer.cancel_confirm')))) return;
    this.busy = 'cancel';
    transfers.cancel(this.attrs.purchase.number).then(
      (reply) => this.attrs.onchange(reply ? reply.data : null),
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }
}
