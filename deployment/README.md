# Pacientu anketu sistēma — Docker deployment

Šī pakotne palaiž Laravel aplikāciju uz viena Windows servera ar Docker Compose. Tā neveic development datubāzes importu un neizmanto `down -v`.

## Priekšnoteikumi

- Docker Desktop ar Linux containers un Compose v2;
- pietiekama diska vieta MariaDB datiem un private storage;
- production domēns/HTTPS risinājums, ja aplikācija būs publiski pieejama.

PHP un Node tiek iebūvēti image buildā. Runtime image izmanto PHP 8.4 FPM; frontend buildā tiek izmantots Node 24 un `npm ci`.

## Jauns Windows serveris

1. Uzinstalē Docker Desktop un palaid to ar Linux containers.
2. Nokopē vai klonē repo serverī.
3. No repo mapes palaid `deployment\INSTALL-SERVER.bat`. Pēc noklusējuma tas ir local-only un izmanto `http://localhost:8080`.
4. Installer izveido lokālu, ignorētu `.env.production`, ģenerē APP_KEY/DB paroles tikai pirmajā reizē, uzbūvē image un palaiž stack.
5. Installer migrē tukšu datubāzi, palaiž production seederi, izveido config/view cache un pārbauda `platform_admin`.
6. Ja root vēl nav, interaktīvi palaidīs esošo `php artisan app:create-admin` komandu. Izmanto tikai paša izvēlētu paroli; default parole netiek izmantota.
7. Pēc pabeigšanas pārbaudi `deployment\STATUS-SERVER.bat` un atver `http://localhost:8080`.

Ja vajadzīgs publisks HTTPS serveris, vienu reizi palaid `deployment\CONFIGURE-PUBLIC-SERVER.bat`. Tas pieprasa publisko HTTPS URL un Cloudflare Tunnel tokenu, ieslēdz secure cookies, aktivizē tunnel profilu un pārbauda health.

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

## HTTPS / Cloudflare Tunnel

Bez tunnel profila nginx pēc noklusējuma ir pieejams tikai uz `HTTP_BIND_ADDRESS`/`HTTP_PORT` (pēc noklusējuma localhost:8080). DB ports nav publicēts.

Cloudflare Zero Trust / Cloudflare Tunnel konfigurācijai:

1. Cloudflare Zero Trust panelī izveido Tunnel.
2. Tunnel konfigurācijā pievieno Public Hostname.
3. Hostname iestati uz izvēlēto publisko subdomēnu.
4. Service/Origin iestati uz `http://nginx:80`.
5. Pēc tam paņem tunnel tokenu.
6. Palaid `deployment\CONFIGURE-PUBLIC-SERVER.bat` un ievadi URL/tokenu tikai interaktīvi. Skripts tokenu saglabā tikai ignorētajā `.env.production` laukā `CLOUDFLARE_TUNNEL_TOKEN` un iestata `APP_URL=https://<hostname>`.
7. Turpmāk izmanto tikai `deployment\START-SERVER.bat`; ja token nav tukšs, START automātiski aktivizē tunnel profilu. Manuālā Compose alternatīva ir `docker compose --env-file .env.production --profile tunnel up -d`.

Cloudflared savienojas ar iekšējo `nginx` servisu; router port forwarding nav vajadzīgs. Tokenu neieraksti Git, dokumentācijā vai shell skriptā.

`TRUSTED_PROXIES` pēc noklusējuma norāda uz Compose privāto backend subnet `172.31.0.0/24`. Tas nav wildcard `*`; nemaini to uz plašāku tīklu bez atsevišķa drošības pamatojuma.

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
