import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import IndexPage from 'flarum/forum/components/IndexPage';
import DiscussionPage from 'flarum/forum/components/DiscussionPage';
import DiscussionControls from 'flarum/forum/utils/DiscussionControls';
import Button from 'flarum/common/components/Button';
import Component from 'flarum/common/Component';
import LoadingIndicator from 'flarum/common/components/LoadingIndicator';
import Icon from 'flarum/common/components/Icon';
import type Discussion from 'flarum/common/models/Discussion';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import { t } from '../common/t';
import { money } from '../common/money';
import { bump, tips } from '../common/api';
import LockedNotice from './components/LockedNotice';

const tagIds = (discussion: Discussion): number[] =>
  (((discussion as any).tags?.() || []) as any[]).filter(Boolean).map((tag: any) => Number(tag.id()));

/** "Back this": what a discussion has raised, and the tip offer. Loads only on its page. */
class BackThis extends Component<{ discussion: Discussion; tagId: number }> {
  raised: { currency: string; totalCents: number; backers: number }[] | null = null;

  oninit(vnode: Mithril.Vnode<{ discussion: Discussion; tagId: number }, this>) {
    super.oninit(vnode);
    tips(Number(this.attrs.discussion.id())).then(({ data }) => {
      this.raised = data.raised;
      m.redraw();
    });
  }

  view() {
    const d = this.attrs.discussion;
    if (this.raised === null) return <LoadingIndicator size="small" display="block" />;

    return (
      <div className="KioskBackThis">
        {this.raised.length ? (
          <p className="KioskBackThis-raised">
            <Icon name="fas fa-hand-holding-heart" />{' '}
            {t('forum.tips.raised', {
              amount: this.raised.map((r) => money(r.totalCents, r.currency)).join(' + '),
              count: this.raised.reduce((n, r) => n + r.backers, 0),
            })}
          </p>
        ) : null}
        {app.session.user ? (
          <LockedNotice type="tip" id={this.attrs.tagId} title={t('forum.tips.title')} context={{ type: 'discussion', id: Number(d.id()) }} />
        ) : null}
      </div>
    );
  }
}

/** Paid posting on a tag's page, bumps on your own discussions, and tips. */
export default function tagActions() {
  // A tag where posting is paid and this visitor can't post yet: say how.
  extend(IndexPage.prototype, 'contentItems', function (items: ItemList<Mithril.Children>) {
    const tag = (app as any).currentTag?.();
    const locked = app.forum.attribute<number[]>('kioskPostTags') || [];
    if (!tag || !app.session.user || !locked.includes(Number(tag.id()))) return;

    items.add('kioskPost', <LockedNotice type="post" id={Number(tag.id())} title={t('forum.post_offer.title', { tag: tag.name() })} />, 95);
  });

  extend(DiscussionControls, 'userControls', function (items: ItemList<Mithril.Children>, discussion: Discussion) {
    const left = discussion.attribute<number>('kioskBumps') || 0;
    if (!left) return;

    items.add(
      'kioskBump',
      <Button
        icon="fas fa-arrow-up"
        onclick={() =>
          bump(Number(discussion.id())).then(({ data }) => {
            discussion.pushAttributes({ lastPostedAt: new Date(data.bumpedAt), kioskBumps: data.bumpsLeft });
            app.alerts.show({ type: 'success' }, t('forum.bump.done'));
            m.redraw();
          })
        }
      >
        {t('forum.bump.button', { count: left })}
      </Button>
    );
  });

  extend(DiscussionPage.prototype, 'sidebarItems', function (this: DiscussionPage, items: ItemList<Mithril.Children>) {
    const discussion = this.discussion;
    const tipTags = app.forum.attribute<number[]>('kioskTipTags') || [];
    const tagId = discussion && tagIds(discussion).find((id) => tipTags.includes(id));
    if (!discussion || !tagId) return;

    items.add('kioskBackThis', <BackThis discussion={discussion} tagId={tagId} />, -50);
  });
}
