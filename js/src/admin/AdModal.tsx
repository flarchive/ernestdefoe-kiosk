import FormModal, { type IFormModalAttrs } from 'flarum/common/components/FormModal';
import Button from 'flarum/common/components/Button';
import Select from 'flarum/common/components/Select';
import Switch from 'flarum/common/components/Switch';
import extractText from 'flarum/common/utils/extractText';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { type AdRow, LOCATIONS, saveAd, uploadAdImage } from './api';

interface Attrs extends IFormModalAttrs {
  ad?: AdRow;
  onsaved: (ad: AdRow) => void;
}

const day = (iso: string | null) => (iso ? iso.slice(0, 10) : '');

/** Make or change an ad: a picture with a link, or pasted code; where it goes, who sees it, and when. */
export default class AdModal extends FormModal<Attrs> {
  f = {
    name: '',
    location: 'sidebar',
    kind: 'image',
    linkUrl: '',
    altText: '',
    html: '',
    audience: 'everyone',
    active: true,
    startsAt: '',
    endsAt: '',
  };
  file: File | null = null;
  preview: string | null = null;
  /** Set once saved, so a retry after a failed upload changes it rather than adding another. */
  savedId?: number;

  oninit(vnode: Mithril.Vnode<Attrs, this>) {
    super.oninit(vnode);
    const ad = this.attrs.ad;
    if (ad) {
      this.f = {
        name: ad.name,
        location: ad.location,
        kind: ad.kind,
        linkUrl: ad.linkUrl || '',
        altText: ad.altText || '',
        html: ad.html || '',
        audience: ad.audience,
        active: ad.active,
        startsAt: day(ad.startsAt),
        endsAt: day(ad.endsAt),
      };
      this.preview = ad.imageUrl;
    }
  }

  className() {
    return 'KioskAdModal Modal--medium';
  }

  title() {
    return this.attrs.ad ? t('admin.ads.edit') : t('admin.ads.new');
  }

  content() {
    const f = this.f;
    const locations: Record<string, string> = {};
    LOCATIONS.forEach((l) => (locations[l] = extractText(t('admin.ads.locations.' + l))));
    const audiences: Record<string, string> = {};
    ['everyone', 'guests', 'members'].forEach((a) => (audiences[a] = extractText(t('admin.ads.audiences.' + a))));
    const input = (key: keyof typeof f, attrs: Record<string, unknown> = {}) => (
      <input
        className="FormControl"
        value={f[key] as string}
        oninput={(e: InputEvent) => ((f as any)[key] = (e.target as HTMLInputElement).value)}
        {...attrs}
      />
    );

    return (
      <div className="Modal-body">
        <div className="Form">
          <div className="Form-group">
            <label>{t('admin.ads.name')}</label>
            {input('name', { maxlength: 150, placeholder: extractText(t('admin.ads.name_placeholder')) })}
          </div>
          <div className="Form-group">
            <label>{t('admin.ads.location')}</label>
            <Select options={locations} value={f.location} onchange={(v: string) => (f.location = v)} />
            <p className="helpText">{t('admin.ads.location_help.' + f.location)}</p>
          </div>
          <div className="Form-group">
            <label>{t('admin.ads.kind')}</label>
            <Select
              options={{ image: extractText(t('admin.ads.kinds.image')), html: extractText(t('admin.ads.kinds.html')) }}
              value={f.kind}
              onchange={(v: string) => (f.kind = v)}
            />
          </div>

          {f.kind === 'image' ? (
            <>
              <div className="Form-group">
                <label>{t('admin.ads.image')}</label>
                {this.preview ? <img className="KioskAdModal-preview" src={this.preview} alt="" /> : null}
                <input
                  className="KioskAdModal-file"
                  type="file"
                  accept="image/png,image/jpeg,image/gif,image/webp"
                  onchange={(e: Event) => this.choose((e.target as HTMLInputElement).files?.[0] || null)}
                />
                <p className="helpText">{t('admin.ads.image_help')}</p>
              </div>
              <div className="Form-group">
                <label>{t('admin.ads.link')}</label>
                {input('linkUrl', { type: 'url', placeholder: 'https://' })}
              </div>
              <div className="Form-group">
                <label>{t('admin.ads.alt')}</label>
                {input('altText', { maxlength: 191 })}
              </div>
            </>
          ) : (
            <div className="Form-group">
              <label>{t('admin.ads.html')}</label>
              <textarea
                className="FormControl KioskAdModal-code"
                rows={6}
                value={f.html}
                spellcheck={false}
                oninput={(e: InputEvent) => (f.html = (e.target as HTMLTextAreaElement).value)}
              />
              <p className="helpText">{t('admin.ads.html_help')}</p>
            </div>
          )}

          <div className="Form-group">
            <label>{t('admin.ads.audience')}</label>
            <Select options={audiences} value={f.audience} onchange={(v: string) => (f.audience = v)} />
          </div>
          <div className="Form-group KioskAdModal-dates">
            <label>
              <span>{t('admin.ads.starts')}</span>
              {input('startsAt', { type: 'date' })}
            </label>
            <label>
              <span>{t('admin.ads.ends')}</span>
              {input('endsAt', { type: 'date' })}
            </label>
          </div>
          <div className="Form-group">
            <Switch state={f.active} onchange={(v: boolean) => (f.active = v)}>
              {t('admin.ads.active')}
            </Switch>
          </div>
          <div className="Form-group">
            <Button
              type="submit"
              className="Button Button--primary"
              loading={this.loading}
              disabled={!f.name.trim() || (f.kind === 'image' ? !this.preview : !f.html.trim())}
            >
              {t('admin.ads.save')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  choose(file: File | null) {
    this.file = file;
    if (this.preview?.startsWith('blob:')) URL.revokeObjectURL(this.preview);
    this.preview = file ? URL.createObjectURL(file) : this.attrs.ad?.imageUrl || null;
  }

  onsubmit(e: SubmitEvent) {
    e.preventDefault();
    this.loading = true;
    const f = this.f;
    const body = { ...f, name: f.name.trim(), startsAt: f.startsAt || null, endsAt: f.endsAt || null };

    saveAd(body, this.savedId ?? this.attrs.ad?.id)
      .then(({ data }) => {
        this.savedId = data.id;
        return this.file ? uploadAdImage(data.id, this.file).then((r) => r.data) : data;
      })
      .then(
        (ad) => {
          this.attrs.onsaved(ad);
          this.hide();
        },
        () => {
          this.loading = false;
          m.redraw();
        }
      );
  }

  onremove(vnode: Mithril.VnodeDOM<Attrs, this>) {
    super.onremove(vnode);
    if (this.preview?.startsWith('blob:')) URL.revokeObjectURL(this.preview);
  }
}
