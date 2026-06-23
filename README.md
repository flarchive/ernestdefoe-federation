# Federation (ActivityPub) for Flarum 2

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](./LICENSE)
[![Latest tag](https://img.shields.io/github/v/tag/ernestdefoe/federation?label=release)](https://github.com/ernestdefoe/federation/releases)
[![Flarum 2.0+](https://img.shields.io/badge/Flarum-2.0%2B-orange.svg)](https://flarum.org)

Put your Flarum community on the **fediverse**. Once enabled, anyone on
[Mastodon](https://joinmastodon.org), [Lemmy](https://join-lemmy.org), Misskey,
Akkoma or any other [ActivityPub](https://activitypub.rocks) server can **follow
your community** — and individual members — and receive every new discussion
right in their home timeline. Replies and likes from those servers flow back
into your forum as posts.

It implements the parts of ActivityPub that make a forum a good fediverse
citizen: WebFinger discovery, signed delivery (HTTP Signatures, the same scheme
Mastodon uses), a community **Service** actor, per-member **Person** actors, and
inbound Follow / Undo / Create / Delete handling.

> **No core files are modified.** Every endpoint lives under `/federation/*` and
> `/.well-known/*`, wired purely through Flarum's extender API, so the extension
> survives core updates untouched.

---

## Table of contents

- [How it works](#how-it-works)
- [What federates (and what doesn't)](#what-federates-and-what-doesnt)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Following your community](#following-your-community)
- [Endpoints](#endpoints)
- [Security](#security)
- [Troubleshooting](#troubleshooting)
- [FAQ](#faq)
- [License](#license)

---

## How it works

Your community is published as a single **`Service` actor** at
`@{community}@{your-host}`. Each member is *also* published as a **`Person`
actor** at `@{member}@{your-host}`.

When a member starts a new discussion:

1. The **community boosts (`Announce`)** the discussion's Note to everyone
   following the community. Because the boost comes from the actor those people
   actually follow, it lands in their home timeline — with the **original
   author's attribution preserved** (the remote server dereferences the Note and
   shows the member as the author). This is the standard fediverse "group/relay
   actor" pattern.
2. The discussion is *also* delivered as a normal `Create` to anyone following
   that **member** directly.

When someone on a remote server replies to a boosted/announced discussion, the
reply is verified and imported as a **federated post** in the thread, authored by
a mirrored local "federated" account. A remote `Delete` removes that post again.

> **Why a boost and not just a Create to community followers?** Mastodon's home
> timeline only shows posts from actors you follow. If member discussions were
> sent to community followers as a `Create` authored by the *member*, the remote
> server would accept them and then silently drop them (you don't follow that
> member). Announcing from the community actor is what makes "follow the
> community → see every discussion" actually work.

Outbound delivery is **queued** ([`DeliverActivity`](src/Job/DeliverActivity.php)),
so a slow or dead remote never blocks a page load.

## What federates (and what doesn't)

| Event | Behavior |
| --- | --- |
| New public discussion | Boosted to community followers + `Create` to the author's own followers |
| Remote **Follow** of the community or a member | Stored; a signed `Accept` is returned |
| Remote **Undo Follow** | Follower removed |
| Remote **reply** to a federated discussion | Imported as a post in the thread |
| Remote **Delete** | The imported federated post is removed |
| Replies by your local members | Delivered to the author's followers + remote thread participants (not boomed to all community followers, to avoid timeline noise) |
| **Private** discussions, **hidden** discussions, PMs | Never federated |

## Requirements

- **Flarum `^2.0`**
- **PHP 8.3+** with the **OpenSSL** extension (used for HTTP Signatures)
- A **publicly reachable HTTPS** host. The fediverse fetches your actor and Note
  documents to verify signatures — `localhost`/LAN-only installs can be followed
  from another machine on the same network, but not from public servers like
  mastodon.social.
- **Recommended:** a real queue driver (`database`, `redis`, …) and a running
  `php flarum queue:work`. Federation works on the default `sync` driver too, but
  then delivery happens inline during the request that created the discussion.

## Installation

```bash
composer require ernestdefoe/federation
php flarum migrate
php flarum cache:clear
```

Then open **Admin → Extensions**, enable **Federation (ActivityPub)**, and turn
on the **Enable federation** setting (see below).

To update later:

```bash
composer update ernestdefoe/federation
php flarum migrate
php flarum cache:clear
```

## Configuration

**Admin → Federation (ActivityPub):**

- **Enable federation** — the master switch. While off, *every* federation
  endpoint returns `404` and nothing is delivered, so the community is invisible
  to the fediverse.
- **Community handle name** — the username part of the community handle. Leave
  blank to use a slug of your forum title. Letters, numbers and underscores only.
  The page shows your live handle, e.g. `@forum@example.com`.

A 2048-bit RSA keypair for the community is generated automatically the first
time it's needed and stored in settings; each member's keypair is generated
lazily the first time their actor is fetched. There is nothing to configure by
hand.

> ⚠️ **Pick the handle name once.** Changing it after people have followed will
> break their existing follows (the handle they followed no longer resolves).

## Following your community

From any fediverse server, search for your handle and hit follow:

```
@your-handle@your-host        ← the whole community (every new discussion)
@member-username@your-host    ← one member (their discussions only)
```

A member's handle is shown on their profile card once federation is enabled.

## Endpoints

All are read-only `GET` unless noted, and all return `404` while federation is
disabled.

| Path | Purpose |
| --- | --- |
| `/.well-known/webfinger?resource=acct:{user}@{host}` | Discovery → resolves to the community or a member actor |
| `/.well-known/nodeinfo`, `/nodeinfo/2.0` | Server metadata for crawlers |
| `/federation/actor` | The community `Service` actor |
| `/federation/outbox` | Recent public discussions as `Create` activities |
| `/federation/followers` | The community's followers collection |
| `/federation/inbox` (POST) | Signed inbox for the community |
| `/federation/notes/{id}` | The `Note` object for a discussion |
| `/federation/users/{id}/actor` | A member `Person` actor |
| `/federation/users/{id}/outbox` | A member's recent discussions |
| `/federation/users/{id}/followers` | A member's followers collection |
| `/federation/users/{id}/inbox` (POST) | Signed inbox for a member |

## Security

- **Inbound** requests to either inbox must carry a valid **HTTP Signature**
  (`rsa-sha256`, draft-cavage). The signature is verified against the sending
  actor's public key, and when a body digest is signed it must match the body.
  Unsigned or bad-signature requests get `401`. The inbox routes are
  CSRF-exempt (remote servers can't send a CSRF token) via Flarum's
  `Extend\Csrf` extender — no other route is affected.
- **Outbound** requests are signed as the community actor, or as the member when
  delivering a member's own activity.
- Federated (remote-mirrored) accounts get an unguessable random password and a
  non-routable `@federated.invalid` email; they can't log in.
- Only **public, visible** discussions are ever exposed or delivered.

## Troubleshooting

- **"I followed the community but see nothing in my timeline."** Make sure your
  host is publicly reachable over HTTPS with a valid certificate, that a
  `queue:work` process is running (or you're on the `sync` driver), and that the
  discussion is public. Confirm `GET https://your-host/federation/actor` returns
  JSON with `Content-Type: application/activity+json`.
- **Search can't find the handle.** WebFinger must resolve at the host *in the
  handle*. Check
  `https://your-host/.well-known/webfinger?resource=acct:your-handle@your-host`
  returns `200`.
- **Everything returns 404.** Federation is disabled — enable it in admin.
- **Replies aren't coming back in.** The remote reply must be `inReplyTo` one of
  your `/federation/notes/{id}` URLs, and your inbox must be reachable; check
  your server logs for `[federation]` debug lines.

## FAQ

**Does this modify core or other extensions?** No. It only adds routes, a couple
of nullable columns (on `users`/`posts`) and one table (`federation_followers`),
all through the extender API.

**Can I use a subdomain handle like `@forum@community.example.com`?** Yes — the
handle host always matches your forum's configured URL. Whatever host your forum
runs on is the host in the handle.

**What happens to existing discussions?** They're listed in the outbox and are
fetchable as Notes, but only discussions created *after* someone follows you are
pushed to their timeline (that's how the fediverse works).

**Disable cleanly?** Turning the setting off makes the community disappear from
the fediverse immediately. Disabling the extension stops everything; the data
columns/table remain until you uninstall.

## License

[MIT](./LICENSE) © ernestdefoe
