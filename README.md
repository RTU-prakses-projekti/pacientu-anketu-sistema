# Universālais veidlapu veidotājs

Laravel projekts testu, anketu un veidlapu izveidei un aizpildīšanai. Anketas var manuāli izveidot sistēmas builderī pēc PDF parauga.

## Kas nepieciešams

- Git
- PHP 8.4.1 vai jaunāks
  - projekts pārbaudīts ar PHP 8.5
- Composer 2
- Node.js un npm
  - projekts pārbaudīts ar Node.js 24
- XAMPP ar MySQL/MariaDB

XAMPP komplektā esošais PHP 8.2 šim projektam neder. XAMPP var izmantot MySQL/MariaDB serverim, bet Laravel aplikācija jāpalaiž terminālī ar PHP 8.4.1 vai jaunāku versiju.

## 1. Lejupielādēt projektu

Ja projekts tiek klonēts ar Git:

```powershell
git clone https://github.com/RTU-prakses-projekti/Kontroldarbu-sistema.git
cd Kontroldarbu-sistema
git checkout universal-form-builder
```

## 2. Instalēt projekta atkarības

```powershell
composer install
npm.cmd install
```

## 3. Izveidot `.env`

Windows CMD:

```cmd
copy .env.example .env
```

PowerShell:

```powershell
Copy-Item .env.example .env
```

Pēc tam izveido aplikācijas atslēgu:

```powershell
php artisan key:generate
```

`.env` fails GitHub netiek glabāts, tāpēc katram tas jāizveido lokāli.

## 4. Datubāze

1. Atver XAMPP.
2. Palaid MySQL.
3. phpMyAdmin izveido tukšu datubāzi:

```text
kontroldarbu_sistema
```

Izmanto `utf8mb4` charset.

`.env` failā norādi:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=kontroldarbu_sistema
DB_USERNAME=root
DB_PASSWORD=
```

Ja lokālajam MySQL ir cita parole vai ports, ievadi savus datus.

Pēc tam izpildi migrācijas:

```powershell
php artisan migrate
```

Datubāzei ar saglabājamiem datiem neizmanto `migrate:fresh`, `db:wipe` vai rollback komandas.

## 5. Frontend

```powershell
npm.cmd run build
```

## 6. Izveidot pirmo administratoru

Tukšai datubāzei izpildi:

```powershell
php artisan app:create-admin
```

Ievadi administratora vārdu un e-pastu. Parole tiks prasīta terminālī, un tai jābūt vismaz 12 simbolus garai, ar burtiem un cipariem.

## 7. Palaist projektu

XAMPP panelī MySQL jābūt ieslēgtam. Laravel aplikāciju palaid projekta mapē:

```powershell
php artisan serve
```

Pārlūkā atver:

```text
http://127.0.0.1:8000
```

Neizmanto `localhost/Kontroldarbu-sistema/public`, ja XAMPP Apache izmanto PHP 8.2. Aplikācija jāpalaiž ar `php artisan serve` no PHP 8.4.1 vai jaunākas vides.

## 8. Ja `php -v` rāda nepareizu PHP

```powershell
php -v
where.exe php
```

Aktīvajai PHP versijai jābūt vismaz 8.4.1.

## 9. Kā izveidot anketu

1. Ielogojies administratora profilā.
2. Izvēlies vai izveido organizāciju.
3. Atver sadaļu “Formas”.
4. Izveido jaunu formu vai anketu.
5. Builderī manuāli pievieno sadaļas un jautājumus pēc dotā PDF parauga.
6. Saglabā un pārbaudi anketu ar Preview.
7. Kad anketa ir gatava, publicē to.

Anketu tekstus var ievadīt latviešu, angļu un krievu valodā (LV/EN/RU).

## 10. Testi (pēc izvēles)

```powershell
php artisan test
npm.cmd run build
```

## Anketas nodošana caur Git

1. Izveido savu branch ar savu vārdu, piemēram, `laura`, `gustavs` vai `janis`.
2. Savā branch palaid projektu, izveido anketu un pārbaudi to ar Preview.
3. Formas skatā pie vajadzīgās versijas nospied “Eksportēt uz Git”.
4. Veic commit savā branch — nav nepieciešams manuāli atlasīt tikai `questionnaires/` failus.
5. Push savu branch uz GitHub.
6. `.env`, MySQL datubāzes failus, SQL dumpus un `storage` runtime failus Git nepievieno.

## Biežākās problēmas

### Composer prasa jaunāku PHP

Pārbaudi versiju:

```powershell
php -v
```

Nepieciešams PHP 8.4.1 vai jaunāks.

### `php artisan migrate` nevar pieslēgties datubāzei

Pārbaudi:

- XAMPP MySQL ir ieslēgts;
- datubāze `kontroldarbu_sistema` ir izveidota;
- `.env` datubāzes dati ir pareizi.

### Pēc `.env` izmaiņām joprojām tiek izmantota vecā konfigurācija

```powershell
php artisan config:clear
```

> Projekts ir izstrādes/prakses projekts. Reālus sensitīvus pacientu datus bez atsevišķa drošības un privātuma novērtējuma neizmantot.
