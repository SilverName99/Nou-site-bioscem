# Nou-site-bioscem

Migrare `bioscem.ro` către un magazin custom PHP (backend preluat din proiectul Mutare-Site-Biovitality, rebranduit pentru Bioscem).

> **De actualizat înainte de lansare:** datele operatorului din textul de consimțământ GDPR (`src/Http/Controllers/SiteController.php`) sunt încă cele ale entității juridice anterioare (SC Amalbo Consulting SRL) — trebuie înlocuite cu datele firmei clientului Bioscem. Verifică și adresele de email (`contact@bioscem.ro`, `gdpr@bioscem.ro`) și logo-ul din `/uploads/gallery/bioscem-logo.png`.

## Ce conține această versiune

- structură aplicație PHP (fără framework extern, potrivită pentru shared hosting);
- routing centralizat (`public/index.php`);
- pagini publice de bază: acasă, magazin, produs, coș, checkout, cont, contact;
- coș persistent în sesiune (adăugare/actualizare/ștergere);
- cupoane active + aplicare discount;
- prag transport gratuit (București/provincie) configurabil din admin;
- checkout funcțional cu salvare comandă și produse comandate în DB;
- pagină de succes după checkout;
- admin minim:
  - login admin;
  - dashboard cu metrici;
  - listă produse;
  - formular produs nou;
  - listă comenzi;
  - setări livrare FAN (skeleton configurabil);
  - setări plăți: EuPlătesc (merchant ID + cheie secretă, URL de notificare) și, opțional, Stripe;
  - secțiune Pagini (editor HTML cu preview live + mod desktop/tabletă/telefon);
  - secțiune Galerie (gestionare imagini);
  - secțiune Design Site (editare Header/Footer/Meniu cu preview);
  - coș de gunoi pentru Pagini și Produse (refacere + ștergere definitivă);
- Galerie cu selecție multiplă și ștergere bulk;
- randare pagini publice custom pe baza slug-ului (ex: `/despre-noi`);
- schemă SQL pentru tabelele principale e-commerce;
- scripturi de instalare și seed.

## Structură proiect

```txt
config/
database/
public/
scripts/
src/
views/
```

## Instalare locală / server

1. Copiază `.env.example` în `.env` și completează datele DB.
2. Asigură-te că document root este `public/` (sau folosește `.htaccess` în `public_html` care pointează aici).
3. Rulează instalarea:

```bash
php scripts/install.php
php scripts/seed.php
```

4. Intră în `/admin/login` cu:
   - email din `ADMIN_DEFAULT_EMAIL`
   - parolă din `ADMIN_DEFAULT_PASSWORD`

## Deploy pe Hostinger (shared)

### Varianta simplă (fără Git pe server)

1. Clonezi/pulli repository local.
2. Uploadezi fișierele în `public_html` păstrând structura.
3. Setezi `public_html` să servească `public/index.php` (direct sau prin rewrite).
4. Creezi DB și actualizezi `.env`.
5. Rulezi `scripts/install.php` și `scripts/seed.php` (CLI SSH sau temporar din browser cu protecție).

### Warm-up pentru primul request (shared hosting)

Pe shared hosting, primul request după idle poate fi mai lent (worker PHP/OPcache „cold”).
Poți reduce asta cu un cron la 5 minute care lovește endpoint-ul de health:

```bash
wget -q -O /dev/null https://domeniul-tau.tld/health
```

### FAN Courier - automatizari AWB + tracking

- daca in `Admin -> Setari livrare` activezi `Generare AWB automata`, sistemul incearca sa genereze AWB automat cand comanda intra in `processing` (ex: dupa confirmarea platii cu cardul);
- cand comanda este marcata `completed` si are AWB, sistemul trimite automat clientului email cu:
  - codul de urmarire (AWB)
  - link direct catre pagina FAN de tracking;
- pentru sincronizarea periodica a tracking-ului FAN la toate comenzile cu AWB, adauga un cron:

```bash
php /home/USER/public_html/scripts/fan-tracking-sync.php --limit=150
```

Recomandare cron: la 10-15 minute.

### Email-uri (template-uri + test + abandon cos)

- in admin exista modulul `Email-uri` (`/admin/emails`) unde poti:
  - configura expeditorul email (`From Name`, `From Email`);
  - edita template-urile pentru: comanda noua, procesare, expediere, livrare/finalizare, anulare, abandon cos;
  - trimite email de test;
  - vedea preview live cu date demo.
- trigger-ele reale sunt legate in cod pentru:
  - `new_order`, `processing`, `shipped`, `delivered`, `cancelled`.
- abandon cos se trimite prin cron, pe sesiuni neconvertite:

