# Deck ↔ GitHub Projects Sync (`deckgithubsync`)

Bidirektionale, pro Board und pro Feld konfigurierbare Synchronisation zwischen
Nextcloud Deck und GitHub Projects v2.

## Stand (0.4.6)

- OAuth-Login per Klick (+ PAT-Fallback), Token-Status, Boards-Dropdown
- GitHub-Projects-Auswahl für eigenes Konto und Organisationen, mit manueller Eingabe als Ausweichmöglichkeit
- Nextcloud-kompatible API-URLs, per-User-OAuth mit Token-Refresh und aussagekräftige Auth-Fehler
- OAuth-Start und -Callback mit korrekter CSRF-Ausnahme; bestätigter Verbindungsstatus in den persönlichen Einstellungen
- Persönliche und Organisations-Projects lassen sich nach der Auswahl als Mapping anlegen
- Redesignte Admin-/Personal-Einstellungen (nativ, `.mjs`-Module)
- Sync: beide Richtungen für Anlage + Update, Last-Write-Wins, getrennte
  `deck_hash`/`github_hash` (idempotent, konvergiert), Titel-Dedup,
  race-sichere Links, Session-Isolation für Cron
- Webhook mit echten `deleted`/`archived`/`restored`-Events; keine
  Löschungen auf Listen-Abwesenheit
- Validierte API (Richtung, Board-Zugriff, Token), `occ`-Fehlercodes
- Unit-Test `MappingTest`, Docs unter `docs/`
- Webhook verlangt ein konfiguriertes Secret; GitHub-Fehler brechen Mutationen ab;
  Kommentar-Sync und deaktivierte Draft-Felder korrigiert

- User-Mapping `deckghs_usermap` pro Board (`PUT /api/v1/mappings/{id}/users`), Sync nutzt es für Assignees beide Richtungen
- Due-Date via konfigurierbarer `dateFieldId` (erstes DATE-Feld auto-erkannt, pro Mapping änderbar), `DeckService::normalizeDue`
- PR-Items read-only: nie Push, bei Anlage `[GitHub PR, read-only]` + URL, kein Delete
- Delete/Archive-Propagation: GitHub `archivedAt` → Deck-Archiv, GitHub gelöscht → Deck-Delete, Deck gelöscht → GitHub-Archiv; Hash inkl. Due/Labels/Assignees
- Älter (0.2.0): GraphQL + Issues-REST, Last-Write-Wins, Webhook-Routing, Labels/Comments beide Richtungen

## Mapping

| Deck | GitHub | Stand |
|---|---|---|
| Stack | Status-Option (ID) | ✅ |
| Titel/Beschreibung | DraftIssue + Issue | ✅ |
| Label | Issue-Label (REST) | ✅ |
| Assignee | via User-Mapping Tabelle | ✅ (ohne Mapping wird übersprungen) |
| Kommentar | Issue-Comment (`[Deck]`/`[GitHub user]`) | ✅ |
| Due | Date-Field (`dateFieldId`) | ✅ |
| Archiv/Delete | nur per Webhook-Event (`deleted`/`archived`/`restored`), nie per Listen-Abwesenheit | ✅ |
| PR | read-only Card | ✅ |
| Anhang | — | TODO |

## Setup

Die [manuelle Installation](docs/SETUP.md) beschreibt den Ablauf für eine
reguläre Nextcloud-Instanz. Deck muss vorher aktiviert sein.

## Dev

- `php -l lib/...`, `./vendor/bin/phpunit tests/Unit` (im Server-Checkout: `NOCOVERAGE=1 ./autotest.sh sqlite apps-extra/deckgithubsync/tests`)
- Frontend: `npm ci && npm run build`
- Deck muss installiert sein, sonst wirft `DeckService` mit klarer Meldung.

## Doku

Kurz-Doku unter `docs/`: `SETUP.md`, `ARCHITECTURE.md`, `API.md`, `TROUBLESHOOTING.md`.
