# Deck ↔ GitHub Projects Sync (`deckgithubsync`)

Bidirektionale, pro Board und pro Feld konfigurierbare Synchronisation zwischen
Nextcloud Deck und GitHub Projects v2.

## Stand (0.3.1 – lauffähig)

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
| Archiv/Delete | Archiv/Delete | ✅ |
| PR | read-only Card | ✅ |
| Anhang | — | TODO |

## Setup

1. App ist in `data/apps-extra/deckgithubsync` verlinkt (wird als `apps-shared` gemountet).
   Für `workspace/server/apps-extra` einmalig manuell (braucht root):
   `sudo ln -s /home/schaechner/Projects/deck-github-sync /home/schaechner/nextcloud-docker-dev/workspace/server/apps-extra/deckgithubsync`
2. GitHub App erstellen (Permissions: Projects RW, Issues RW, PR Read, Webhook `projects_v2_item, issues`), installieren, App-ID + Private Key + Installation-ID in Admin-Einstellungen eintragen.
3. Webhook auf `https://<nc>/index.php/apps/deckgithubsync/webhook/github` zeigen lassen, Secret eintragen.
4. Pro User: Deck-Board + GitHub Project mappen (Persönliche Einstellungen), Richtung/Felder wählen.
5. `occ deckgithubsync:sync` oder Cron abwarten.

## Dev

- `php -l lib/...`, `./vendor/bin/phpunit tests/Unit` (im Server-Checkout: `NOCOVERAGE=1 ./autotest.sh sqlite apps-extra/deckgithubsync/tests`)
- Frontend: `npm ci && npm run build`
- Deck muss installiert sein, sonst wirft `DeckService` mit klarer Meldung.