```bash
php /home/USER/public_html/scripts/abandoned-cart-emails.php --limit=100
```

> **ATENTIE la comenzile de mai sus si de mai jos:** `USER` este un substituent,
> nu un nume de cont. Inlocuieste-l cu userul real de gazduire (pe Hostinger e
> de forma `u742855921`) si verifica in File Manager traseul exact pana la
> folderul `scripts/`. Un cron copiat cu `USER` in el nu da eroare vizibila
> nicaieri — pur si simplu nu ruleaza niciodata. Dupa ce il adaugi, deschide
> „View Output" din panoul de cron si asigura-te ca vezi linia de rezultat a
> scriptului, nu „No such file or directory".

### Newslettere (obligatoriu cron)

Cronul de newsletter face doua lucruri:

1. **continua campaniile ramase in curs** (status `sending`) — o lista de zeci
   de mii de abonati nu pleaca dintr-o singura executie PHP, asa ca trimiterea
   merge pe bucati si fiecare rulare o duce mai departe de unde a ramas;
2. **porneste campaniile programate** ajunse la scadenta (`scheduled_at <= NOW()`).

Fara acest cron, campaniile programate nu pleaca deloc, iar cele mari raman
neterminate pana cand cineva apasa din nou „Trimite acum" pentru fiecare lot.

```bash
php /home/USER/public_html/scripts/newsletter-campaigns.php --seconds=240
```

Recomandare cron: la fiecare 5 minute:

```
*/5 * * * * php /home/USER/public_html/scripts/newsletter-campaigns.php --seconds=240 >/dev/null 2>&1
```

Optiuni: `--seconds=` bugetul de timp al unei rulari (implicit 240; se opreste
curat inainte de limita de executie a serverului, iar restul pleaca la trecerea
urmatoare), `--per-run=` cati destinatari cel mult per campanie per trecere
(implicit 2000), `--limit=` cate campanii programate se pornesc odata.

Rularile nu se suprapun: scriptul ia un lacat pe fisier
(`storage/newsletter-cron.lock`) si iese imediat daca precedenta inca lucreaza.

### Recomandări next sprint

- extindere Stripe (refund-uri din admin, retry plată, audit trail webhook);
- integrare FAN Courier API pentru AWB/tracking real;
- emailuri tranzacționale prin SendGrid;
- login Google;
- migrare date reale din WooCommerce (produse, clienți, comenzi, puncte fidelizare).

## Import produse + pagini de pe bioscem.ro (fără acces admin WordPress)

Scriptul `scripts/import-bioscem.php` preia produsele și paginile direct de pe
site-ul vechi, folosind doar endpoint-uri publice (WooCommerce Store API +
WordPress REST API) — nu are nevoie de niciun cont WordPress. Se rulează de pe
serverul Hostinger (SSH) sau de pe orice calculator cu PHP și acces la internet
și la baza de date a noului site.

```bash
# 1. test fără scriere în DB (nu necesită nici măcar .env configurat)
php scripts/import-bioscem.php --dry-run --limit=10

# 2. importul real (necesită .env cu datele DB + php scripts/install.php rulat)
php scripts/import-bioscem.php
```

Opțiuni utile:

- `--base-url=https://bioscem.ro` — sursa (implicit bioscem.ro);
- `--limit=N` — importă doar primele N produse (pentru test);
- `--skip-images` — nu descarcă imaginile local, păstrează URL-urile de pe
  site-ul vechi (bun pentru un test rapid; fără această opțiune imaginile se
  descarcă în `public/uploads/products/`);
- `--skip-products` / `--skip-pages` — importă doar cealaltă categorie;
- `--default-stock=N` — stocul setat produselor disponibile (implicit 100;
  stocul real nu este expus public de WooCommerce, deci trebuie ajustat
  ulterior din admin).

Scriptul e idempotent: rulat de mai multe ori, actualizează după `slug` în loc
să dubleze. Paginile de sistem WooCommerce (cart, checkout, my-account etc.)
sunt sărite automat, pentru că noua aplicație are propriile pagini.

## Secțiuni din descriere → câmpuri suplimentare (tab-uri)

Descrierile importate din WooCommerce conțin secțiuni („Caracteristici”,
„Mod de utilizare”, „Ingrediente”, „Precauții” etc.), diferite de la produs la
produs. Cele două scripturi de mai jos le detectează și le transformă automat
în câmpuri suplimentare, ca să apară ca tab-uri pe pagina produsului.

