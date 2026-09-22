# Deck ↔ GitHub Projects Sync (`deckgithubsync`)

Bidirektionale, pro Board und pro Feld konfigurierbare Synchronisation zwischen
Nextcloud Deck und GitHub Projects v2.

## Stand (0.2.0 – erweiterter Sync)

- App-Gerüst für Nextcloud 30–36, Namespace `OCA\DeckGithubSync`
- DB: `deckghs_boardmap` (Board ↔ Project + Richtung + Feldconfig) und `deckghs_itemmap` (Card ↔ Item)
- GitHub App Auth: JWT (RS256, OpenSSL) → Installation Token (1h, gecacht) → GraphQL + REST, Fallback User-PAT
- Projects v2 GraphQL: Projekt auflösen, Fields/Status-Optionen, Items paginiert, Draft anlegen/updaten, Status/Date setzen, Löschen
- Issues REST: Titel/Body/State, Labels setzen, Assignees setzen, Comments lesen/schreiben
- Deck: interne `BoardService/StackService/CardService` im User-Kontext, Full-Roundtrip-Update, Labels/Assignees/Comments defensiv via `method_exists`
- Sync: beide Richtungen für Anlage + Update, Last-Write-Wins (`lastModified` vs `updatedAt`), Hash-Loop-Schutz, Richtung `both|deck_to_github|github_to_deck|off` pro Board und pro Feld (title, description, status, labels, assignees, due, comments)
- Webhook `/webhook/github` mit HMAC-Prüfung + Bot-Filter + Routing via `project_node_id` → Map auf `last_sync=0`, Ausführung via `SyncJob`
- BackgroundJob `SyncJob` (≥300s) + occ `deckgithubsync:sync [map-id]`
- Admin-/Personal-Settings + Vue UI (Richtung + Feldmatrix + manueller Sync)
- Unit-Test `MappingTest` (Felddefaults, Status-, Label-, Assignee-Extraktion)

## Mapping (erweitert)

| Deck | GitHub | Stand |
|---|---|---|
| Stack | Status-Option (ID, nicht Name) | ✅ beide Richtungen |
| Card Titel/Beschreibung | DraftIssue + Issue title/body | ✅ beide Richtungen |
| Label | Issue-Label (REST) | ✅ beide Richtungen |
| Assignee | Issue-Assignee (REST, Deck-Seite nur via User-ID) | ✅ Deck→GitHub; GitHub→Deck TODO (Login→UID Mapping) |
| Kommentar | Issue-Comment (REST, `[Deck]`/`[GitHub user]` Präfix) | ✅ beide Richtungen, Duplikat-Schutz |
| Fälligkeitsdatum | Date-Field | ✅ `setDate` / clear |
| Anhang | — | TODO: kein Projects-Äquivalent |

Offen: GitHub-User ↔ Deck-User Mapping-Tabelle, Due-Date Custom-Field-ID Config, PR-Items (read-only), Archiv/Delete-Propagation.

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
