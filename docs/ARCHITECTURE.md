# Architektur

```
Deck (Board/Stack/Card)  <--DeckService-->  SyncService  <--GithubProjectService-->  GitHub Projects v2
                                                        <--GithubClientService-->   (GraphQL + REST)
```

## Komponenten

| Klasse | Aufgabe |
|---|---|
| `Service/DeckService` | Deck-2.x-API (`Board/Stack/Card/Label/AssignmentService`, `Card/LabelMapper`), User-Kontext via `setUserId` + Session |
| `Service/GithubClientService` | Auth: GitHub-App-JWT → Installation-Token, OAuth-Code/Refresh-Flow, Token-Validierung; GraphQL (fail-loud) + REST |
| `Service/SyncService` | Bidirektionaler Sync pro Mapping: Richtung pro Board + pro Feld, Last-Write-Wins via Zeitstempel, getrennte Deck-/GitHub-Hashes, Titel-Dedup, PRs read-only |
| `Controller/OAuthController` | GitHub-Login-Flow (state-gesichert, Token + Login werden pro Nutzer gespeichert) |
| `Controller/SettingsController` | Mappings-CRUD, User-Mapping, Boards-Liste, Token-Status/Set/Unset, Admin-Config |
| `Controller/SyncController` | Manueller Sync-Trigger + Status |
| `Controller/WebhookController` | Öffentlicher GitHub-Webhook (HMAC, Bot-Filter, `deleted`/`archived`/`restored`-Events werden direkt angewendet, sonst Routing per `project_node_id` → Mapping wird fällig gestellt) |
| `BackgroundJob/SyncJob` | Cron-Job (TimedJob, Intervall aus Config, min. 300 s) |
| `Command/SyncCommand` | `occ deckgithubsync:sync [id]` |

## Datenmodell

- `deckghs_boardmap`: Board ↔ Project, optionales Issue-Repository, Richtung,
  Feldconfig (JSON), `status_field_id`, `date_field_id`, `start_field_id`,
  `last_sync` (nur letzter **erfolgreicher** Lauf), `cooldown_until`
  (Rate-Limit-Sperre, schließt Mappings vom Cron aus)
- `deckghs_itemmap`: Card ↔ Project-Item, Content-Typ, Deck-Hash + GitHub-Hash (getrennt, für idempotente Syncs)
- `deckghs_usermap`: GitHub-Login ↔ Deck-Benutzer (pro Mapping, für Assignees)

## Sync-Regeln

- Richtung global pro Board (`both`, `deck_to_github`, `github_to_deck`) und
  verfeinerbar pro Feld (`title`, `description`, `status`, `labels`,
  `assignees`, `start`, `due`, `comments`, jeweils zusätzlich `off` möglich).
  Unbekannte Werte wirken wie `off`.
- **Optionale Felder best-effort**: Labels, Assignees, Kommentare sowie
  Schema-Anpassungen (Datumsfelder, Status-Optionen) erzeugen bei Fehlern
  Warnungen statt Abbrüche; Titel/Status/Daten bleiben hart.
- **Last-Write-Wins**: Bei beidseitig geänderten Items gewinnt die neuere
  Seite (`lastModified` vs. `updatedAt`).
- **Loop-Schutz**: Getrennte Hashes über die gemappten Felder pro Seite + Bot-Filter im Webhook.
- **Status**: Deck-Stack ↔ Status-Option (per **ID**, nicht Name).
- **Fälligkeit/Start**: Deck-Daten ↔ Datumsfelder (per Name erkannt, Fallback:
  einziges DATE-Feld; `Start date`/`Due date` werden bei Bedarf angelegt).
- **Session-Isolation**: Der Sync stellt nach dem Lauf den vorherigen
  Session-Benutzer wieder her (Cron-Hygiene).
- **Kommentare**: Duplikat-geschützt, mit `[Deck]`- bzw. `[GitHub user]`-Präfix.
- **Assignees**: Nur über explizites Nutzer-Mapping, sonst Skip.
- **Pull Requests**: read-only (nur GitHub → Deck, markiert).
- **Löschen/Archivieren**: Nur auf explizite Webhook-Events
  (`projects_v2_item` → `deleted`/`archived`/`restored`). Abwesenheit im
  Listing löst **nie** Löschungen aus (kann ein partielles Listing bedeuten).
  Neue Mappings verlinken titelgleiche Items/Karten statt Duplikate anzulegen.
