/*
 * Pașii newsletterului din builder: Conținut → Destinatari → Verificare →
 * Trimite / Programează.
 *
 * Builderul (views/admin/emails/builder.php) dă `window.ebBuilder`: blocurile
 * de pe ecran, sincronizarea formularului și starea „modificat”. Aici:
 *  - mutarea între pași (pasul curent stă în adresă, ?pas=N);
 *  - salvarea ÎNAINTE de orice trimitere: înainte, „Trimite acum” pornea o
 *    salvare fără s-o aștepte, așa că putea pleca varianta veche (alt subiect,
 *    altă listă). Acum trimiterea pornește doar după ce salvarea a reușit;
 *  - previzualizarea pe lățime de calculator și de telefon;
 *  - lista de verificare (de pe server) și blocarea trimiterii la erori.
 */
(function () {
  'use strict';

  const script = document.currentScript;
  const api = window.ebBuilder;
  if (!script || !api) return;

  const idCampanie = Number(script.dataset.id || 0);
  const poateModifica = script.dataset.poateModifica === '1';
  const corpBuilder = document.querySelector('#eb-wrap .eb-body');
  const cheiePas = 'nlp-pas-' + idCampanie;
  let pasCurent = Number(script.dataset.pas || 1);
  let ultimaVerificare = null;
  let latimeEcran = 'calculator';

  const $ = (sel) => document.querySelector(sel);
  const $$ = (sel) => Array.from(document.querySelectorAll(sel));

  function listeAlese() {
    return $$('.nlp-lista:checked').map((x) => Number(x.value));
  }
  function subiect() {
    return ($('#eb-subject-input') || {}).value || '';
  }
  function corpCerere(extra) {
    const p = new URLSearchParams();
    p.set('blocks_json', JSON.stringify(api.blocuri()));
    p.set('subject', subiect());
    Object.keys(extra || {}).forEach((k) => p.set(k, extra[k]));
    return p;
  }

  /* ── Salvarea (aceeași rută ca butonul „Salvează”, cu răspuns JSON) ── */
  async function salveaza() {
    if (!poateModifica) return true;
    api.sincronizeaza();
    const intrareListe = $('#eb-list-ids-input');
    if (intrareListe) intrareListe.value = listeAlese().join(',');
    const formular = $('#eb-form');
    if (!formular) return true;
    const date = new URLSearchParams(new FormData(formular));
    date.set('ajax', '1');
    try {
      const r = await fetch('/admin/emails/builder', { method: 'POST', body: date, credentials: 'same-origin' });
      const d = await r.json().catch(() => null);
      if (r.ok && d && d.ok) {
        api.marcheazaSalvat();
        return true;
      }
      alert((d && d.message) || 'Nu am putut salva newsletterul. Încearcă din nou.');
    } catch (e) {
      alert('Nu am putut salva newsletterul (conexiunea s-a întrerupt). Încearcă din nou.');
    }
    return false;
  }

  /* ── Mutarea între pași ── */
  async function mergiLa(pas) {
    pas = Math.max(1, Math.min(4, Number(pas) || 1));
    if (poateModifica && api.esteModificat() && pas !== pasCurent) {
      if (!(await salveaza())) return;
    }
    pasCurent = pas;
    if (corpBuilder) corpBuilder.style.display = pas === 1 ? '' : 'none';
    const continua = $('#nlp-continua-1');
    if (continua) continua.hidden = pas !== 1;
    // Butoanele de editare (Undo, Editare/Desktop/Mobil) țin doar de pasul 1.
    $$('.eb-history-btns, .eb-preview-tabs').forEach((x) => { x.style.display = pas === 1 ? '' : 'none'; });
    $$('[data-pas-panou]').forEach((panou) => { panou.hidden = Number(panou.dataset.pasPanou) !== pas; });
    $$('#eb-wizard-bar .adm-pasi__pas').forEach((b) => {
      const n = Number(b.dataset.pas);
      b.classList.toggle('este-curent', n === pas);
      b.classList.toggle('este-gata', n < pas);
      if (n === pas) b.setAttribute('aria-current', 'step'); else b.removeAttribute('aria-current');
    });
    try {
      const adresa = new URL(window.location.href);
      adresa.searchParams.set('pas', String(pas));
      adresa.searchParams.delete('nou');
      history.replaceState(null, '', adresa.toString());
    } catch (e) { /* fără adresă actualizată: nu e grav */ }

    if (pas === 2) verifica(false);
    if (pas === 3) { previzualizeaza(); verifica(true); }
    if (pas === 4) { completeazaRezumat(); verifica(false); }
    const panou = document.querySelector('[data-pas-panou="' + pas + '"]');
    if (panou) panou.scrollTop = 0;
  }

  /* ── Previzualizarea ── */
  async function previzualizeaza() {
    const iframe = $('#nlp-iframe');
    const ecran = $('#nlp-ecran');
    if (!iframe || !ecran) return;
    const afisat = $('#nlp-subiect-afisat');
    if (afisat) afisat.textContent = subiect() || '(fără subiect)';
    ecran.classList.toggle('este-telefon', latimeEcran === 'telefon');
    $$('.nlp-comutator [data-ecran]').forEach((b) => b.setAttribute('aria-pressed', b.dataset.ecran === latimeEcran ? 'true' : 'false'));
    try {
      const r = await fetch('/admin/emails/builder/preview', { method: 'POST', body: corpCerere(), credentials: 'same-origin' });
      const d = await r.json();
      if (d && d.ok) {
        iframe.srcdoc = d.html;
        iframe.onload = () => {
          try {
            const h = iframe.contentDocument.documentElement.scrollHeight;
            if (h) iframe.style.height = (h + 24) + 'px';
          } catch (e) { /* înălțimea rămâne cea implicită */ }
        };
      }
    } catch (e) {
      iframe.srcdoc = '<p style="font-family:sans-serif;padding:20px;color:#b91c1c;">Nu am putut genera previzualizarea. Reîncearcă.</p>';
    }
  }

  /* ── Lista de verificare ── */
  const SEMNE = { ok: '✓', avertisment: '!', eroare: '✕', info: 'i' };
  const ETICHETE = { ok: 'În regulă', avertisment: 'De verificat', eroare: 'De reparat', info: 'Informație' };

  function deseneazaVerificari(rezultat) {
    const lista = $('#nlp-verificari');
    if (!lista) return;
    lista.innerHTML = '';
    rezultat.puncte.forEach((p) => {
      const li = document.createElement('li');
      li.className = 'nlp-verificare nlp-verificare--' + p.stare;
      const semn = document.createElement('span');
      semn.className = 'nlp-verificare__semn';
      semn.textContent = SEMNE[p.stare] || '?';
      semn.setAttribute('aria-label', ETICHETE[p.stare] || '');
      semn.setAttribute('role', 'img');
      const corp = document.createElement('div');
      const titlu = document.createElement('span');
      titlu.className = 'nlp-verificare__titlu';
      titlu.textContent = p.titlu;
      corp.appendChild(titlu);
      if (p.text) {
        const text = document.createElement('p');
        text.className = 'nlp-verificare__text';
        text.textContent = p.text;
        corp.appendChild(text);
      }
      if (p.detalii && p.detalii.length) {
        const ul = document.createElement('ul');
        p.detalii.forEach((d) => { const x = document.createElement('li'); x.textContent = d; ul.appendChild(x); });
        corp.appendChild(ul);
      }
      li.appendChild(semn);
      li.appendChild(corp);
      lista.appendChild(li);
    });
  }

  // „1 abonat”, „15 abonați”, „43 de abonați” (de la 20 în sus, cu „de”).
  function textAbonati(n) {
    if (n === 1) return '1 abonat';
    return n.toLocaleString('ro-RO') + (n >= 20 && (n % 100 === 0 || n % 100 >= 20) ? ' de abonați' : ' abonați');
  }

  function aplicaRezultat(rezultat) {
    ultimaVerificare = rezultat;
    const n = Number(rezultat.destinatari || 0);
    const textNumar = textAbonati(n);
    const total = $('#nlp-total-destinatari');
    if (total) total.textContent = textNumar;
    const rezDest = $('#nlp-rezumat-destinatari');
    if (rezDest) rezDest.textContent = textNumar;
    const butonAcum = $('#nlp-form-trimite [data-nlp-trimite]');
    if (butonAcum) butonAcum.textContent = 'Trimite acum către ' + textNumar;
    const blocat = !!rezultat.blocheaza;
    $$('[data-nlp-trimite]').forEach((b) => { b.disabled = blocat; });
    const banner = $('#nlp-blocat');
    if (banner) banner.hidden = !blocat || pasCurent !== 4 || !poateModifica;
  }

  async function verifica(peInternet) {
    const lista = $('#nlp-verificari');
    if (lista && pasCurent === 3) lista.innerHTML = '<li class="nlp-verificare"><span></span><span>Se verifică…</span></li>';
    try {
      const r = await fetch('/admin/emails/builder/verificare', {
        method: 'POST',
        body: corpCerere({ list_ids: listeAlese().join(','), internet: peInternet ? '1' : '0' }),
        credentials: 'same-origin',
      });
      const d = await r.json();
      if (!d || !d.ok) throw new Error('raspuns');
      // Verificarea fără internet nu înlocuiește lista arătată la pasul 3.
      if (peInternet || pasCurent !== 3) deseneazaVerificari(d);
      aplicaRezultat(d);
    } catch (e) {
      if (lista) lista.innerHTML = '<li class="nlp-verificare nlp-verificare--avertisment"><span class="nlp-verificare__semn">!</span><span>Nu am putut face verificarea acum. Apasă „Verifică din nou”.</span></li>';
    }
  }

  function completeazaRezumat() {
    const s = $('#nlp-rezumat-subiect');
    if (s) s.textContent = subiect() || '(fără subiect)';
    const l = $('#nlp-rezumat-liste');
    if (l) {
      const nume = $$('.nlp-lista:checked').map((x) => x.closest('label').querySelector('span').textContent.trim());
      l.textContent = nume.length ? nume.join(', ') : '(nicio listă)';
    }
  }

  /* ── Formularele care trimit: salvăm întâi, apoi trimitem ── */
  $$('form[data-nlp-salveaza="1"]').forEach((formular) => {
    formular.addEventListener('submit', async (e) => {
      e.preventDefault();
      const buton = formular.querySelector('button[type="submit"]');
      const esteTest = formular.id === 'nlp-form-test';
      if (!esteTest && ultimaVerificare && ultimaVerificare.blocheaza) {
        mergiLa(3);
        return;
      }
      if (formular.dataset.nlpConfirma === '1') {
        const n = ultimaVerificare ? textAbonati(Number(ultimaVerificare.destinatari || 0)) : 'abonații aleși';
        if (!confirm('Trimiți acum newsletterul „' + subiect() + '” către ' + n + '?\n\nDupă ce pleacă nu se mai poate opri sau modifica.')) return;
      }
      if (formular.id === 'nlp-form-programeaza') {
        const camp = formular.querySelector('input[type="datetime-local"]');
        if (camp && camp.value) {
          const cand = new Date(camp.value);
          if (!confirm('Programezi newsletterul pentru ' + cand.toLocaleString('ro-RO', { dateStyle: 'full', timeStyle: 'short' }) + '?')) return;
        }
      }
      const textInitial = buton ? buton.textContent : '';
      if (buton) { buton.disabled = true; buton.textContent = 'Se salvează…'; }
      if (!(await salveaza())) {
        if (buton) { buton.disabled = false; buton.textContent = textInitial; }
        return;
      }
      if (buton) buton.textContent = esteTest ? 'Se trimite testul…' : 'Se trimite…';
      try { sessionStorage.setItem(cheiePas, String(pasCurent)); } catch (err) { /* fără memorie: revine la pasul 1 */ }
      api.marcheazaSalvat();
      formular.submit();
    });
  });

  /* ── Legături ── */
  $$('#eb-wizard-bar .adm-pasi__pas').forEach((b) => b.addEventListener('click', () => mergiLa(b.dataset.pas)));
  document.addEventListener('click', (e) => {
    const t = e.target.closest('[data-mergi-la]');
    if (t) { e.preventDefault(); mergiLa(t.dataset.mergiLa); }
    const ecran = e.target.closest('.nlp-comutator [data-ecran]');
    if (ecran) { latimeEcran = ecran.dataset.ecran; previzualizeaza(); }
  });
  $$('.nlp-lista').forEach((c) => c.addEventListener('change', () => {
    c.closest('tr').classList.toggle('este-ales', c.checked);
    const intrareListe = $('#eb-list-ids-input');
    if (intrareListe) intrareListe.value = listeAlese().join(',');
    api.marcheazaModificat();
    verifica(false);
  }));
  const dinNou = $('#nlp-verifica-din-nou');
  if (dinNou) dinNou.addEventListener('click', () => { previzualizeaza(); verifica(true); });
  const laLista = $('#nlp-la-lista');
  if (laLista) laLista.addEventListener('click', async (e) => {
    if (poateModifica && api.esteModificat()) {
      e.preventDefault();
      if (await salveaza()) window.location.href = laLista.href;
    }
  });
  if (!poateModifica) {
    const salvare = $('#btn-save');
    if (salvare) salvare.hidden = true;
  }

  /* ── Pornirea: pasul din adresă sau cel de dinaintea unei trimiteri ── */
  try {
    const salvat = sessionStorage.getItem(cheiePas);
    if (salvat && !new URL(window.location.href).searchParams.has('pas')) pasCurent = Number(salvat);
    sessionStorage.removeItem(cheiePas);
  } catch (e) { /* fără memorie: pasul din pagină */ }
  mergiLa(pasCurent);
})();
