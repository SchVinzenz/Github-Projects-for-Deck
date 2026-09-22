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

## Assignees werden nicht übernommen

Assignees laufen nur über explizites Nutzer-Mapping
(Mapping → *Felder & Nutzer*). Ohne Eintrag wird übersprungen.

## Logs

- Nextcloud-Log nach `deckgithubsync` filtern.
- Manueller Sync mit Details: `php occ deckgithubsync:sync <mapping-id>`
  gibt JSON-Statistik inkl. `errors` aus.
