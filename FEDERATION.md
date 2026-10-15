# Federation

This document follows [FEP-67ff](https://codeberg.org/fediverse/fep/src/branch/main/fep/67ff/fep-67ff.md)
and describes how this Flarum extension federates over
[ActivityPub](https://www.w3.org/TR/activitypub/).

Federation is **off by default** and enabled by an admin in the extension
settings. Once enabled the forum becomes a single followable actor and each
member can optionally federate as their own actor.

## Supported protocols & discovery

- **ActivityPub** (S2S) — actors, activities and objects.
- **WebFinger** (`/.well-known/webfinger`) — resolves `acct:user@host` to an
  actor, for the community and individual members.
- **NodeInfo 2.0** ([FEP-f1d5](https://codeberg.org/fediverse/fep/src/branch/main/fep/f1d5/fep-f1d5.md))
  at `/.well-known/nodeinfo` → `/nodeinfo/2.0`.
- **HTTP Signatures** (draft-cavage, `rsa-sha256`) with a SHA-256 `Digest` on
  every inbound and outbound request. Remote actors are fetched with signed
  `GET`s ("authorized fetch").

## Actors

- **Community** — a single `Group` actor
  ([FEP-1b12](https://codeberg.org/fediverse/fep/src/branch/main/fep/1b12/fep-1b12.md)).
  Following it delivers every new discussion to your inbox as an `Announce`.
- **Members** — each non-system member may federate as a `Person` actor at
  `/federation/users/{id}/actor`, discoverable as `@{username}@{host}`.

Each actor publishes `inbox`, `outbox`, `followers`, `preferredUsername`,
`name`, `summary`, `url`, `icon`, `manuallyApprovesFollowers: false`,
`discoverable: true`, a legacy `publicKey` (RSA-2048) **and** a FEP-521a
`assertionMethod` `Multikey`
([FEP-521a](https://codeberg.org/fediverse/fep/src/branch/main/fep/521a/fep-521a.md))
— the same key in both — plus a `schema:PropertyValue` `attachment` row.

## Activities

**Outbound:** `Create` (`Note`) for new discussions and replies (attributed to
the member author); `Announce` (the community boosts each new discussion's
`Note` to its followers, preserving the author's attribution); `Accept` in
response to an inbound `Follow`.

**Inbound** (to `/federation/inbox` or `/federation/users/{id}/inbox`):
`Follow` / `Undo`(Follow) — add/remove a follower, returning a signed `Accept`;
`Create` (`Note` with `inReplyTo`) — imported as a reply; `Delete` — removes a
previously imported remote reply.

## Objects

`Note` objects carry `attributedTo`, `to`/`cc` (`as:Public` + followers),
`content`, `url`, `published`, `inReplyTo` (replies), and `tag` `Hashtag`s
derived from the discussion's tags (when **flarum/tags** is installed).

## JSON-LD `@context`

Documents use `https://www.w3.org/ns/activitystreams`,
`https://w3id.org/security/v1`, and an inline extension object defining the
Mastodon/FEP terms we emit (`Hashtag`, `sensitive`, `discoverable`,
`manuallyApprovesFollowers`, `PropertyValue`, `Multikey`, `assertionMethod`,
`publicKeyMultibase`).

## Implemented FEPs

- **FEP-67ff** — this document.
- **FEP-f1d5** — NodeInfo.
- **FEP-1b12** — `Group` actor federation.
- **FEP-521a** — actor public keys as a `Multikey` (`assertionMethod`).

## Not yet implemented

- `sharedInbox` advertising (per-actor inboxes only).
- Outbound `Update`/`Delete`/`Like`; `following` and `featured` collections.
- Object integrity proofs (FEP-8b32) — integrity is at the transport layer via
  HTTP Signatures.
- FEP-8fcf followers-collection synchronization.

## Threat model / security notes

Outbound requests are SSRF-guarded (private-range + DNS-rebinding defenses).
Private keys are encrypted at rest (AES-256-GCM). Inbound activities are
rejected unless the HTTP Signature verifies against the signing actor's
published key and the `actor` matches the signer.
