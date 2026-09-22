# Troubleshooting

## Einstellungs-Seite bleibt leer

- Harten Reload (Strg+Shift+R): JS wird als `.mjs`-Modul geladen.
- Browser-Konsole (F12) prüfen. Steht dort etwas von MIME-Typ oder
  `import`, ist vermutlich ein altes Bundle im Cache.
- Falls nur *„Wird geladen …"* steht: Das Bundle läuft nicht – bitte die
  Konsolen-Meldung melden.

## Deck-App zeigt nichts an

Deck braucht gebautes Frontend. Bei Installation per Git-Clone:

```bash
cd apps-extra/deck && npm ci && npm run build
```

## Sync meldet GitHub-Fehler

- `No GitHub token configured`: Nutzer-Konto verbinden (OAuth/PAT) oder
  GitHub App konfigurieren.
- `Bad credentials` / 401: Token ungültig oder abgelaufen → neu verbinden.
- `INSUFFICIENT_SCOPES` / 403: Token/App braucht *Projects* **und**
  *Issues* (Read & Write).
- `GitHub project not found` beim Anlegen: Owner/Nummer prüfen und ob der
  Token/die App Zugriff auf das Project hat.

## Personal Access Token lässt sich nicht speichern

- `GitHub hat den Token abgelehnt (401)`: Token auf GitHub auf Ablauf,
  Widerruf und vollständiges Kopieren prüfen.
- `GitHub verweigert den Zugriff (403)`: GitHub-Berechtigungen oder
  Rate-Limit prüfen.
- `GitHub ist vom Nextcloud-Server aus nicht erreichbar`: ausgehende
  HTTPS-Verbindung und Zertifikate auf dem Nextcloud-Server prüfen.
- Nextcloud-Fehler 403/404: neu anmelden beziehungsweise die App-Version
  und den installierten App-Pfad prüfen. Bei Betrieb unter einem
  Nextcloud-Unterpfad mindestens App-Version 0.4.4 verwenden.

## OAuth-Anmeldung schlägt fehl

- `CSRF check failed` bei *Mit GitHub verbinden*: App auf mindestens
  Version 0.4.5 aktualisieren und die Einstellungsseite neu laden.
- Im Admin-Formular prüfen, ob **Client Secret: gespeichert** angezeigt wird.
- Die angezeigte Callback-URL muss bei der GitHub OAuth App exakt eingetragen
  und von außen erreichbar sein. Ein falsches Schema (`http` statt `https`)
  weist oft auf die Reverse-Proxy-Konfiguration der Nextcloud hin.
- `invalid_state`: Anmeldung erneut starten und Cookies für die Nextcloud
  zulassen; der Callback muss in derselben Nextcloud-Sitzung ankommen.
- `exchange_failed`: Client ID/Secret und Callback-URL prüfen;
  Nextcloud-Log nach `deckgithubsync: OAuth callback failed` filtern.
- Bei zuvor verbundenen Nutzern kann nach dem Update eine erneute Verbindung
  nötig sein, damit Refresh-Tokens und der `read:org`-Scope vorliegen.

## Assignees werden nicht übernommen

Assignees laufen nur über explizites Nutzer-Mapping
(Mapping → *Felder & Nutzer*). Ohne Eintrag wird übersprungen.

## Logs

- Nextcloud-Log nach `deckgithubsync` filtern.
- Manueller Sync mit Details: `php occ deckgithubsync:sync <mapping-id>`
  gibt JSON-Statistik inkl. `errors` aus.
