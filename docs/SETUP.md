# Setup – Deck ↔ GitHub Projects Sync

## Voraussetzungen

- Nextcloud 30–36, Deck-App installiert und aktiviert
- GitHub-Konto; für Server-Sync optional eine GitHub App, für Nutzer-Login optional eine GitHub OAuth App

## Installation (Entwicklung)

```bash
# Repo als Nextcloud-App verlinken/kopieren, z. B.:
cp -r . /pfad/zu/nextcloud/apps-extra/deckgithubsync
php occ app:enable deckgithubsync
```

Frontend bauen (nur nach Änderungen unter `src/` nötig, `js/` ist committet):

```bash
npm install && npm run build
```

## GitHub anbinden (Nutzer, empfohlen)

1. Admin: **Einstellungen → Deck ↔ GitHub Projects** → Abschnitt *GitHub OAuth*:
   GitHub → Settings → Developer settings → OAuth Apps → New OAuth App,
   Authorization callback URL aus dem Admin-Formular übernehmen.
   Client ID + Client Secret eintragen, speichern.
2. Nutzer: **Persönliche Einstellungen → Deck ↔ GitHub Projects** →
   *Mit GitHub verbinden* klicken, auf GitHub bestätigen, fertig.

Alternative ohne OAuth App: Personal Access Token (fine-grained, Scopes
`Projects: Read & Write`, `Issues: Read & Write`) im selben Formular eintragen.

## Server-Sync via GitHub App (optional)

GitHub App mit Permissions *Projects: Read & Write*, *Issues: Read & Write*,
*Pull requests: Read* erstellen, installieren, App ID + Private Key +
Installation ID in den Admin-Einstellungen hinterlegen. Vorteil: Sync läuft
auch ohne Nutzer-Token über Cron.

## Mapping anlegen

Persönliche Einstellungen → *Neues Mapping*: Deck-Board wählen, GitHub-Owner
und Project-Nummer eintragen, Richtung wählen, anlegen. Danach optional pro
Feld (Titel, Beschreibung, Status, Labels, Assignees, Fälligkeit, Kommentare)
die Richtung feintunen sowie GitHub-Logins auf Deck-Benutzer mappen.

## Webhook (optional, für Echtzeit)

GitHub App → Webhook auf
`https://<nextcloud>/index.php/apps/deckgithubsync/webhook/github`
zeigen lassen (Events: `projects_v2_item`, `issues`), Secret in den
Admin-Einstellungen hinterlegen. Ohne Webhook greift der Cron-Job
(Intervall einstellbar, min. 300 s).

## Manueller Sync

- Button *Jetzt syncen* in den Einstellungen oder
- `php occ deckgithubsync:sync [mapping-id]`
