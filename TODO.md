# TODO — Simple Game Interface (SGI)

Status-Legende: `[ ]` offen · `[~]` in Arbeit · `[x]` erledigt
Siehe [KONZEPT.md](KONZEPT.md) für Details.

---

## Phase 0 — Projekt-Grundgerüst
- [x] Verzeichnisstruktur anlegen (`public/`, `src/`, `public/api/`).
- [x] `index.html` nach `public/index.html` verschieben.
- [x] `composer.json` mit PSR-4-Autoload `tk\weslie\SGI\ → src/` (keine externen Deps).
- [x] `Dockerfile` (`php:8.3-apache`, DocumentRoot `public/`, `curl`-Extension aktiv).
- [x] Apache-Rewrite/`.htaccess`: `/api/*` → `public/api/index.php`.
- [x] `docker-compose.yml` (Port 8080, Docker-Socket ro, benanntes Volume `sgi_backup`:/backup, restart).
- [x] Smoke-Test: statisches Frontend erreichbar, Router liefert 401 ohne Token, JSON-Fehler bei Docker-Ausfall. *(Real-Container-Start auf Linux noch zu verifizieren.)*

## Phase 1 — Docker-Anbindung & Auth (Fundament)
- [x] `Docker/DockerClient.php`: HTTP über Unix-Socket via cURL (`CURLOPT_UNIX_SOCKET_PATH`).
      Methoden: `listContainers`, `inspect`, `stats`, `logs`, `start/stop/restart/kill`,
      plus `ensureImage/createContainer/runToCompletion/removeContainer` für Backups.
- [x] `Auth/TokenAuth.php`: Bearer-Token (oder `?token=` für Downloads) → Container per
      Label `sgi.token=<token>` auflösen (0 → 401, 1 → ok, >1 → 409). `Auth/ServerContext.php`.
- [x] `Http/Router.php` + `public/api/index.php`: Front-Controller, Routing,
      JSON-Responses, zentrale Auth-Prüfung. Client sendet **nie** Container-IDs.
- [x] Fehler-/Statuscodes einheitlich (400/401/404/409/500/502 + JSON `{error}`).

## Phase 2 — Status & Ressourcen
- [x] `Service/StatusService.php`: Inspect + Stats → `status`-Objekt gemäß API-Contract.
- [x] CPU-% aus `cpu_stats`/`precpu_stats`-Delta berechnen.
- [x] RAM aus `memory_stats` (usage − cache / limit) → MB.
- [x] Uptime aus `State.StartedAt`; `online` aus `State.Running`; Image → `version`.
- [x] `GET /api/status` verdrahtet.
- [x] Kachel-Mapping: `ping`/`players` als `null` (→ „—“); `disk` optional (`null` in v1).

## Phase 3 — Power-Aktionen
- [x] `Service/PowerService.php`: start/stop/restart/kill auf aufgelösten Container.
- [x] `POST /api/start | /stop | /restart | /kill` verdrahtet.
- [ ] Test mit echtem Container (Buttons → Container, Status aktualisiert sich).

## Phase 4 — Konsole
- [x] `Service/ConsoleService.php` (Ausgabe): `docker logs` mit `timestamps=1&since=<seq>`.
- [x] Log-Frame-Header (8-Byte-Prefix bei Nicht-TTY-Containern) korrekt parsen (`deframe`).
- [x] Response `{lines:[{seq,ts,text}], last}`, `seq`/`last` = Log-Timestamp (stateless-Cursor).
- [x] `GET /api/console?after=<seq>` verdrahtet.
- [x] Eingabe: `Docker/SocketStream.php` — roher Socket, `POST .../attach?stream=1&stdin=1`,
      HTTP-Upgrade/Hijack, Kommando + `\n` schreiben.
- [x] `POST /api/command` verdrahtet. Voraussetzung dokumentiert: Container mit `-i`.

## Phase 5 — Backups (Volume → tar)
- [x] Backup-Speicher: benanntes Volume `sgi_backup` unter `/backup/`, je Server `/backup/<ServerToken>/<name>.tar.gz`.
- [x] `Service/BackupService.php`:
  - [x] Zielordner je Server = `/backup/<ServerToken>/`, bei Bedarf über Helfer angelegt.
  - [x] `list()` — `scandir` auf `/backup/<ServerToken>/`, Metadaten (size/created) aus FS, `id` = Basename.
  - [x] `create()` — Helfer-Container `alpine`, `--volumes-from <gamecontainer>:ro` + `-v sgi_backup:/out`,
        `tar czf /out/<token>/<name>.tar.gz -C <sgi.backup.path> .` (Fallback: erstes Volume-Ziel).
  - [x] `restore(id)` — stop → Ziel-Pfad leeren + tar entpacken (Helfer, `--volumes-from` schreibend) → start.
  - [x] `delete(id)` — Datei aus `/backup/<ServerToken>/` löschen.
  - [x] `download(id)` — Datei aus `/backup/<ServerToken>/` streamen (Router).
  - [x] **Isolation/Path-Traversal-Schutz**: `id`/`name`/`token` validiert, nur Basename, hart auf
        `/backup/<ServerToken>/` des eingeloggten Tokens geklemmt.
- [x] Endpoints verdrahtet: `GET/POST /api/backups`, `POST /.../restore`,
      `GET /.../download`, `DELETE /api/backups/<id>`.

## Phase 6 — Feinschliff & Doku
- [ ] End-to-End-Test mit echtem Gameserver-Container (z.B. `itzg/minecraft-server`).
- [x] README: Setup, Token-Label setzen, Beispiel-`docker run`, Sicherheitshinweise.
- [ ] Frontend-Feinabstimmung, falls API-Felder abweichen (Kachel-Labels etc.).
- [x] `.dockerignore`, Healthcheck im Compose.

---

## Backlog / später (v2)
- [ ] `ping` & `players` via gamespezifisches Query-Protokoll.
- [ ] RCON-Eingabe als Alternative zu stdin.
- [ ] Login-Rate-Limiting / Brute-Force-Schutz.
- [ ] PyCharm-/IDE-Konfiguration (falls Tooling-Skripte in Python dazukommen).

> HTTPS/TLS wird vom vorgelagerten Reverse-Proxy übernommen → **kein** SGI-Task.
