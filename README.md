# Nou-site-bioscem

Migrare `bioscem.ro` către un magazin custom PHP (backend preluat din proiectul Mutare-Site-Biovitality, rebranduit pentru Bioscem).

> **De actualizat înainte de lansare:** datele operatorului din textul de consimțământ GDPR (`src/Http/Controllers/SiteController.php`) sunt încă cele ale entității juridice anterioare (SC Amalbo Consulting SRL) — trebuie înlocuite cu datele firmei clientului Bioscem. Verifică și adresele de email (`contact@bioscem.ro`, `gdpr@bioscem.ro`) și logo-ul din `/uploads/gallery/bioscem-logo.png`.

## Ce conține această versiune

- structură aplicație PHP (fără framework extern, potrivită pentru shared hosting);
- routing centralizat (`public/index.php`);
- pagini publice de bază: acasă, magazin, produs, coș, checkout, cont, contact;
- cos care ramane plin 30 de zile, peste pauze si peste inchiderea browserului;
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
  - setări plăți: EuPlătesc (merchant ID + cheie secretă, URL de notificare), opțional Stripe și
    Banca Transilvania iPay (rate și puncte STAR) — fiecare procesator cu bifa lui; cheile secrete
    nu se mai afișează în pagină (vezi secțiunea „Plata cu cardul prin Banca Transilvania");
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

- lista de puncte FANbox se tine local (asa o cere FAN la emiterea AWB-ului,
  dupa id-ul lor). Daca ramane veche, un punct inchis de FAN ramane vizibil la
  checkout si AWB-ul e refuzat abia la expediere, cu `awbGeneration.lockerInactive`.
  Se poate improspata oricand din `Admin -> Setari livrare -> FANbox`, dar mai
  bine printr-un cron zilnic:

```bash
php /home/USER/public_html/scripts/fan-lockers-sync.php
```

Recomandare cron: o data pe zi (lista se schimba rar). Daca FAN raspunde cu o
lista goala, scriptul se opreste fara sa modifice nimic — altfel ar dezactiva
tot nomenclatorul.

- localitatile FAN (dropdown-ul din checkout), lista de localitati cu km
  suplimentari si lista de strazi vin din API-ul FAN (`/reports/localities`,
  `/reports/streets`). Lista de km suplimentari e exact ce are FAN cu
  `exteriorKm > 0` — inainte se incarca din fisier si ajunsese sa contina toata
  tara, deci aproape orice comanda primea taxa. Fiecare tab din
  `Admin -> Setari livrare` are butonul lui de sincronizare si arata data
  ultimei sincronizari si eroarea ultimei incercari. Cron zilnic, noaptea
  (comanda exacta, cu calea reala de pe server, e afisata si pe tab-uri):

```
15 1 * * * php /home/USER/public_html/scripts/fan-nomenclator-sync.php
```

  Optional `--lista=localitati` sau `--lista=strazi`. Listele se aduna intai
  in tabele-ciorna (`fan_localities__sync`, `fan_localities_extra_km__sync`,
  `fan_streets__sync`) si iau locul celor folosite de site printr-un singur
  `RENAME TABLE`, abia dupa ce a venit tot: daca FAN pica la jumatate, intoarce
  gol, fara `exteriorKm` sau mult mai putine randuri decat anunta, site-ul
  ramane pe listele vechi. Rularile nu se suprapun (lacat MySQL `GET_LOCK`).
  Strazile sunt peste o suta de pagini, asa ca butonul din admin lucreaza cate
  ~15 secunde pe cerere si pagina se retrimite singura pana la final; o
  sincronizare lasata la jumatate o termina cronul. Importul din fisier ramane
  ca rezerva (cel de km suplimentari inlocuieste lista si, daca fisierul are o
  coloana de km, ia doar randurile cu km > 0).

### Precomanda

- pe fisa produsului, in `Admin -> Produse`, exista bifa **„Valabil pentru
  precomanda"** si doua plafoane:
  - **Maxim per comanda** — cate bucati poate lua un client intr-o comanda;
  - **Maxim precomandat (total)** — cate bucati se pot precomanda cu totul, pe
    toate comenzile.
  Ambele goale inseamna „fara limita";
- produsul bifat se poate cumpara si fara stoc (asta e rostul precomenzii). In
  magazin butonul scrie „Precomanda", iar pe pagina produsului apare o banda
  care spune ca marfa vine mai tarziu;
- clientul e anuntat pe tot drumul, nu doar pe fisa produsului: eticheta
  „⏳ Precomanda" pe randul din cos si din rezumatul de checkout, o caseta
  galbena deasupra butonului de plasare (cu numele produselor) si aceeasi
  mentiune pe pagina de confirmare;
- toate cele trei metode de plata merg normal (card, OP, ramburs): banii se
  incaseaza ca la orice comanda, doar trimiterea in ERP asteapta butonul;
- comanda care contine macar un astfel de produs NU pleaca in ERP la plasare:
  ramane in `Admin -> Comenzi`, in tabul **„Precomenzi"** (`?precomanda=asteptare`).
  ERP-ul ar rezerva altfel stoc inexistent si ar cere o factura pe care n-o poate
  emite nimeni;
- cand marfa a venit, apesi **▶** pe randul comenzii. Pleaca in „Comenzi site"
  si trece in tabul **„Precomenzi eliberate"**; de acolo incolo e o comanda
  obisnuita;
- cate bucati s-au precomandat se numara din comenzi, nu dintr-un contor: o
  comanda anulata elibereaza locul inapoi in plafon;
- verificarea intregului drum:

```bash
php /home/USER/domains/bioscem.ro/public_html/scripts/test-precomanda.php
```

Testul nu trimite nimic in ERP si sterge in urma lui tot ce a creat.

### Plata cu cardul prin Banca Transilvania (BT iPay)

Integrarea urmeaza modulul oficial BT pentru Magento (`btrl/ipay` 100.0.2): aceleasi
adrese (`/payment/rest/*.do`), sume in bani, moneda RON = 946, plata in doua faze
(`registerPreAuth.do`). Clientul plateste pe pagina bancii; acolo, cei cu **STAR Card**
pot alege **3 rate fara dobanda** sau plata (si) cu **puncte STAR** — site-ul nu are
nimic de setat pentru asta, doar anunta optiunea in checkout.

**Pana nu e pornit din admin, site-ul se poarta exact ca inainte.** BT e oprit implicit;
EuPlatesc si Stripe raman cum erau.

Ce se intampla cu banii:

- la comanda, banca doar **blocheaza** suma (autorizare). Comanda devine „platita" pe
  site (ca la EuPlatesc): pleaca emailul de comanda noua si comanda intra in ERP;
- suma se **incaseaza** (deposit) automat cand comanda e **aprobata (facturata) in ERP**
  — inainte de AWB —, din butonul **„Incaseaza"** din fereastra comenzii sau, daca nu s-a
  intamplat pana atunci, **automat in ziua 4** (96 de ore; banca cere incasarea in cel
  mult 5 zile). Suma = cea mai mica dintre cea blocata si totalul de acum al comenzii;
- comanda **anulata / returnata inainte de incasare** isi elibereaza automat suma
  blocata (reverse). **Dupa incasare nu se ramburseaza nimic automat**: in comanda apare
  „Plata incasata – necesita rambursare" si butonul **„Rambursează"** (suma completata,
  se poate micsora, cu confirmare). Partea platita in puncte STAR se intoarce prima;
- plata cu puncte STAR vine de la banca in doua comenzi (puncte + card); starea comenzii
  de card decide (ca in modulul BT), iar o plata cu puncte e intreaga abia cand AMBELE
  parti sunt autorizate (cardul trecut si punctele refuzate / inca in 3-D Secure nu
  inseamna „platita"; ce ramane blocat se elibereaza). Orice operatie atinge intai partea
  in puncte, apoi cardul, ca in modulul BT;
- o plata venita pentru o comanda deja anulata (sau stearsa) nu reinvie comanda: suma
  blocata se elibereaza imediat si magazinul primeste email;
- daca suma blocata a unei comenzi inca active e eliberata (din admin, din portalul BT,
  la expirarea autorizarii sau cat comanda a stat in cos), comanda devine NEPLATITA si e
  marcata cu rosu in lista; **aprobarea ei din ERP e refuzata** (fara „in procesare" si
  fara AWB, ca marfa sa nu plece neplatita si fara ramburs): ERP-ul primeste un raspuns de
  eroare (se vede in jurnalul lui), magazinul primeste email, iar aprobarea se reia singura
  dupa ce comanda e anulata sau — dupa ce clientul a platit pe alt drum (link EuPlatesc
  trimis separat, OP) — marcata din „Actiuni comanda" -> „Platit prin link extern de
  plata" (marcajul rosu dispare odata cu plata);
- „Anuleaza autorizarea" pe o comanda inca activa cere o alegere explicita: **„Anuleaza
  comanda si elibereaza suma" (recomandat** — trece prin anularea obisnuita: ERP anuntat,
  email catre client, puncte intoarse) sau „Doar elibereaza suma" (comanda ramane activa
  si neplatita, marcata in lista);
