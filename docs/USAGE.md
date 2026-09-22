# Benutzung

## Läuft der Sync automatisch?

Ja. Nach dem Anlegen eines Mappings musst du nichts weiter tun:

- Der **Cron-Job** synchronisiert alle fälligen Mappings automatisch
  (Intervall in den Admin-Einstellungen, Minimum 300 s). Dafür muss der
  reguläre Nextcloud-Hintergrundjob per System-Cron laufen
  (`cron.php`, siehe Nextcloud-Doku) – der AJAX-Modus reicht für
  regelmäßige Syncs nicht aus.
- Mit konfiguriertem **Webhook** (GitHub App, Events `projects_v2_item`)
  wird das betroffene Mapping sofort fällig gestellt und beim nächsten
  Cron-Lauf synchronisiert – quasi Echtzeit.
- Der Button **Jetzt syncen** und `php occ deckgithubsync:sync [mapping-id]`
  sind nur für manuelle Zwischen-Syncs da. Die Statistik zeigt pro Lauf
  `deck_to_github`, `github_to_deck`, `errors` und `warnings`.

Hinweis: Schlägt ein Sync mit Fehlern fehl, wird `lastSync` nicht
aktualisiert – das Mapping bleibt fällig und wird beim nächsten Lauf erneut
versucht.

## Muss ein Issue-Repository angegeben werden?

Nein, es ist **optional**:

- **Ohne Repository** entstehen reine Project-Drafts (Titel + Beschreibung).
  Ideal zum Ausprobieren oder für Boards ohne Repo-Bezug.
- **Mit Repository** (Format `owner/repo`) wandelt die App neue Karten
  direkt in Issues um; bereits verknüpfte Drafts werden beim nächsten Sync
  in Issues in diesem Repository umgewandelt (Status und Daten bleiben
  erhalten). Issues werden nie in ein anderes Repository verschoben.

## Richtung pro Board und pro Feld

- Pro Mapping: **Bidirektional**, **Deck → GitHub** oder **GitHub → Deck**.
- Zusätzlich pro Feld (`Titel`, `Beschreibung`, `Status`, `Labels`,
  `Assignees`, `Startdatum`, `Fälligkeit`, `Kommentare`): beidseitig, nur
  eine Richtung oder `aus`.
- Bei beidseitig geänderten Items gewinnt die **neuere Seite**
  (Last-Write-Wins anhand der Zeitstempel).

## Stacks und Status

- Deck-Listen entsprechen den Optionen des GitHub-**Status**-Felds
  (Abgleich per ID, nicht per Name).
- Fehlt eine Liste als Status-Option (oder umgekehrt), legt die App sie
  automatisch an – in GitHub bzw. in Deck.

## Assignees: Nutzer mappen

GitHub-Logins und Nextcloud-Benutzer heißen fast nie gleich. Unter
*Felder & Nutzer* pro Mapping Zeilen im Format
`GitHub-Login → Deck-Benutzer` pflegen. Ohne Eintrag werden Assignees in
diese Richtung übersprungen (kein Fehler).

## Kommentare

Werden duplikat-geschützt übertragen und mit `[Deck]`- bzw.
`[GitHub user]`-Präfix markiert, damit die Herkunft erkennbar bleibt und
keine Echos entstehen.

## Löschen und Archivieren

Absichtlich zurückhaltend: Gelöschte oder archivierte GitHub-Items wirken
nur dann auf Deck-Karten, wenn ein Webhook-Event (`deleted`, `archived`,
`restored`) ankommt. Reines Fehlen im Listing löst **nie** Löschungen aus.
Umgekehrt genauso dokumentiert verhalten – im Zweifel auf beiden Seiten
aufräumen.
