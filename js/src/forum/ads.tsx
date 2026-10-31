import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import Component from 'flarum/common/Component';
import Link from 'flarum/common/components/Link';
import type PageStructure from 'flarum/forum/components/PageStructure';
import type Mithril from 'mithril';
import { t } from '../common/t';

interface Ad {
  id: number;
  location: string;
  kind: 'image' | 'html';
  image: string | null;
  width: number | null;
  height: number | null;
  alt: string | null;
  link: string | null;
  html: string | null;
}

/*
 * The admin's ads. The server sends only the ones this visitor may see
 * (none to ad-free members), so this file just places them: one per spot,
 * picked again on each page, and cycling through the pool between posts
 * and discussions. Seen counts go back in batches, not one request each.
 */

const live = (): Ad[] => app.forum?.attribute<Ad[]>('kioskAds') || [];
const pool = (location: string) => live().filter((ad) => ad.location === location);

let route: string | undefined;
let picks: Record<string, Ad | undefined> = {};

/** One ad for this spot, the same until the visitor opens another page. */
function pick(location: string): Ad | undefined {
  if (m.route.get() !== route) {
    route = m.route.get();
    picks = {};
  }
  if (!(location in picks)) {
    const list = pool(location);
    picks[location] = list[Math.floor(Math.random() * list.length)];
  }
  return picks[location];
}

// ─── Counting ─────────────────────────────────────────────────────────────

let queue: Record<number, number> = {};
let timer: number | undefined;

function flush() {
  window.clearTimeout(timer);
  timer = undefined;
  if (!Object.keys(queue).length) return;
  const seen = queue;
  queue = {};
  send('/kiosk/ads/seen', { seen });
}

/** keepalive: still sent when the tab is closing, or a click is leaving the page. */
function send(path: string, data: object) {
  fetch(app.forum.attribute('apiUrl') + path, {
    method: 'POST',
    keepalive: true,
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': app.session.csrfToken || '' },
    body: JSON.stringify({ data }),
  }).catch(() => {});
}

function count(id: number) {
  queue[id] = (queue[id] || 0) + 1;
  timer ??= window.setTimeout(flush, 15000);
}

// ─── Rendering ────────────────────────────────────────────────────────────

class AdView extends Component<{ ad: Ad }> {
  observer?: IntersectionObserver;

  view() {
    const ad = this.attrs.ad;
    if (ad.kind === 'html') return <div className="KioskAd-html" />;

    const img = (
      <img src={ad.image!} width={ad.width || undefined} height={ad.height || undefined} alt={ad.alt || ''} loading="lazy" decoding="async" />
    );
    // Left and middle clicks both open it; a right-click to copy the link doesn't count.
    const clicked = (e: MouseEvent) => {
      if (e.button < 2) send('/kiosk/ads/' + ad.id + '/click', {});
    };

    return ad.link ? (
      <a className="KioskAd-image" href={ad.link} target="_blank" rel="sponsored noopener" onclick={clicked} onauxclick={clicked}>
        {img}
      </a>
    ) : (
      <div className="KioskAd-image">{img}</div>
    );
  }

  oncreate(vnode: Mithril.VnodeDOM<{ ad: Ad }, this>) {
    super.oncreate(vnode);
    const ad = this.attrs.ad;

    if (ad.kind === 'html') {
      const el = vnode.dom as HTMLElement;
      el.innerHTML = ad.html || '';
      // Scripts set through innerHTML never run: swap each for a fresh copy.
      el.querySelectorAll('script').forEach((old) => {
        const s = document.createElement('script');
        Array.from(old.attributes).forEach((a) => s.setAttribute(a.name, a.value));
        s.text = old.text;
        old.replaceWith(s);
      });
    }

    // Seen = at least half of it on screen, once per showing.
    this.observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((e) => e.isIntersecting)) {
          count(ad.id);
          this.observer?.disconnect();
        }
      },
      { threshold: 0.5 }
    );
    this.observer.observe(vnode.dom);
  }

  onbeforeupdate() {
    // The ad's own code may have changed its box; never redraw over it.
    return false;
  }

  onremove() {
    this.observer?.disconnect();
  }
}

