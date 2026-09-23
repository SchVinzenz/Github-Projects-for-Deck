# Changelog

## 1.0.0 (2026-09-23)

First stable release.

- Everything from 0.5.0, hardened through reviews and live tests against
  Nextcloud 36 with Deck 2.x.
- Idempotent bidirectional sync with per-side hashes, duplicate protection,
  rate-limit cooldowns, and best-effort warnings for optional fields.
- Reviewed controller security, user scoping, migration safety, and
  App Store metadata validation.

## 0.5.0 (2026-09-23)

Initial release candidate.

- Bidirectional Deck ↔ GitHub Projects v2 synchronization with configurable direction per field.
- Project draft items and optional GitHub Issues, including conversion of linked drafts.
- Status, labels, assignees, dates, completion state, and comments.
- GitHub OAuth, personal access tokens, and optional GitHub App credentials.
- Scheduled and manual sync, optional webhook, and English/German settings.
- Documentation, demo screenshots, and App Store metadata.
