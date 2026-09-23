# Setup – Deck ↔ GitHub Projects Sync

## Voraussetzungen

- Nextcloud 31–36, Deck-App installiert und aktiviert
- GitHub-Konto; für Server-Sync optional eine GitHub App, für Nutzer-Login optional eine GitHub OAuth App

## Manuelle Installation in einer regulären Nextcloud

Die App ist ein Quellcode-Release mit bereits gebauten Frontend-Dateien unter
`js/`. Wähle einen in `config/config.php` unter `apps_paths` eingetragenen
App-Pfad (typisch `custom_apps`). Die Befehle unten setzt du auf dem
Nextcloud-Server aus; passe Pfade und den Webserver-Benutzer an.

```bash
cd /pfad/zu/nextcloud
sudo -u www-data php occ app:enable deck
cd custom_apps
git clone https://github.com/SchVinzenz/Github-Projects-for-Deck.git deckgithubsync
cd ..
sudo -u www-data php occ app:enable deckgithubsync
sudo -u www-data php occ app:list --enabled
```

Falls `custom_apps` nicht existiert oder nicht in `apps_paths` steht,
verwende den konfigurierten zusätzlichen App-Pfad. Der Verzeichnisname muss
`deckgithubsync` lauten. Die Nextcloud-Version muss laut App-Metadaten
zwischen 30 und 36 liegen. Webserver/PHP brauchen Leserechte auf die Dateien.

Bei Aktualisierungen: Datenbank und Nextcloud-Konfiguration sichern, im
App-Verzeichnis `git pull --ff-only` ausführen und danach
`sudo -u www-data php occ upgrade`. Prüfe anschließend
`occ app:list --enabled`. Für eigene Änderungen in der Installation zuerst
eine separate Kopie oder einen Branch verwenden.

Frontend neu bauen (nur nach Änderungen unter `src/` nötig, `js/` ist committet):

```bash
npm ci && npm run build
```

## GitHub anbinden (Nutzer, empfohlen)

1. Ein Admin richtet einmalig die GitHub OAuth App ein:
   **Einstellungen → Deck und GitHub synchronisieren** → Abschnitt *GitHub OAuth*:
   GitHub → Settings → Developer settings → OAuth Apps → New OAuth App,
   Authorization callback URL exakt aus dem Admin-Formular übernehmen.
   Client ID + Client Secret eintragen, speichern. Die angezeigte URL
   muss auf die öffentlich erreichbare Nextcloud-Adresse zeigen
   (bei Reverse Proxy ggf. `overwritehost`/`overwriteprotocol` prüfen).
2. Danach verbindet **jeder Nextcloud-Benutzer sein eigenes GitHub-Konto**:
   **Persönliche Einstellungen → Deck und GitHub synchronisieren** →
   *Mit GitHub verbinden* klicken und auf GitHub bestätigen.
   Ablaufende OAuth-Tokens werden mit dem Refresh-Token erneuert.
   Bereits verbundene Nutzer sollten sich für die Organisationserkennung
   erneut verbinden, damit der zusätzliche `read:org`-Scope erteilt wird.

Alternative ohne OAuth App: Personal Access Token (fine-grained, Scopes
`Projects: Read & Write`, `Issues: Read & Write`) im selben Formular eintragen.

## Server-Sync via GitHub App (optional)

GitHub App mit Permissions *Projects: Read & Write*, *Issues: Read & Write*,
*Pull requests: Read* erstellen, installieren, App ID + Private Key +
Installation ID in den Admin-Einstellungen hinterlegen. Persönliche
OAuth-/PAT-Tokens werden für den jeweiligen Nutzer bevorzugt; die GitHub
App dient als Fallback für Sync ohne nutzbares persönliches Token.

## Mapping anlegen

Persönliche Einstellungen → *Neues Mapping*: Deck-Board und ein automatisch
geladenes GitHub Project wählen, Richtung wählen und ein **Issue-Repository**
aus den Vorschlägen wählen. Ohne Repository legt die App weiterhin Project-Drafts
an. Wird ein Repository bei einem bestehenden Mapping eingetragen, werden
bereits verknüpfte Drafts beim nächsten Sync in Issues in diesem Repository
umgewandelt. Falls ein Project
nicht in der Liste erscheint, können Owner und Project-Nummer weiter manuell
eingegeben werden. Danach optional pro
Feld (Titel, Beschreibung, Status, Labels, Assignees, Start-/Fälligkeitsdatum, Kommentare)
die Richtung feintunen sowie GitHub-Logins auf Deck-Benutzer mappen.

Deck-Listen werden als Optionen des GitHub-Project-Felds **Status** angelegt;
GitHub-Statusoptionen werden als Deck-Listen angelegt. Die GitHub-Board-Ansicht
muss nach **Status** gruppiert sein, damit jede Liste als Spalte erscheint.
Die App legt fehlende Project-Datumsfelder **Start date** und **Due date** an
und synchronisiert sie mit Start- und Fälligkeitsdatum der Deck-Karte. In der
GitHub-Roadmap-Ansicht diese beiden Felder als Start- und Zieldatum auswählen.
Ein in Deck erledigter Karte entsprechendes Issue wird geschlossen und beim
Zurücksetzen wieder geöffnet. Bereits vorhandene GitHub-Issues werden nicht
in ein anderes Repository verschoben.

## Automatik: Cron und Webhook

Der Sync läuft ohne Klicks: Der Cron-Job (`SyncJob`) synchronisiert alle
fälligen Mappings, das Intervall steht in den Admin-Einstellungen
(Minimum 300 s). Voraussetzung ist der reguläre Nextcloud-Hintergrundjob
per System-Cron (`cron.php`); der AJAX-Modus reicht nicht für verlässliche
Intervalle. Mit Webhook (siehe unten) wird das betroffene Mapping sofort
fällig gestellt und beim nächsten Cron-Lauf synchronisiert.

## Webhook (optional, für Echtzeit)

GitHub App → Webhook auf
`https://<nextcloud>/index.php/apps/deckgithubsync/webhook/github`
zeigen lassen (Events: `projects_v2_item`, `issues`). Ein identisches
zufälliges Secret bei GitHub und in den Admin-Einstellungen hinterlegen;
ohne Secret lehnt die App Webhook-Anfragen ab. Ohne Webhook greift der Cron-Job
(Intervall einstellbar, min. 300 s).

Die regulären Nextcloud-Hintergrundjobs sollten per System-Cron laufen.
Vor dem ersten bidirektionalen Sync ein Backup des Deck-Boards und des
GitHub-Projekts erstellen und das Mapping zunächst an Testdaten prüfen.

## Manueller Sync

- Button *Jetzt syncen* in den Einstellungen oder
- `php occ deckgithubsync:sync [mapping-id]`