/** `key` only where the spot sits among keyed siblings (lists of posts or discussions). */
function slot(ad: Ad | undefined, tag = 'div', key?: string): Mithril.Children {
  if (!ad) return null;
  const offer = app.forum.attribute<string | null>('kioskAdFreeProduct');
  const showLabel = app.forum.attribute('kioskAdsLabel') !== false;

  // Spots outside the page's container line up with the content.
  const wide = ['header', 'below_header', 'above_footer'].includes(ad.location);

  return m(tag, { className: 'KioskAd KioskAd--' + ad.location + (wide ? ' container' : ''), key }, [
    showLabel || offer ? (
      <div className="KioskAd-meta">
        {showLabel ? <span className="KioskAd-label">{t('forum.ads.label')}</span> : null}
        {offer ? (
          <Link className="KioskAd-remove" href={app.route('kiosk.store') + '?product=' + encodeURIComponent(offer)}>
            {t('forum.ads.remove')}
          </Link>
        ) : null}
      </div>
    ) : null,
    // Keyed on its own, so a different ad in the same spot gets a fresh view.
    [<AdView key={ad.id} ad={ad} />],
  ]);
}

/** Put an ad after every `every`-th keyed item that has more after it. */
function interleave(children: any[], every: number, location: string, isItem: (v: any) => boolean, offset = 0, tag = 'div'): number {
  const list = pool(location);
  if (!list.length || every < 1) return offset;

  for (let i = 0; i < children.length; i++) {
    if (!isItem(children[i])) continue;
    offset++;
    if (offset % every === 0 && children.slice(i + 1).some(isItem)) {
      const ad = list[(offset / every - 1) % list.length];
      // Two showings of the same ad on one page need their own keys.
      children.splice(++i, 0, slot(ad, tag, 'kiosk-ad-' + location + '-' + offset));
    }
  }

  return offset;
}

export default function ads() {
  // app.forum isn't loaded yet when initializers run: every hook asks at render
  // time, and with no ads each one adds nothing.
  // 🚨 Module names, not classes: the discussion page and its parts are lazy chunks in Flarum 2.
  extend('flarum/forum/components/PageStructure', 'mainItems', function (items: any) {
    items.add('kiosk-ad-header', slot(pick('header')), 150);
    items.add('kiosk-ad-below-header', slot(pick('below_header')), 50);
    items.add('kiosk-ad-above-footer', slot(pick('above_footer')), 5);
  });

  extend('flarum/forum/components/PageStructure', 'sidebarItems', function (this: PageStructure, items: any) {
    // The discussion page has its own spot.
    if (!this.attrs.sidebar || (this.attrs.className || '').includes('DiscussionPage')) return;
    items.add('kiosk-ad', slot(pick('sidebar')), -50);
  });

  extend('flarum/forum/components/DiscussionPage', 'sidebarItems', function (items: any) {
    items.add('kiosk-ad', slot(pick('discussion_sidebar')), -150);
  });

  override('flarum/forum/components/Footer', 'view', function (original: () => Mithril.Children) {
    return slot(pick('footer')) || original();
  });

  extend('flarum/forum/components/PostStream', 'afterFirstPostItems', function (items: any) {
    items.add('kiosk-ad', slot(pick('after_first_post')), 0);
  });

  extend('flarum/forum/components/PostStream', 'view', function (vdom: any) {
    const every = app.forum.attribute<number>('kioskAdsEveryPosts');
    if (!vdom?.children) return;
    interleave(vdom.children, every, 'between_posts', (v) => v?.attrs?.['data-number'] !== undefined);
  });

  extend('flarum/forum/components/DiscussionList', 'view', function (vdom: any) {
    const every = app.forum.attribute<number>('kioskAdsEveryDiscussions');
    const list = (vdom?.children || []).find((c: any) => String(c?.attrs?.className || '').includes('DiscussionList-discussions'));
    if (!list?.children) return;
    // One fragment per page of results; count across them.
    let offset = 0;
    list.children.forEach((page: any) => {
      if (page?.children)
        offset = interleave(page.children, every, 'between_discussions', (v) => v?.tag === 'li' && v.attrs?.['data-id'] !== undefined, offset, 'li');
    });
  });

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush();
  });
  window.addEventListener('pagehide', flush);
}
