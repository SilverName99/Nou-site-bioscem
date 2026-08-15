/**
 * Plafonarea cantității la stocul disponibil.
 *
 * Serverul pune pe câmpul de cantitate `data-stoc-maxim="5"`. De aici încolo
 * totul e local: butonul „+" se oprește la limită, iar o valoare tastată peste
 * limită e coborâtă la maxim, cu o notă vizibilă deasupra controlului.
 *
 * Câmpurile fără `data-stoc-maxim` (produse fără stoc urmărit în ERP) rămân
 * exact cum erau — nu limităm pe baza unei cifre în care nu avem încredere.
 */
(function () {
    'use strict';

    var TEXT_NOTA = 'Cantitate maximă în stoc';

    function limitaCampului(input) {
        var brut = input.getAttribute('data-stoc-maxim');
        if (brut === null || brut === '') {
            return null;
        }
        var limita = parseInt(brut, 10);
        return Number.isFinite(limita) && limita > 0 ? limita : null;
    }

    /** Nota se caută lângă control; dacă lipsește, o creăm la prima nevoie. */
    function notaPentru(input) {
        var container = input.closest('.qty-stepper') || input.parentElement;
        if (!container) {
            return null;
        }
        var radacina = container.parentElement || container;
        var existenta = radacina.querySelector('[data-stoc-note]');
        if (existenta) {
            return existenta;
        }
        var nota = document.createElement('p');
        nota.className = 'qty-stoc-note';
        nota.setAttribute('data-stoc-note', '');
        nota.textContent = TEXT_NOTA;
        nota.hidden = true;
        radacina.insertBefore(nota, container);
        return nota;
    }

    function aratăNota(input, activa) {
        var nota = notaPentru(input);
        if (!nota) {
            return;
        }
        nota.textContent = TEXT_NOTA;
        nota.hidden = !activa;
        nota.classList.toggle('is-visible', !!activa);
    }

    /**
     * Coboară valoarea la limită dacă e depășită.
     * @returns {boolean} true dacă valoarea a fost schimbată.
     */
    function plafoneaza(input) {
        var limita = limitaCampului(input);
        if (limita === null) {
            return false;
        }
        var valoare = parseInt(input.value, 10);
        if (!Number.isFinite(valoare)) {
            return false;
        }
        if (valoare > limita) {
            input.value = String(limita);
            aratăNota(input, true);
            return true;
        }
        // Nota rămâne vizibilă cât timp stăm fix pe maxim.
        aratăNota(input, valoare === limita);
        return false;
    }

    function esteCampCantitate(el) {
        return el instanceof HTMLInputElement
            && el.type === 'number'
            && el.hasAttribute('data-stoc-maxim');
    }

    // Tastare / lipire / schimbare: plafonăm după ce câmpul are valoarea nouă.
    ['input', 'change'].forEach(function (eveniment) {
        document.addEventListener(eveniment, function (e) {
            if (esteCampCantitate(e.target)) {
                plafoneaza(e.target);
            }
        });
    });

    // Butonul „+": îl lăsăm să incrementeze, apoi tăiem la limită. Ordinea
    // contează — alte scripturi ascultă „input" pe câmp și trebuie să vadă
    // valoarea deja plafonată.
    document.addEventListener('click', function (e) {
        var tinta = e.target;
        if (!(tinta instanceof HTMLElement)) {
            return;
        }
        var buton = tinta.closest('[data-role="qty-plus"], [data-qty-action="increase"]');
        if (!buton) {
            return;
        }
        var container = buton.closest('.qty-stepper') || buton.parentElement;
        if (!container) {
            return;
        }
        var input = container.querySelector('input[type="number"]');
        if (!esteCampCantitate(input)) {
            return;
        }
        // Rulăm după handlerul care incrementează.
        window.setTimeout(function () {
            if (plafoneaza(input)) {
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }, 0);
    }, true);

    // La încărcare: dacă un coș vechi are deja mai mult decât stocul curent.
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('input[type="number"][data-stoc-maxim]').forEach(function (input) {
            plafoneaza(input);
        });
    });
})();