- orice rambursare si orice eliberare manuala (din comanda sau din unealta de test) trimite
  un email magazinului (cine, ce comanda, cat, rezultatul) si apare in jurnalul de
  activitate. Butoanele le are orice administrator care lucreaza cu comenzile.

Fiecare procesator are bifa lui in `Admin -> Setari plati`. Bifa ascunde doar optiunea
din checkout: platile deja incepute (intoarcerea clientului, notificarile, cronul,
butoanele din comanda) merg mai departe si cu procesatorul oprit. Cand checkout-ul ofera
doua sau mai multe procesatoare de card, fiecare are eticheta lui („Card bancar — Banca
Transilvania", „Card bancar — EuPlatesc", „Card bancar — Stripe"); cu unul singur ramane
„Card bancar", ca pana acum.

**Linkurile de plata pentru diferenta** (`Trimite link de plata pentru diferenta` din
comanda) folosesc Banca Transilvania cand BT e pornit **in productie**, cu datele de acces
si deschis clientilor (fara „Doar pentru administratori"); altfel EuPlatesc, ca pana acum.
Linkurile deja trimise merg mai departe pe procesatorul lor (un link BT trece pe
EuPlatesc doar daca BT nu mai e disponibil). Plata unei diferente prin BT e o comanda
separata la banca, cu numarul linkului (`{comanda}-P{n}`, la reincercari `-R2`...), tot
in doua faze, dar **incasata imediat dupa autorizare** (nu exista o aprobare ERP pentru
ea); daca incasarea imediata nu merge, o reia cronul, cu aceeasi plasa de 96 de ore.
Dupa incasare, suma se adauga la cea incasata pe comanda si comanda se retrimite in ERP —
exact ca la un link platit prin EuPlatesc. In fereastra comenzii, plata diferentei are
panoul ei, cu „Incaseaza" / „Anuleaza autorizarea" / „Rambursează". Fara niciun procesator
pornit, adminul primeste un mesaj clar si poate consemna incasarea altfel.

#### 1. Datele de acces (doar in `.env`, niciodata in admin sau in git)

In fisierul `.env` din radacina site-ului (pe Hostinger: hPanel -> File Manager,
`public_html/.env`; calea exacta e afisata in admin, la „?" de langa „configurat"):

```
BT_IPAY_MODE=test
BT_IPAY_TEST_USER=utilizatorul-de-test
BT_IPAY_TEST_PASS=parola-de-test
BT_IPAY_TEST_CALLBACK_KEY=cheia-callback-de-test
BT_IPAY_LIVE_USER=utilizatorul-de-productie
BT_IPAY_LIVE_PASS=parola-de-productie
BT_IPAY_LIVE_CALLBACK_KEY=cheia-callback-de-productie
```

`BT_IPAY_MODE=test` foloseste platforma de test a bancii (nu se iau bani), `live` pe cea
reala. Modificarea se aplica imediat. Adminul arata doar „configurat / lipseste" pentru
fiecare valoare, niciodata valoarea. **In modul test, BT nu apare deloc in checkout** (nici
pentru administratori): testele se fac doar cu „Plata de test 1 leu", iar o plata din
modul test nu se socoteste niciodata pe o comanda reala (nu o face „platita" si nu ajunge
in ERP ca platita). `BT_IPAY_BASE_URL_OVERRIDE` exista doar pentru teste locale (un
server care imita banca) si ramane gol pe site-ul live; daca e completat, adminul arata un
avertisment rosu. Cheia de callback e optionala: fara ea, confirmarea vine la
intoarcerea clientului si prin cron.

#### 2. Tab-ul `Admin -> Setari plati -> Banca Transilvania`

- starea datelor din `.env` (+ „?" cu explicatia pas cu pas) si „Testeaza conexiunea"
  (intreaba banca de o comanda inexistenta: „comanda inexistenta" = datele sunt bune);
- bifa „Accepta plata ... in checkout", bifa „Doar pentru administratorii generali"
  (doar in productie), incasarea la aprobarea din ERP, pragurile (72 h reamintire, 96 h
  incasare automata, 60 min expirare) si adresele de email pentru avertismente;
- adresa de **callback** de dat bancii: `https://bioscem.ro/webhook/bt-ipay` (adresa de
  intoarcere `https://bioscem.ro/checkout/bt/retur` pleaca automat cu fiecare plata);
- linia de cron, cu calea reala, ora ultimei rulari INCHEIATE si rezultatul ei (erori,
  apeluri la banca);
- **plata de test de 1 leu** si jurnalul ultimelor apeluri catre banca (fara parole si
  fara date de card).

#### 3. Cron (obligatoriu), la 15 minute

```
*/15 * * * * php /home/USER/domains/bioscem.ro/public_html/scripts/bt-ipay-sync.php >/dev/null 2>&1
```

Linia exacta, cu calea reala, e afisata pe tab-ul BT. La fiecare trecere: verifica la
banca platile neterminate si le expira dupa 60 de minute (comanda devine esuata),
elibereaza suma blocata pentru comenzile anulate / returnate / sterse, platile in plus,
platile din modul test ajunse pe comenzi reale, diferentele cu link nevalabil (si platile
de test uitate, dupa 30 de minute), reincearca incasarile esuate cu suma ceruta atunci
(de admin sau la aprobare), trimite email la 72 de ore cu platile inca neincasate si le
incaseaza singur la 96 de ore (inclusiv precomenzile si diferentele), cu email — o singura
data pe plata, nu la fiecare rulare. Rularile nu se suprapun (lacat MySQL), fiecare plata
e atinsa cel mult o data pe rulare, o rulare face cel mult 600 de apeluri catre banca (o
cerere web, cel mult 60), iar „bataia de inima" si rezultatul se scriu la SFARSITUL
rularii: o rulare care moare pe drum se vede in admin ca „nu a mai rulat". Scriptul merge
doar din linia de comanda.

#### 4. Testul de 1 leu (in productie)

1. completeaza in `.env` datele de productie si `BT_IPAY_MODE=live`, apoi
   „Testeaza conexiunea";
2. adauga linia de cron;
3. pe tab-ul BT apasa **„Plata de test 1 leu"**: esti dus pe pagina bancii, platesti cu
   un card real, revii in admin si alegi **„Anuleaza (reverse)"** sau **„Incaseaza, apoi
   ramburseaza"**. Plata de test nu tine de nicio comanda, nu trimite emailuri si nu
   ajunge in ERP; neatinsa, cronul o anuleaza in 30 de minute;
4. pentru o comanda reala de proba, fara ca clientii sa vada optiunea (tot in modul
   productie): bifeaza „Doar pentru administratorii generali" + „Accepta plata", plaseaza
   comanda din acelasi browser in care esti logat ca administrator general, apoi
   anuleaz-o din admin (suma se elibereaza automat). Comanda
   ajunge in ERP ca orice comanda platita, iar anularea pleaca si acolo. Un produs de 1 leu
   are si transport, deci comanda reala nu iese la 1 leu; autorizarea anulata nu costa
   nimic;
5. abia apoi debifeaza „Doar pentru administratori".

#### 5. Intoarcerea clientului si notificarea bancii

Adresa de intoarcere (`/checkout/bt/retur`) e publica, asa ca raspunde doar browserului
care a pornit plata (numarul platii e tinut in sesiunea lui): oricine altcineva vede o
pagina generica, fara niciun apel la banca si fara nimic despre plata (nici motivul unui
refuz). Pentru client, banca e intrebata cel mult o data la 30 de secunde pe plata, doar
cat plata se mai poate schimba (o plata in eroare sau expirata, doar in primele 2 ore), cu
lacatul asteptat cel mult 2 secunde. Daca banca nu raspunde sau plata e verificata chiar
atunci, clientul vede „Verificam plata" (fara conversii, cu cosul intact), iar confirmarea
vine prin notificare sau cron; daca plata pica pana la urma, comanda devine esuata si
clientul primeste, ca la orice cos neterminat, emailul de cos abandonat. Notificarea
bancii (`/webhook/bt-ipay`, JWT) intreaba banca cel mult o data la 2 secunde pe plata.

#### 6. Tabele noi (create singure, o singura data; versiunea sta in `settings`)

- `bt_ipay_transactions`: o plata (incercare) la banca, cu numarul trimis (`{comanda}`,
  la reincercari `{comanda}-R2`...; diferentele `{comanda}-P{n}`; platile de test
  `TEST-...`), starea, sumele (card si puncte, autorizat / incasat / rambursat), suma
  ceruta la o incasare reincercata si modul (test / live) in care a fost facuta —
  operatiile ulterioare folosesc datele de acces ale aceluiasi mod;
- `bt_ipay_log`: jurnalul apelurilor catre banca, fara parole si fara date de card, sters
  dupa 180 de zile.

Teste: `php scripts/bt-ipay-sync.php` ruleaza o trecere de cron manual. Pentru teste
locale fara banca, `BT_IPAY_BASE_URL_OVERRIDE=http://127.0.0.1:PORT` trimite toate
apelurile la un server care imita API-ul BT.

### Sesiunea si cosul

Cosul sta in sesiunea PHP. Pana in septembrie 2026 sesiunea pornea fara nicio
setare, deci lua ce zicea serverul: de obicei 24 de minute de nemiscare si un
cookie care murea la inchiderea browserului. Pe gazduire comuna era si mai rau —
curatenia altui site de pe aceeasi masina putea matura fisierele noastre mult
mai devreme. De aici reclamatia „am iesit 5 minute si mi s-a golit cosul".

Acum, in `bootstrap.php`:

- fisierele de sesiune stau in `storage/sessions/`, nu in dosarul comun al
  serverului (cu `.htaccess` de refuz scris automat acolo);
- `gc_maxlifetime` si viata cookie-ului: **30 de zile**;
- curatenia o face PHP pe dosarul nostru, cu `gc_probability = 1/200`;
- cookie-ul e `httponly`, `SameSite=Lax` si `secure` cand cererea vine pe HTTPS.

Dosarul e in `.gitignore` si se creeaza singur la prima cerere. Daca nu se poate
scrie in el, programul merge mai departe pe dosarul serverului — nu pica, dar
cosul se goleste iar, deci merita verificate drepturile.

**Administrarea are ceasul ei.** O sesiune de 30 de zile ar tine un admin logat
o luna, asa ca `Auth::check()` deconecteaza administratorul dupa **12 ore de
nemiscare** (`admin_last_seen`). Cosul si contul cumparatorului din aceeasi
sesiune nu sunt atinse.

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

- dupa cate minute pleaca se alege in admin, campul `email_abandoned_after_minutes`
  (implicit 60; pe bioscem e pus pe 180, adica 3 ore);
- **pleaca o singura data** pentru acelasi cos: randul din `cart_abandonments`
  primeste `abandoned_email_sent_at` si nu mai intra in selectie niciodata.
  Inainte, cand sesiunea murea in cateva minute, fiecare vizita facea un rand
  nou si omul putea primi mai multe emailuri pentru acelasi cos; cu sesiunea de
  30 de zile, randul e acelasi;
- adresa se ia din formularul de finalizare, iar daca omul e logat si n-a ajuns
  pana acolo, din fisa contului lui;
- inregistrarea se face din bataia de inima trimisa de pagina cosului si de cea
  de finalizare (`POST /api/cart/heartbeat`). Cine pune produse in cos si nu
  deschide niciodata pagina cosului nu intra in socoteala.

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