Un titlu de secțiune este recunoscut când paragraful începe cu text îngroșat
urmat de „:” (`<p><strong>Caracteristici</strong>:`), când paragraful e format
doar din text îngroșat, sau când e un `<h2>`/`<h3>`. Textul îngroșat folosit ca
accent în frază (`<strong>Compensează</strong> efectele...`) nu este confundat
cu un titlu.

Titlurile sinonime sunt grupate automat într-un singur câmp: „Mod de
administrare”, „Instrucțiuni de utilizare”, „Mod de folosire” etc. ajung toate
în **Mod de utilizare**, iar „Beneficiile SILICIUM G7”, „De ce să alegi X”,
„Importanța magneziului” ajung în **Beneficii**. Fără grupare ar rezulta ~80 de
câmpuri; cu grupare rămân ~17 relevante.

### 1. Analiză (nu modifică nimic)

```bash
php scripts/analyze-product-sections.php
php scripts/analyze-product-sections.php --by-category
php scripts/analyze-product-sections.php --product=<slug>      # detaliu pe un produs
php scripts/analyze-product-sections.php --csv=sectiuni.csv    # export pentru Excel
php scripts/analyze-product-sections.php --raw                 # fara grupare sinonime
```

### 2. Aplicare

```bash
php scripts/apply-product-sections.php --min=3 --dry-run
php scripts/apply-product-sections.php --min=3
```

- `--min=N` — mută doar secțiunile prezente la minim N produse (implicit 3);
  cele sub prag rămân în descriere. Cu `--min=1` devin câmpuri toate secțiunile.
- `--fields=ingrediente,mod_de_utilizare` — doar cheile listate.
- `--exclude=nota` — sare peste anumite chei.
- `--keep-description` — nu șterge secțiunile din descriere (atenție: conținut
  dublat între descriere și tab-uri).

Câmpurile sunt create cu tipul `html`, deci păstrează formatarea. Fiecare produs
primește doar câmpurile care există în descrierea lui, iar tab-urile se afișează
doar pentru câmpurile cu valoare — deci produse diferite ajung automat cu
tab-uri diferite, fără configurare manuală.

Înainte de orice modificare, descrierile originale sunt salvate în
`storage/backups/product-descriptions-<data>.json`. Revenire completă:

```bash
php scripts/apply-product-sections.php --restore=storage/backups/product-descriptions-<data>.json
```

## Produse similare (carusel) alocate automat

`scripts/set-similar-products.php` alocă fiecărui produs un set de produse
similare, preferând aceeași categorie și completând din restul catalogului.

```bash
php scripts/set-similar-products.php --dry-run
php scripts/set-similar-products.php                  # implicit intre 5 si 8
php scripts/set-similar-products.php --min=4 --max=6
```

Opțiuni: `--only-missing` (doar produsele fără selecție), `--any-category`
(ignoră categoria), `--seed=N` (rezultat reproductibil), `--clear` (șterge
toate selecțiile).

## Curățarea denumirilor de produs

Unele denumiri importate din WooCommerce conțin HTML (`QUINTON IZOTONIC
<br>fiole`), care apare vizibil în pagină pentru că titlurile sunt afișate ca
text simplu.

```bash
php scripts/clean-product-names.php --dry-run
php scripts/clean-product-names.php
php scripts/clean-product-names.php --with-pages    # si titlurile paginilor
```

Importatorul curăță acum denumirile la sursă, deci importurile viitoare nu mai
au nevoie de acest pas.

## Atribuirea unui template tuturor produselor

`scripts/set-product-template.php` aplică un template de produs în masă, ca să
nu fie nevoie de editare manuală produs cu produs.

```bash
# vezi ce template-uri există (id + slug)
php scripts/set-product-template.php --list

# test, fără scriere în DB
php scripts/set-product-template.php --template=<slug> --dry-run

# aplicare pe toate produsele active
php scripts/set-product-template.php --template=<slug>
```

Opțiuni: `--category=<slug>` (doar o categorie), `--only-missing` (doar
produsele fără template), `--include-inactive`, `--include-trashed`,
`--template=none` (scoate template-ul). Rularea e tranzacțională și sare peste
produsele care au deja template-ul respectiv.

## Migrare utilizatori din WordPress (fără puncte)

1. Exportă din WordPress un CSV cu minim coloanele:
   - `user_email` (sau `email`)
   - `user_pass` (hash parolă WP) sau `password_hash`
   - opțional: `first_name`, `last_name`, `phone`, `user_registered`
2. Rulează importul în aplicația nouă:

```bash
php scripts/import-wordpress-users.php /cale/catre/users-export.csv
```

Note:
- scriptul face insert/update pe `email`;
- hash-urile WordPress sunt acceptate la login și se convertesc automat la bcrypt după prima autentificare reușită.
