# Newsletter: piesele noi și componentele din Faza D

Pașii newsletterului din builder (Conținut → Destinatari → Verificare → Trimite / Programează)
sunt făcuți deja cu vocabularul de componente din planul Fazei D („B — Compact, pentru lucru
intens”, WP-31). În Faza D se schimbă doar **aspectul** (variabilele și regulile CSS), nu
paginile. Tabelul de mai jos spune ce piesă corespunde cărei componente.

## Componente (`views/admin/componente/`, stil în `public/assets/css/admin-componente.css`)

Fiecare componentă e un fișier mic, chemat cu `admin_componenta('<nume>', [...])`
(`_incarca.php`). Primește doar ce i se dă explicit; clasele sunt `adm-…`.

| Piesă nouă | Fișier | Componenta WP-31 | Ce rămâne de făcut în Faza D |
|---|---|---|---|
| Antet de pagină cu „?” (ajutor) | `antet-pagina.php` | `antet-pagina` + ajutorul din spatele lui „?” | doar stilul; ajutorul se deschide cu `componente.js` (clic, Esc) |
| Bara de pași | `bara-pasi.php` | **nouă** (WP-31 nu o are în listă); propunere: se adaugă ca `bara-pasi` | doar stilul |
| Mesaj (banner) info / reușită / avertisment / eroare | `mesaj.php` | `mesaj` | doar stilul |
| Panou lateral (rezumat, „Trimite un test”, lista de verificare) | `panou-lateral.php` | `panou-lateral` | azi stă în coloana din dreapta; în Faza D poate deveni panoul care se deschide din dreapta, fără schimbări în pași |
| Tabelul listelor (cu bife) | `tabel.php` | `tabel` | sortare / antet fix nu sunt necesare aici |
| Stare goală („Nu ai încă nicio listă…”) | `stare-goala.php` | **nouă**; propunere: se adaugă în WP-31 ca `stare-goala` | doar stilul |
| Pastila de stare (Ciornă, Programat, Se trimite, Oprit, Trimis) | clasa `adm-stare` în CSS | parte din `tabel` / `tab-uri-cu-numar` | doar stilul |
| Butoane | clasele existente `btn`, `btn-secondary`, `btn-danger` | butoanele shell-ului (WP-30) | se restilizează odată cu `app.css` → `admin.css` |

Toate culorile, razele și inelul de focus sunt variabile `--adm-…` în
`admin-componente.css`. Când WP-30 aduce `admin.css` cu tokenurile lui, fie se leagă
variabilele `--adm-…` de tokenurile noi, fie regulile `adm-…` se mută în `admin.css`.

## Pașii newsletterului (`views/admin/emails/newsletter/`, așezarea în `admin-newsletter.css`)

| Fișier | Ce conține | Componente folosite |
|---|---|---|
| `pas-destinatari.php` | pasul 2: listele cu bife, numărul exact de abonați | antet-pagina, tabel, stare-goala |
| `pas-verificare.php` | pasul 3: previzualizare calculator / telefon, lista de verificare, „Trimite un test” | antet-pagina, panou-lateral |
| `pas-trimitere.php` | pasul 4: rezumat, „Trimite acum”, „Programează”, starea trimiterii (programat, se trimite, oprit, trimis) | antet-pagina, mesaj, panou-lateral |

Pasul 1 („Conținut”) e builderul existent (`views/admin/emails/builder.php`, clasele `eb-…`);
el se reface în WP-35 (grupul „emails + builder”). Bara de pași și subiectul (sus în paleta din
stânga) sunt deja pe componente.

Clasele `nlp-…` din `admin-newsletter.css` țin doar de așezare (coloane, lățimea
previzualizării, lista de verificare). Ele folosesc aceleași variabile `--adm-…`.

## JavaScript

| Fișier | Ce face | WP |
|---|---|---|
| `public/assets/js/admin/componente.js` | ajutorul „?” (deschide / închide, Esc) | WP-31 |
| `public/assets/js/admin/newsletter-pasi.js` | mutarea între pași (pasul stă în adresă, `?pas=N`, deci „Înapoi” și reîncărcarea păstrează pasul), salvarea înainte de orice trimitere, previzualizarea, lista de verificare | WP-35 |

Builderul expune `window.ebBuilder` (blocurile, sincronizarea, starea „modificat”);
`newsletter-pasi.js` nu umblă în interiorul builderului.

## Pentru contractul de admin (WP-29) și portarea în CMS

Adrese, nume de câmpuri și atribute noi, de declarat în `$contractAdminPorturi`:

- `POST /admin/emails/builder/verificare` (lista de verificare, JSON; nu trimite nimic);
- `POST /newsletter/unsubscribe/{token}` (dezabonarea „cu un clic” din clientul de email, RFC 8058);
- secțiunile noi `newsletter-campaign-unschedule` (anulează programarea) și
  `newsletter-campaign-pause` (oprește o trimitere în curs) pe `POST /admin/emails`;
- parametrul `ajax=1` la `POST /admin/emails/builder` (răspuns JSON, 409 dacă newsletterul a plecat);
- parametrul `pas` (1–4) și `nou=1` la `GET /admin/emails/builder?type=campaign`;
- atributele citite de JS: `data-pas`, `data-pas-panou`, `data-mergi-la`, `data-nlp-salveaza`,
  `data-nlp-confirma`, `data-nlp-trimite`, `data-ecran`, `.nlp-lista[data-activi]`;
- setarea `newsletter_cron_ultima_rulare` (scrisă de cron, citită de lista de verificare);
- coloanele `newsletter_campaign_sends.attempts`, `newsletter_campaigns.last_error` și starea
  nouă `paused` (Oprit) a unei campanii; starea `pending` a unui rând din evidența trimiterilor.

Plimbarea cu tastatura (WP-31): pașii sunt butoane, fiecare element are inel de focus
(`--adm-focus`), Esc închide ajutorul. Dacă newsletterul devine plugin în CMS, textele lui
(inclusiv ajutorul) trebuie să dispară când pluginul e oprit (test-pluginuri C).
