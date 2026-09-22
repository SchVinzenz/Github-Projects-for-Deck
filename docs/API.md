# API-Referenz (App-intern)

Basis: `/index.php/apps/deckgithubsync`, Auth: Nextcloud-Session (+ CSRF).

## Mappings (Nutzer)

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/v1/mappings` | Eigene Mappings listen |
| POST | `/api/v1/mappings` | Anlegen (`deckBoardId`, `githubOwner`, `githubNumber`, `direction`, `fieldConfig`) – löst Project-ID + Feld-IDs auf |
| PUT | `/api/v1/mappings/{id}` | Richtung, Feldconfig, `dateFieldId` ändern |
| DELETE | `/api/v1/mappings/{id}` | Löschen |
| PUT | `/api/v1/mappings/{id}/users` | Nutzer-Mapping setzen (`users: [{githubLogin, deckUid}]`) |

## Deck & GitHub-Konto (Nutzer)

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/api/v1/deck/boards` | Eigene Deck-Boards (`id`, `title`) für Dropdowns |
| GET | `/api/v1/github/status` | `{connected, login?, invalid?, oauth}` |
| PUT | `/api/v1/github/token` | PAT prüfen + speichern |
| DELETE | `/api/v1/github/token` | Verbindung trennen |

## Sync (Nutzer)

| Methode | Pfad | Beschreibung |
|---|---|---|
| POST | `/api/v1/sync/{id}` | Mapping sofort syncen, gibt Statistik zurück |
| GET | `/api/v1/sync/{id}/status` | `lastSync`, Richtung |

## Admin

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET/PUT | `/api/v1/admin` | GitHub-App-Daten, OAuth-Client, Webhook-Secret, Intervall |

## OAuth & Webhook

| Methode | Pfad | Beschreibung |
|---|---|---|
| GET | `/oauth/start` | Redirect zu GitHub (Session + State) |
| GET | `/oauth/callback` | Code einlösen, Token speichern, zurück zu den Einstellungen |
| POST | `/webhook/github` | Öffentlich, HMAC-geprüft (`X-Hub-Signature-256`), Bot-Updates werden ignoriert |
