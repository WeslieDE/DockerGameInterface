# KONZEPT — Simple Game Interface (SGI)

**Projektname:** Simple Game Interface (kurz **SGI**)
**Namespace:** `tk.weslie.SGI`
**Backup-Volume:** benanntes Docker-Volume `sgi_backup`, in **jedem** Container unter
`/backup/` gemountet — pro Server ein Unterordner `/backup/<ServerToken>/`.

> Hinweis: SGI läuft dediziert in einem Docker-System. Es wird **kein**
> Host-Userdaten-Pfad (`~/.weslie/SGI/`) und **kein** Host-Bind-Mount genutzt.
> Die einzige persistente Ablage ist das **benannte Volume `sgi_backup`**. Weil
> Docker benannte Volumes **per Name** auflöst, kann jeder (auch von SGI gestartete
> Helfer-)Container es ohne Kenntnis eines Host-Pfades einbinden.
>
> Das Volume ist in allen Gameserver-Containern **und** im SGI-Container identisch
> unter `/backup/` gemountet. Die Gameserver sehen darin alle Token-Unterordner;
> die **Token→Pfad-Zuordnung (Zugriffstrennung) erzwingt allein das Webinterface**,
> indem es je Login nur `/backup/<ServerToken>/` liest und schreibt.

Minimales, **stateless** Game-Server-Panel. Gameserver werden als Docker-Container
verwaltet (Start/Stop/Restart/Kill, Konsole, Backups). Das Panel selbst läuft
ebenfalls in Docker und steuert den Host-Docker über den **Docker-Socket**.

---

## 1. Leitprinzipien

- **Stateless** — keine Datenbank, keine Server-Sessions. Alle Daten kommen zur
  Laufzeit ausschließlich aus:
  - **Docker Runtime** (Container-Liste, Inspect, Stats, Logs, Labels)
  - **Ordnerinhalten** (Backup-Verzeichnis)
- **Token-basiertes Login** — kein Username/Passwort. Der **Server-Token** ist als
  Docker-**Label** am laufenden Container hinterlegt (`-l sgi.token=<token>`) und
  gibt Zugriff auf **genau diesen einen** Container (1 Token = 1 Container).
- **Least Privilege im Backend** — der Client sendet **niemals** eine Container-ID.
  Das Backend löst den Ziel-Container bei **jedem** Request serverseitig aus dem
  Token-Label auf. Es wird nie ein beliebiger Container über Client-Input adressiert.

---

## 2. Technologie-Stack

