# Deck ↔ GitHub Projects Sync (`deckgithubsync`)

Bidirektionale, pro Board und pro Feld konfigurierbare Synchronisation zwischen
Nextcloud Deck und GitHub Projects v2.

## Funktionen (0.5.0)

- **Verbinden per Klick**: GitHub OAuth-Login mit Token-Refresh, alternativ
  Personal Access Token; Verbindungsstatus inkl. Prüfung in den Einstellungen
- **Mapping per Auswahl**: Deck-Board-Dropdown, GitHub-Project-Suche (eigene
  und Organisations-Projects) mit manueller Eingabe als Fallback,
  Duplikat-Schutz, transaktionales Löschen inkl. Links
- **Issue-Modus optional**: Ohne Repository entstehen Project-Drafts; mit
  Repository werden Drafts (auch bestehende) in Issues umgewandelt
- **Automatik**: Cron-Job (Intervall einstellbar, min. 300 s) plus optionaler
  GitHub-Webhook für Echtzeit; manueller Sync per Button oder
  `php occ deckgithubsync:sync [mapping-id]`
- **Sync-Logik**: Beide Richtungen für Anlage + Update, Last-Write-Wins,
  getrennte Deck-/GitHub-Hashes (idempotent), Titel-Dedup bei neuen Mappings,
  race-sichere Links, Session-Isolation für Cron
- **Felder**: Titel, Beschreibung, Status, Labels, Assignees, Start- und
  Fälligkeitsdatum, Erledigt-Status, Kommentare – Richtung pro Feld einstellbar
- **Robustheit**: Optionale Felder (Labels, Assignees, Kommentare,
  Schema-Anpassungen) laufen best-effort mit Warnungen statt Abbrüchen;
  GraphQL-Fehler brechen laut ab statt still leer zu liefern; destruktive
  Aktionen nur per explizitem Webhook-Event, nie per Listen-Abwesenheit
- **Tests & Doku**: 27 Unit-Tests, Docs unter `docs/`

## Mapping

| Deck | GitHub | Stand |
|---|---|---|
| Stack | Status-Option (ID, fehlende werden angelegt) | ✅ |
| Titel/Beschreibung | DraftIssue + Issue | ✅ |
| Label | Issue-Label (REST, fehlende werden angelegt) | ✅ |
| Assignee | via User-Mapping-Tabelle | ✅ (ohne Mapping wird übersprungen) |
| Kommentar | Issue-Comment (`[Deck]`/`[GitHub user]`) | ✅ |
| Fälligkeit | Datumsfeld (auto-erkannt, Default: einziges DATE-Feld) | ✅ |
| Startdatum | separates Datumsfeld (`Start date` wird ggf. angelegt) | ✅ |
| Erledigt | Issue open/closed | ✅ |
| Archiv/Delete | nur per Webhook-Event (`deleted`/`archived`/`restored`) | ✅ |
| PR | read-only Card | ✅ |
| Anhang | — | TODO |

## Setup

Die [manuelle Installation](docs/SETUP.md) beschreibt den Ablauf für eine
reguläre Nextcloud-Instanz. Deck muss vorher aktiviert sein.

## Benutzung

Siehe [Benutzung](docs/USAGE.md): verbinden, Mapping anlegen, Richtungen und
Nutzer-Mapping verstehen, Automatik und manueller Sync.

## Dev

- `php -l lib/...`, Unit-Tests im Server-Checkout:
  `phpunit --bootstrap tests/bootstrap.php apps-extra/deckgithubsync/tests/Unit`
- Frontend: `npm ci && npm run build` (`.mjs`-Module + CSS werden committet)
- Deck muss installiert sein, sonst wirft `DeckService` mit klarer Meldung.

## Doku

Kurz-Doku unter `docs/`: `SETUP.md`, `USAGE.md`, `ARCHITECTURE.md`, `API.md`,
`TROUBLESHOOTING.md`.
