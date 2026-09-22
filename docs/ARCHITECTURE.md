# Architektur

```
Deck (Board/Stack/Card)  <--DeckService-->  SyncService  <--GithubProjectService-->  GitHub Projects v2
                                                        <--GithubClientService-->   (GraphQL + REST)
```

## Komponenten

| Klasse | Aufgabe |
|---|---|
| `Service/DeckService` | Deck-2.x-API (`Board/Stack/Card/Label/AssignmentService`, `Card/LabelMapper`), User-Kontext via `setUserId` + Session |
| `Service/GithubClientService` | Auth: GitHub-App-JWT → Installation-Token (1 h, gecacht), User-PAT/OAuth-Token; GraphQL- + REST-Calls, Token-Validierung |
| `Service/GithubProjectService` | Projects-v2-Operationen: Project auflösen/discover, Repositories, Fields/Status-Optionen (inkl. Anlegen), Items (paginiert), Draft anlegen/updaten/konvertieren, Status/Datum setzen, archivieren/löschen; Issue-REST (Titel/Body/Labels/Assignees/Comments) |
| `Service/GithubClientService` | Auth: GitHub-App-JWT → Installation-Token, OAuth-Code/Refresh-Flow, Token-Validierung; GraphQL (fail-loud) + REST |
| `Service/SyncService` | Bidirektionaler Sync pro Mapping: Richtung pro Board + pro Feld, Last-Write-Wins via Zeitstempel, getrennte Deck-/GitHub-Hashes, Titel-Dedup, PRs read-only |
| `Controller/OAuthController` | GitHub-Login-Flow (state-gesichert, Token + Login werden pro Nutzer gespeichert) |
| `Controller/SettingsController` | Mappings-CRUD, User-Mapping, Boards-Liste, Token-Status/Set/Unset, Admin-Config |
| `Controller/SyncController` | Manueller Sync-Trigger + Status |
| `Controller/WebhookController` | Öffentlicher GitHub-Webhook (HMAC, Bot-Filter, `deleted`/`archived`/`restored`-Events werden direkt angewendet, sonst Routing per `project_node_id` → Mapping wird fällig gestellt) |
| `BackgroundJob/SyncJob` | Cron-Job (TimedJob, Intervall aus Config, min. 300 s) |
| `Command/SyncCommand` | `occ deckgithubsync:sync [id]` |

## Datenmodell

- `deckghs_boardmap`: Board ↔ Project, Richtung, Feldconfig (JSON),
  `status_field_id`, `date_field_id`, `last_sync`
- `deckghs_itemmap`: Card ↔ Project-Item, Content-Typ, Deck-Hash + GitHub-Hash (getrennt, für idempotente Syncs)
- `deckghs_usermap`: GitHub-Login ↔ Deck-Benutzer (pro Mapping, für Assignees)

## Sync-Regeln

- Richtung global pro Board (`both`, `deck_to_github`, `github_to_deck`) und
  verfeinerbar pro Feld (`title`, `description`, `status`, `labels`,
  `assignees`, `due`, `comments`, jeweils zusätzlich `off` möglich).
- **Last-Write-Wins**: Bei beidseitig geänderten Items gewinnt die neuere
  Seite (`lastModified` vs. `updatedAt`).
- **Loop-Schutz**: Getrennte Hashes über die gemappten Felder pro Seite + Bot-Filter im Webhook.
- **Status**: Deck-Stack ↔ Status-Option (per **ID**, nicht Name).
- **Fälligkeit**: Deck-`duedate` ↔ konfigurierbares Datumsfeld (`dateFieldId`,
  Default: erstes DATE-Feld).
- **Kommentare**: Duplikat-geschützt, mit `[Deck]`- bzw. `[GitHub user]`-Präfix.
- **Assignees**: Nur über explizites Nutzer-Mapping, sonst Skip.
- **Pull Requests**: read-only (nur GitHub → Deck, markiert).
- **Löschen/Archivieren**: Nur auf explizite Webhook-Events
  (`projects_v2_item` → `deleted`/`archived`/`restored`). Abwesenheit im
  Listing löst **nie** Löschungen aus (kann ein partielles Listing bedeuten).
  Neue Mappings verlinken titelgleiche Items/Karten statt Duplikate anzulegen.
