import { extend } from 'flarum/common/extend';
import app from 'flarum/forum/app';
import UserCard from 'flarum/forum/components/UserCard';
import type ItemList from 'flarum/common/utils/ItemList';
import type Mithril from 'mithril';

declare const m: import('mithril').Static;

const KEY = 'ernestdefoe-federation';

// Show a member's fediverse handle on their profile card, so people on Mastodon,
// Lemmy, etc. know what to follow. Rendered only when federation is enabled and
// the user exposes a handle (see the UserResource field in extend.php).
extend(UserCard.prototype, 'infoItems', function (this: any, items: ItemList<Mithril.Children>) {
  const user = this.attrs.user;
  const handle = user && user.attribute('federationHandle');

  if (!handle) return;

  items.add(
    'federationHandle',
    m('span.UserCard-federation', { title: app.translator.trans(`${KEY}.forum.handle_tooltip`) }, [
      app.translator.trans(`${KEY}.forum.handle_label`),
      ' ',
      m('code', handle),
    ]),
    -10
  );
});
