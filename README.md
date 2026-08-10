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
  - setări Stripe keys + webhook secret;
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

- daca in `Admin -> Setari livrare` activezi `Generare AWB automata`, sistemul incearca sa genereze AWB automat cand comanda intra in `processing` (ex: dupa plata confirmata Stripe);
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

### Newslettere programate (obligatoriu cron)

Newsletterele programate (status `scheduled` + `scheduled_at`) NU se trimit
singure — trebuie un cron care ruleaza scriptul de dispatch. Fara acest cron,
campaniile programate raman in asteptare si nu pleaca niciodata.

```bash
php /home/USER/public_html/scripts/newsletter-campaigns.php
```

Recomandare cron: la fiecare 5 minute:

```
*/5 * * * * php /home/USER/public_html/scripts/newsletter-campaigns.php >/dev/null 2>&1
```

Scriptul preia campaniile a caror ora a trecut (`scheduled_at <= NOW()`) si le
trimite, deci o campanie programata pleaca in maxim ~5 minute dupa ora setata.

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
