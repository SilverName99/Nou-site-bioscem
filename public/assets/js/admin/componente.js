/*
 * Componentele de admin (WP-31): ajutorul din spatele lui „?” de lângă titlu.
 * Un clic îl deschide sau îl închide; Esc îl închide.
 */
(function () {
  'use strict';
  function comuta(buton, deschis) {
    const tinta = document.getElementById(buton.getAttribute('aria-controls') || '');
    if (!tinta) return;
    tinta.hidden = !deschis;
    buton.setAttribute('aria-expanded', deschis ? 'true' : 'false');
  }
  document.addEventListener('click', function (e) {
    const buton = e.target.closest('.adm-ajutor__buton');
    if (!buton) return;
    comuta(buton, buton.getAttribute('aria-expanded') !== 'true');
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    document.querySelectorAll('.adm-ajutor__buton[aria-expanded="true"]').forEach(function (b) { comuta(b, false); });
  });
})();
