import app from 'flarum/forum/app';
import { extend } from 'flarum/common/extend';
import CommentPost from 'flarum/forum/components/CommentPost';
import Discussion from 'flarum/common/models/Discussion';
import Badge from 'flarum/common/components/Badge';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';
import { t } from '../common/t';
import LockedNotice from './components/LockedNotice';

/**
 * A paid discussion seen from outside. The server already sent only the
 * opening words (and no replies); this fades them out and puts the offer
 * underneath, and marks the discussion with a lock in lists.
 */
export default function teaser() {
  // 🚨 A post only redraws when its data changes: without this, the offer
  // inside the teaser stays a loading spinner after it has loaded.
  extend(CommentPost.prototype, 'oninit', function (this: CommentPost) {
    if (this.attrs.post.attribute('kioskLocked')) this.subtree.check(() => LockedNotice.loads);
  });

  extend(CommentPost.prototype, 'classes', function (this: CommentPost, classes: string[]) {
    if (this.attrs.post.attribute('kioskLocked')) classes.push('KioskTeaserPost');
  });

  extend(CommentPost.prototype, 'content', function (this: CommentPost, content: Mithril.Children[]) {
    const post = this.attrs.post;
    if (!post.attribute('kioskLocked')) return;

    const discussion = post.discussion();
    content.push(
      <div className="KioskTeaser">
        <LockedNotice type="discussion" id={Number(discussion ? discussion.id() : 0)} name={discussion ? discussion.title() : undefined} />
      </div>
    );
  });

  extend(Discussion.prototype, 'badges', function (this: Discussion, badges: ItemList<Mithril.Children>) {
    if (this.attribute('kioskLocked')) {
      badges.add('kioskLocked', <Badge type="kioskLocked" icon="fas fa-lock" label={t('forum.teaser.badge')} />, 5);
    }
  });
}
