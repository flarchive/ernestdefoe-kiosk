import app from 'flarum/admin/app';
import Component from 'flarum/common/Component';
import Button from 'flarum/common/components/Button';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { date } from '../common/money';
import * as api from './api';
import type { AdRow } from './api';
import AdModal from './AdModal';

/** The admin's ads: each with where it runs, who sees it, when, and how it is doing. */
export default class AdsTab extends Component {
  ads: AdRow[] | null = null;

  oninit(vnode: Mithril.Vnode) {
    super.oninit(vnode);
    api.ads().then(({ data }) => {
      this.ads = data;
      m.redraw();
    });
  }

  view() {
    return (
      <div className="KioskAds">
        <div className="KioskAds-head">
          <p className="helpText">{t('admin.ads.intro')}</p>
          <Button className="Button Button--primary" icon="fas fa-plus" onclick={() => this.edit()}>
            {t('admin.ads.new')}
          </Button>
        </div>

        {!this.ads ? (
          <LoadingIndicator />
        ) : !this.ads.length ? (
          <p className="KioskEmpty">{t('admin.ads.empty')}</p>
        ) : (
          <div className="KioskTableWrap">
            <table className="KioskTable KioskAds-table">
              <thead>
                <tr>
                  <th>{t('admin.ads.name')}</th>
                  <th>{t('admin.ads.location')}</th>
                  <th>{t('admin.ads.audience')}</th>
                  <th>{t('admin.ads.runs')}</th>
                  <th>{t('admin.ads.seen')}</th>
                  <th>{t('admin.ads.clicks')}</th>
                  <th />
                </tr>
              </thead>
              <tbody>{this.ads.map((ad) => this.row(ad))}</tbody>
            </table>
          </div>
        )}
      </div>
    );
  }

  row(ad: AdRow): Mithril.Children {
    const ctr = ad.impressions ? ((ad.clicks / ad.impressions) * 100).toFixed(1) + '%' : null;

    return (
      <tr key={ad.id} className={ad.active ? '' : 'KioskAds-off'}>
        <td>
          <span className="KioskAds-name">
            {ad.kind === 'image' && ad.imageUrl ? (
              <img className="KioskAds-thumb" src={ad.imageUrl} alt="" />
            ) : (
              <Icon name="fas fa-code" className="KioskAds-thumb" />
            )}
            <span>
              {ad.name}
              {ad.active ? null : <small className="KioskTable-sub">{t('admin.ads.off')}</small>}
            </span>
          </span>
        </td>
        <td>{t('admin.ads.locations.' + ad.location)}</td>
        <td>{t('admin.ads.audiences.' + ad.audience)}</td>
        <td>
          {ad.startsAt || ad.endsAt ? (ad.startsAt ? date(ad.startsAt) : '…') + ' – ' + (ad.endsAt ? date(ad.endsAt) : '…') : t('admin.ads.always')}
        </td>
        <td>{ad.impressions.toLocaleString()}</td>
        <td>
          {ad.kind === 'image' ? ad.clicks.toLocaleString() : '—'}
          {ad.kind === 'image' && ctr ? <small className="KioskTable-sub">{ctr}</small> : null}
        </td>
        <td className="KioskTable-actions">
          <Button className="Button Button--icon" icon="fas fa-pen" aria-label={extractText(t('admin.ads.edit'))} onclick={() => this.edit(ad)} />
          <Button
            className="Button Button--icon"
            icon="fas fa-trash"
            aria-label={extractText(t('admin.ads.delete'))}
            onclick={() => {
              if (!confirm(extractText(t('admin.ads.delete_confirm', { name: ad.name })))) return;
              api.deleteAd(ad.id).then(() => {
                this.ads = (this.ads || []).filter((x) => x.id !== ad.id);
                m.redraw();
              });
            }}
          />
        </td>
      </tr>
    );
  }

  edit(ad?: AdRow) {
    app.modal.show(AdModal, {
      ad,
      onsaved: (saved: AdRow) => {
        const list = this.ads || [];
        const keep = { impressions: ad?.impressions || 0, clicks: ad?.clicks || 0 };
        this.ads = list.some((x) => x.id === saved.id) ? list.map((x) => (x.id === saved.id ? { ...saved, ...keep } : x)) : [saved, ...list];
        m.redraw();
      },
    });
  }
}
