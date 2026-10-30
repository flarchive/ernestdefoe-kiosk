import app from 'flarum/admin/app';
import Component, { type ComponentAttrs } from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import Icon from 'flarum/common/components/Icon';
import Switch from 'flarum/common/components/Switch';
import classList from 'flarum/common/utils/classList';
import { t } from '../common/t';
import * as api from './api';
import type { CryptoStatus } from './api';

export interface CryptoCardsAttrs extends ComponentAttrs {
  status: CryptoStatus;
  onchange: () => void;
}

/**
 * BTCPay Server and NOWPayments. One-time and set-time prices only: a crypto
 * payment can't be taken again, so nothing renews by itself.
 */
export default class CryptoCards extends Component<CryptoCardsAttrs> {
  btcpayUrl = '';
  apiKey = '';
  ipnSecret = '';
  sandbox = false;
  busy: string | null = null;

  oninit(vnode: any) {
    super.oninit(vnode);
    this.btcpayUrl = this.attrs.status.btcpay.url || '';
    this.sandbox = this.attrs.status.nowpayments.sandbox;
  }

  view() {
    const { btcpay, nowpayments } = this.attrs.status;
    const back = m.route.param('btcpay');

    return [
      <section className="KioskProvider">
        <header className="KioskProvider-head">
          <Icon name="fab fa-bitcoin" />
          <h3>BTCPay Server</h3>
          {this.badge(btcpay.connected)}
        </header>
        <p className="helpText">{t('admin.btcpay.help')}</p>
        {back && back !== 'connected' ? (
          <p className="KioskWarning">
            <Icon name="fas fa-triangle-exclamation" /> {t('admin.btcpay.' + (back === 'expired' ? 'expired' : 'failed'))}
          </p>
        ) : null}
        {btcpay.connected ? (
          <p className="KioskSuccess">
            <Icon name="fas fa-circle-check" /> {t('admin.btcpay.connected_to', { url: btcpay.url, store: btcpay.storeId })}
          </p>
        ) : null}
        <div className="Form-group">
          <label>{t('admin.btcpay.url')}</label>
          <input
            className="FormControl"
            type="url"
            placeholder="https://btcpay.example.com"
            value={this.btcpayUrl}
            oninput={(e: InputEvent) => (this.btcpayUrl = (e.target as HTMLInputElement).value.trim())}
          />
        </div>
        <div className="KioskToolbar">
          <Button className="Button Button--primary" loading={this.busy === 'btcpay'} disabled={!this.btcpayUrl} onclick={() => this.connectBtcpay()}>
            {btcpay.connected ? t('admin.payments.reconnect') : t('admin.payments.connect')}
          </Button>
          {btcpay.connected ? (
            <Button className="Button" onclick={() => this.disconnect('btcpay')}>
              {t('admin.payments.disconnect')}
            </Button>
          ) : null}
        </div>
      </section>,

      <section className="KioskProvider">
        <header className="KioskProvider-head">
          <Icon name="fas fa-coins" />
          <h3>NOWPayments</h3>
          {this.badge(nowpayments.connected)}
          {nowpayments.connected && nowpayments.sandbox ? <span className="KioskTag">{t('admin.payments.test_now')}</span> : null}
        </header>
        <p className="helpText">{t('admin.nowpayments.help')}</p>
        <div className="Form-group">
          <label>{t('admin.nowpayments.api_key')}</label>
          <input
            className="FormControl"
            type="password"
            autocomplete="new-password"
            placeholder={nowpayments.keyHint || ''}
            value={this.apiKey}
            oninput={(e: InputEvent) => (this.apiKey = (e.target as HTMLInputElement).value.trim())}
          />
        </div>
        <div className="Form-group">
          <label>{t('admin.nowpayments.ipn_secret')}</label>
          <input
            className="FormControl"
            type="password"
            autocomplete="new-password"
            value={this.ipnSecret}
            oninput={(e: InputEvent) => (this.ipnSecret = (e.target as HTMLInputElement).value.trim())}
          />
        </div>
        <div className="Form-group">
          <Switch state={this.sandbox} onchange={(v: boolean) => (this.sandbox = v)}>
            {t('admin.nowpayments.sandbox')}
          </Switch>
        </div>
        <div className="KioskToolbar">
          <Button
            className="Button Button--primary"
            loading={this.busy === 'nowpayments'}
            disabled={!this.apiKey || !this.ipnSecret}
            onclick={() => this.saveNowpayments()}
          >
            {t('admin.payments.connect')}
          </Button>
          {nowpayments.connected ? (
            <Button className="Button" onclick={() => this.disconnect('nowpayments')}>
              {t('admin.payments.disconnect')}
            </Button>
          ) : null}
        </div>
      </section>,
    ];
  }

  badge(connected: boolean) {
    return (
      <span className={classList('KioskStatus', connected ? 'KioskStatus--active' : 'KioskStatus--pending')}>
        {connected ? t('admin.payments.connected') : t('admin.payments.not_connected')}
      </span>
    );
  }

  connectBtcpay() {
    this.busy = 'btcpay';
    api.connectBtcpay(this.btcpayUrl).then(
      ({ data }) => {
        // BTCPay's own page asks which store, then sends the admin back here.
        window.location.href = data.url;
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }

  saveNowpayments() {
    this.busy = 'nowpayments';
    api.connectNowpayments({ apiKey: this.apiKey, ipnSecret: this.ipnSecret, sandbox: this.sandbox }).then(
      () => {
        this.apiKey = this.ipnSecret = '';
        this.busy = null;
        app.alerts.show({ type: 'success' }, t('admin.nowpayments.ok'));
        this.attrs.onchange();
      },
      () => {
        this.busy = null;
        m.redraw();
      }
    );
  }

  disconnect(key: 'btcpay' | 'nowpayments') {
    if (!confirm(String(t('admin.payments.disconnect_confirm')))) return;
    api.disconnectCrypto(key).then(() => this.attrs.onchange());
  }
}
