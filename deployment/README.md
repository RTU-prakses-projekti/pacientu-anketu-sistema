# Pacientu anketu sistēma — Docker deployment

Šī pakotne palaiž Laravel aplikāciju uz viena Windows servera ar Docker Compose. Tā neveic development datubāzes importu un neizmanto `down -v`.

## Priekšnoteikumi

- Docker Desktop ar Linux containers un Compose v2;
- pietiekama diska vieta MariaDB datiem un private storage;
- publiskam demo nav vajadzīgs domēns; Quick Tunnel izmanto pagaidu random HTTPS adresi.

PHP un Node tiek iebūvēti image buildā. Runtime image izmanto PHP 8.4 FPM; frontend buildā tiek izmantots Node 24 un `npm ci`.

## Jauns Windows serveris

1. Uzinstalē Docker Desktop un palaid to ar Linux containers.
2. Nokopē vai klonē repo serverī.
3. No repo mapes palaid `deployment\INSTALL-SERVER.bat`. Pēc noklusējuma tas ir local-only un izmanto `http://localhost:8080`.
4. Installer izveido lokālu, ignorētu `.env.production`, ģenerē APP_KEY/DB paroles tikai pirmajā reizē, uzbūvē image un palaiž stack.
5. Installer migrē tukšu datubāzi, palaiž production seederi, izveido config/view cache un pārbauda `platform_admin`.
6. Ja root vēl nav, interaktīvi palaidīs esošo `php artisan app:create-admin` komandu. Izmanto tikai paša izvēlētu paroli; default parole netiek izmantota.
7. Pēc pabeigšanas pārbaudi `deployment\STATUS-SERVER.bat` un atver `http://localhost:8080`.

Publiskam, īslaicīgam HTTPS demo izmanto `deployment\START-PUBLIC-DEMO.bat`; tam nav vajadzīgs domēns, Cloudflare konts vai token.

Installer ir idempotents: atkārtota palaišana nepārraksta APP_KEY, DB paroles vai esošo root kontu un neveic destructive migrācijas.

## Ikdienas vadība

```text
deployment\START-SERVER.bat
deployment\STATUS-SERVER.bat
deployment\STOP-SERVER.bat
```

START neveic migrācijas, neveido adminu un nereģenerē APP_KEY. STOP izmanto tikai `docker compose stop`; datu volume netiek dzēsts.

## Fresh uninstall tests

Tikai test/staging vidē palaid `deployment\UNINSTALL-SERVER.bat`, ja jāatkārto instalācija no nulles. Tas dzēš šī Compose projekta konteinerus, tīklus, volumes, built image tagus, `.env.production` un install logu, tāpēc tiek neatgriezeniski zaudēti visi šī servera projekta DB un private storage dati. Uninstall turpinās tikai pēc precīzas `DELETE` ievades. Tas neizmanto globālu Docker cleanup un neaiztiek citus projektus.

## Public HTTPS demo / Cloudflare Quick Tunnel

Without the public demo, nginx is available only on `HTTP_BIND_ADDRESS`/`HTTP_PORT` (by default `http://localhost:8080`). The database port is not published.

### Start

1. Confirm the local stack is running and `http://localhost:8080/up` returns HTTP 200.
2. Run `deployment\START-PUBLIC-DEMO.bat`.
3. The script starts only a separate Cloudflare Quick Tunnel container with origin `http://nginx:80`; it does not reconfigure Laravel or recreate app, database, queue, or scheduler services.
4. Every start creates a fresh random `https://<random>.trycloudflare.com` address. No Cloudflare account, custom domain or token is required.
5. The script waits for a registered tunnel connection and checks Windows/default DNS plus public DNS resolvers. It then verifies HTTPS `/up`, `/login`, CSS/JS assets and mixed-content behavior. If a hostname is not ready, it removes only that demo tunnel and retries with a new hostname.
6. `deployment\PUBLIC-DEMO-URL.txt` is written only after the URL passes all readiness checks. Diagnostic output is stored separately and does not contain secrets.
7. On failure, the script reports the reason and leaves the local Laravel stack untouched.

### Stop

Run `deployment\STOP-PUBLIC-DEMO.bat` to stop and remove only the public demo tunnel. The database, application, nginx, queue, scheduler and Docker volumes remain running/persistent.

The Quick Tunnel hostname is temporary and changes on each successful start. Router port forwarding is not required. The local `.env.production`, `APP_URL` and `SESSION_*` settings are not changed by this transport-only flow.

`TRUSTED_PROXIES` is scoped to the Compose private edge/backend subnets `172.30.0.0/24,172.31.0.0/24`; it is not a wildcard.

## Backup

Palaid `deployment\BACKUP-SERVER.bat`. Backup izveido `deployment\backups\<timestamp>\` ar MariaDB `mariadb-dump`, `storage/app/private` saturu un deployment konfigurācijas kopiju, ieskaitot `.env.production`.

Backup direktorija satur secrets un jāglabā ar ierobežotām Windows ACL. Skripts neveic DB vai volume dzēšanu.

Restore: saglabā to pašu APP_KEY, palaid MariaDB/app stack, importē `database.sql`, atjauno `storage-app-private` saturu private storage volume un pārbaudi `STATUS-SERVER.bat`. Restore vispirms izmēģini izolētā staging vidē.

## Update flow

Konceptuāli: `git pull`, `docker compose --env-file .env.production build`, `docker compose --env-file .env.production up -d`. Ja release satur migrācijas, tās palaiž kontrolēti ar `docker compose --env-file .env.production exec -T app php artisan migrate --force`.

Pirms update izveido backup. Neizmanto `migrate:fresh`, `db:wipe`, `down -v` vai nejaušu rollback production datos.

## Drošības piezīmes

- Web servera root ir Laravel `public/`.
- `storage/app/private` nav nginx publiski pieejams.
- DB nav hostā publicēta.
- APP_DEBUG production jāpaliek `false`.
- APP_KEY tiek ģenerēts tikai pirmajā instalācijā un jāglabā ārpus Git.
- `SESSION_SECURE_COOKIE=true` paredz HTTPS.
- Queue worker un scheduler darbojas kā atsevišķi restartējami Compose servisi.
- `docker compose config` var izdrukāt env vērtības; neizmanto to ar reāliem secrets publiskā logā.
