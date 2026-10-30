import app from 'flarum/forum/app';
import { extend, override } from 'flarum/common/extend';
import DiscussionListState from 'flarum/forum/states/DiscussionListState';
import IndexPage from 'flarum/forum/components/IndexPage';
import Icon from 'flarum/common/components/Icon';
import Link from 'flarum/common/components/Link';
import classList from 'flarum/common/utils/classList';
import textContrastClass from 'flarum/common/helpers/textContrastClass';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import { t } from '../common/t';
import LockedNotice from './components/LockedNotice';

interface LockedTag {
  id: number;
  name: string;
  slug: string;
  description: string | null;
  color: string | null;
  icon: string | null;
}

const locked = (): LockedTag[] => app.forum.attribute<LockedTag[]>('kioskLockedTags') || [];
const isLocked = (slug: string | undefined): LockedTag | undefined =>
  slug ? locked().find((x) => x.slug.localeCompare(slug, undefined, { sensitivity: 'base' }) === 0) : undefined;

/**
 * A paid tag the visitor can't see stays visible as a locked tile, and its
 * own page shows what unlocks it, instead of vanishing (or a bare 404).
 */
export default function lockedTags() {
  // The tag's page: the discussion list becomes the offer.
  extend(IndexPage.prototype, 'contentItems', function (items: ItemList<Mithril.Children>) {
    const tag = isLocked(app.search.state.params().tags);
    if (!tag) return;

    items.remove('toolbar');
    items.remove('discussionList');
    // First in the list: themes add their own blocks here (Bespoke's phone
    // widgets), and the offer is what someone following the link came for.
    items.add('kioskLocked', <LockedNotice type="tag" id={tag.id} name={tag.name} />, 1000);
  });

  // 🚨 flarum/tags looks an unknown tag up again on every redraw: on a locked
  // tag's page that is a request storm, each one a 404. A locked tag is known
  // to be unreachable, so it is never looked up.
  const currentTag = (app as any).currentTag;
  if (typeof currentTag === 'function') {
    (app as any).currentTag = function (this: any, ...args: unknown[]) {
      if (isLocked(this.search.state.params().tags)) {
        this.currentActiveTag = undefined;
        return undefined;
      }

      return currentTag.apply(this, args);
    };
  }

  // Don't ask the API for a locked tag's discussions: it would answer
  // "not found" (they are not this visitor's to see) and log an error.
  // loadPage is protected, so the extend helper's types won't name it.
  (override as any)(
    DiscussionListState.prototype,
    'loadPage',
    function (this: DiscussionListState, original: (page?: number) => Promise<any>, page?: number) {
      if (isLocked((this as any).params?.tags)) return Promise.resolve(Object.assign([], { payload: { data: [], meta: {} } }));

      return original(page);
    }
  );

  // The Tags page: locked tiles after the ones they can open.
  extend('ext:flarum/tags/forum/components/TagsPage', 'contentItems', function (items: ItemList<Mithril.Children>) {
    const tags = locked();
    if (!tags.length || !items.has('tagTiles')) return;

    items.add(
      'kioskLocked',
      <div className="KioskLockedTags">
        <h3 className="KioskLockedTags-title">{t('forum.locked.tags_title')}</h3>
        <ul className="TagTiles">
          {tags.map((tag) => (
            <li
              className={classList('TagTile KioskLockedTile', { colored: tag.color }, tag.color ? textContrastClass(tag.color) : '')}
              style={{ '--tag-bg': tag.color || undefined }}
            >
              <Link className="TagTile-info" href={app.route('tag', { tags: tag.slug })}>
                <div className="TagTile-heading">
                  <Icon name="fas fa-lock" className="KioskLockedTile-lock" />
                  <h3 className="TagTile-name">{tag.name}</h3>
                </div>
                {tag.description ? <p className="TagTile-description">{tag.description}</p> : null}
                <span className="KioskLockedTile-cta">{t('forum.locked.see_how')}</span>
              </Link>
            </li>
          ))}
        </ul>
      </div>,
      95
    );
  });
}