| Ebene        | Wahl                                                              |
|--------------|------------------------------------------------------------------|
| Frontend     | Bestehende `index.html` (Vanilla HTML/CSS/JS, keine Libs)        |
| Backend      | **PHP 8.3**, PSR-4 Autoloading unter `tk\weslie\SGI\`            |
| Webserver    | `php:8.3-apache` (ein Container, minimal)                        |
| Docker-Zugriff | Docker Engine API über Unix-Socket (`/var/run/docker.sock`) via cURL (`CURLOPT_UNIX_SOCKET_PATH`) |
| Persistenz   | **keine** — Runtime-Daten + Backup-Ordner                        |

---

## 3. Architektur

```
┌───────────────────────────────────────────────────────────┐
│  Browser (index.html)                                       │
│   - Login mit Server-Token → localStorage                   │
│   - fetch() an /api/*  (Authorization: Bearer <token>)      │
└───────────────┬───────────────────────────────────────────┘
                │ HTTP
┌───────────────▼───────────────────────────────────────────┐
│  SGI-Container (php:8.3-apache)                             │
│   public/index.html      (Frontend, statisch)              │
│   public/api/index.php    (Front-Controller / Router)      │
│   src/ tk\weslie\SGI\...  (Auth, DockerClient, Controller) │
│        │                                                    │
│        │  Unix-Socket  (read-only mount)                    │
└────────┼───────────────────────────────────────────────────┘
         │ /var/run/docker.sock
┌────────▼───────────────────────────────────────────────────┐
│  Host Docker Engine                                        │
│   ┌──────────────┐   ┌──────────────┐                      │
│   │ Gameserver A │   │ Gameserver B │  … (Label: sgi.token)│
│   └──────────────┘   └──────────────┘                      │
│   Backup-Ordner  →  Mount  /backup/<ServerToken>/          │
└────────────────────────────────────────────────────────────┘
```

---

## 4. Container-Konvention (Labels)

Ein vom SGI verwalteter Gameserver-Container trägt folgende Labels:

| Label                 | Pflicht | Bedeutung                                                        |
|-----------------------|---------|------------------------------------------------------------------|
| `sgi.token`           | ✅      | Geheimer Server-Token = Login-Zugang zu genau diesem Container.  |
| `sgi.name`            | –       | Anzeigename (Fallback: Container-Name).                          |
| `sgi.backup.path`     | –       | Pfad **im Gameserver-Container**, der gesichert wird (z.B. `/data`). Fallback: Mount-Ziel des ersten Named-Volumes des Containers. |
| `sgi.enabled`         | –       | `true`/`false` — SGI-Verwaltung aktiv (Default: aktiv, wenn Token gesetzt). |

Voraussetzungen für volle Funktion:
- **stdin offen** (`-i` / `stdin_open: true`, `tty: false`) → Konsolenbefehle via `docker attach`.
- Spieldaten liegen in einem **Volume oder Bind-Mount** → Backups.
- Das Volume **`sgi_backup`** ist unter `/backup/` gemountet → gemeinsamer Backup-Speicher.

---

## 5. Login-Ablauf (Token → Container)

1. Nutzer gibt Server-Token ein → wird in `localStorage` (`dgi.token`) gespeichert.
2. Jeder API-Request trägt `Authorization: Bearer <token>`.
3. Backend fragt Docker: `GET /containers/json?all=1&filters={"label":["sgi.token=<token>"]}`.
4. **Genau 1 Treffer** → Zugriff auf diesen Container. **0 Treffer** → `401`.
   Mehrere Treffer (Fehlkonfiguration) → `409`/Ablehnung.
5. Kein Server-State: Die Auflösung passiert bei jedem Request neu.

> Sicherheitshinweis: Wer Docker-Zugriff auf dem Host hat, kann Labels lesen. Der
> Token schützt gegen Web-Zugriff, nicht gegen Host-Admins. Tokens sollten lang &
> zufällig sein (z.B. 32+ Zeichen).

---

## 6. Funktionsliste (aus der Demo `index.html` abgeleitet)

### 6.1 Frontend (bereits in der Demo vorhanden)
- **Login-Ansicht** mit Token-Feld, Fehlermeldung, „Sign in“.
- **Token-Persistenz** in `localStorage`, Auto-Login beim Laden, Logout.
- **Fehlerbehandlung** — bei nicht erreichbarer API klare Fehlermeldung; im Login als Text, in laufender Session Toast + Zwangslogout (kein Offline-/Demo-Fallback).
- **App-Shell** — Topbar (Logo, Logout), Tab-Navigation (System / Backups).
- **Seite „Server Console“ (System):**
  - Status-Kacheln (4×): aktuell Ping, RAM, CPU, Players.
  - Online/Offline-Statusanzeige (Dot + Text) mit Servernamen.
  - **Live-Terminal** — Log-Ausgabe mit Zeitstempel & Zeilen-Klassifizierung
    (info/warn/err/cmd), Auto-Scroll.
  - **Befehlseingabe** — Kommando an Server senden (Enter/Button).
  - **Power-Buttons** — Start, Restart, Stop, Kill (mit Bestätigungsdialog bei Stop/Kill).
  - **Charts (client-seitig)** — RAM- und CPU-Sparkline, live aus Status-Polling
    aufgebaut (kein History-Endpoint; ~40 Punkte, 5-Sekunden-Takt).
- **Seite „Backups“:**
  - Backup-Liste als Karten (Name, Größe, Datum, ID).
  - **Backup erstellen**.
  - Pro Backup: **Restore**, **Download**, **Delete** (jeweils mit Bestätigung).
- **Toast-Benachrichtigungen** für Aktionsergebnisse.
- **Polling** — Status alle 5 s, Konsole alle 2 s.

### 6.2 Backend-API (umzusetzen — Contract steht in der Demo)
Alle Endpoints mit Header `Authorization: Bearer <token>`; Token → Container-Auflösung serverseitig.

| Methode | Pfad                         | Funktion                                                            |
|---------|------------------------------|--------------------------------------------------------------------|
| GET     | `/api/status`                | Live-Status: `online, name, address, version, players, uptime, resources{ping, ram{used,max}, cpu, disk{used,max}}`. Quelle: Docker Inspect + Stats. |
| POST    | `/api/start`                 | Container starten.                                                  |
| POST    | `/api/stop`                  | Container graceful stoppen.                                         |
| POST    | `/api/restart`               | Container neu starten.                                              |
| POST    | `/api/kill`                  | Container hart beenden (SIGKILL).                                   |
| GET     | `/api/console?after=<seq>`   | Inkrementelle Logausgabe. `seq` = opaker Cursor (Log-Timestamp). Quelle: `docker logs`. |
| POST    | `/api/command`               | `{command}` in Container-stdin schreiben (via `docker attach`).     |
| GET     | `/api/backups`               | Backup-Liste aus Backup-Ordner (`readdir`).                        |
| POST    | `/api/backups`               | Neues Backup: Volume → `.tar.gz` in Backup-Ordner.                 |
| POST    | `/api/backups/<id>/restore`  | Backup zurückspielen (Container stoppen, Volume ersetzen, starten).|
| GET     | `/api/backups/<id>/download` | Backup-Datei als Download ausliefern.                             |
| DELETE  | `/api/backups/<id>`          | Backup-Datei löschen.                                              |

---

## 7. Umsetzung der Kernmechaniken

### 7.1 Status & Ressourcen
- `GET /containers/{id}/json` (Inspect) → `online` (State.Running), `uptime` (State.StartedAt),
  Name, Image (→ „version“), Health.
- `GET /containers/{id}/stats?stream=false` → **RAM** (`memory_stats.usage`/`limit`),
  **CPU** (Delta-Berechnung aus `cpu_stats`/`precpu_stats`).
- **Kachel-Mapping** (Docker liefert keine Spiel-Metriken):
  - `ram` = Docker Memory Stats ✅
  - `cpu` = Docker CPU-% ✅
  - `disk` = Größe des Backup-Volumes / Mount (optional)
  - `ping` / `players` = spielspezifisch → v1: leer/`—` oder später via optionalem Query-Protokoll.

### 7.2 Konsole (Logs + Eingabe)
- **Ausgabe:** `GET /containers/{id}/logs?stdout=1&stderr=1&timestamps=1&since=<seq>`.
  Rückgabe im Format `{lines:[{seq,ts,text}], last}`; `last` = neuester Log-Timestamp
  (wird vom Frontend als `after` zurückgeschickt → stateless-kompatibel).
  *Hinweis:* Log-Stream-Header-Framing (8-Byte-Prefix) bei Containern ohne TTY beachten.
- **Eingabe:** `POST /containers/{id}/attach?stream=1&stdin=1` über rohen Unix-Socket
  (HTTP-Upgrade/Hijack), Kommando + `\n` schreiben. Erfordert Container mit offenem stdin.

### 7.3 Backups (Volume → tar)
- **Speicher:** benanntes Volume `sgi_backup`, in allen Containern unter `/backup/`.
  Je Server ein Unterordner `/backup/<ServerToken>/`. **Kein Host-Pfad nötig** —
  Helfer-Container binden das Volume per Name ein.
- **Erstellen:** Ephemeren Helfer-Container starten, der die Spieldaten **read-only**
  (per `--volumes-from`) einliest und ins `sgi_backup`-Volume schreibt:
  ```bash
  docker run --rm --volumes-from <gamecontainer>:ro -v sgi_backup:/out alpine \
    sh -c 'mkdir -p /out/<token> && tar czf /out/<token>/<name>.tar.gz -C <sgi.backup.path> .'
  ```
  → SGI mountet **keine Game-Volumes** in sich hinein — der Zugriff auf die Spieldaten
    läuft docker-nativ über den ephemeren Helfer. Das **Backup-Volume** (`sgi_backup`)
    hat SGI hingegen selbst unter `/backup/` gemountet, damit das Webinterface Liste,
    Download und Delete direkt über das Dateisystem bereitstellen kann.
    `<sgi.backup.path>` kommt aus dem Label (Fallback: erstes Named-Volume-Ziel des
    Gameservers).
- **Liste:** Dateien in `/backup/<ServerToken>/*.tar.gz` lesen — SGI hat `sgi_backup`
  selbst unter `/backup/` gemountet (`id` = Dateiname, `size`/`created` aus FS-Metadaten).
- **Restore:** Container stoppen → Ziel-Pfad leeren + tar zurückentpacken (Helfer-Container,
  `--volumes-from <gamecontainer>` schreibend) → Container starten.
- **Download/Delete:** Datei direkt aus `/backup/<ServerToken>/` streamen bzw. löschen.
- **Isolation & Sicherheit:** SGI operiert **ausschließlich** in `/backup/<ServerToken>/`
  des eingeloggten Tokens. `id`/`name` validieren, nur Basename zulassen
  (Path-Traversal-Schutz), damit kein Ausbruch in fremde Token-Ordner möglich ist.

---

## 8. Deployment

> **Betriebsannahme:** SGI läuft hinter einem vorgelagerten Reverse-Proxy, der
> HTTPS/TLS terminiert. Der Container exponiert nur HTTP (Port 80). Kein TLS-Handling
> in SGI selbst.

- **`Dockerfile`** — `php:8.3-apache`, Apache-DocumentRoot auf `public/`, `.htaccess`
  Rewrite `/api/...` → `public/api/index.php`.
- **`docker-compose.yml`:**
  ```yaml
  services:
    sgi:
      build: .
      ports: ["8080:80"]
      volumes:
        - /var/run/docker.sock:/var/run/docker.sock:ro   # Steuerung Host-Docker
        - sgi_backup:/backup                              # Backup-Speicher (/backup/<ServerToken>/)
      restart: unless-stopped

  volumes:
    sgi_backup:
      external: true    # zentrales, gemeinsames Backup-Volume (auch von Gameservern genutzt)
  ```
  > Benanntes Volume `sgi_backup` — **kein** Host-Pfad. Der Docker-Daemon löst es per
  > Name auf, sodass SGI-Helfer-Container es ohne Host-Pfad-Kenntnis einbinden können.
- Beispiel Gameserver-Start mit Token-Label und gemeinsamem Backup-Volume:
  ```bash
  TOKEN=$(openssl rand -hex 16)
  docker run -d -i --name mc \
    -l sgi.token=$TOKEN \
    -l sgi.name="My Minecraft" \
    -l sgi.backup.path=/data \
    -v mc_data:/data \
    -v sgi_backup:/backup \
    itzg/minecraft-server
  ```
  Der Gameserver sieht seinen eigenen Ordner unter `/backup/$TOKEN/` (und technisch
  auch die übrigen). Die Zugriffstrennung erzwingt allein SGI beim Login.

---

## 9. Verzeichnisstruktur (Ziel)

```
SGI/
├── KONZEPT.md
├── TODO.md
├── Dockerfile
├── docker-compose.yml
├── public/
│   ├── index.html            # Frontend (aus bestehender Demo)
│   └── api/
│       └── index.php         # Front-Controller / Router
├── src/                      # tk\weslie\SGI\  (PSR-4)
│   ├── Http/Router.php
│   ├── Auth/TokenAuth.php
│   ├── Docker/DockerClient.php
│   ├── Docker/SocketStream.php     # roher Socket für attach/logs
│   ├── Service/StatusService.php
│   ├── Service/ConsoleService.php
│   ├── Service/PowerService.php
│   └── Service/BackupService.php
└── composer.json             # nur Autoload, keine externen Abhängigkeiten nötig
```

---

## 10. Offene / spätere Punkte

- `ping` & `players`: optionales gamespezifisches Query-Protokoll (v2).
- RCON als Alternative zur stdin-Eingabe (v2).
- Mehrere Container pro Token / Server-Auswahl (aktuell bewusst **nicht** vorgesehen).
- Rate-Limiting / Brute-Force-Schutz am Login (Token-Erraten).

> **HTTPS/TLS ist kein Bestandteil von SGI.** Das System läuft im Betrieb hinter
> einem vorgelagerten Reverse-Proxy, der TLS terminiert. SGI selbst spricht nur
> HTTP (Port 80 im Container) und geht davon aus, hinter einem Proxy zu stehen.
