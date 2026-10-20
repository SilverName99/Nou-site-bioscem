/*
 * Fereastra „avertisment produs” (ex. ATENȚIE – PRODUS REFRIGERAT), deschisă
 * după ce clientul pune în coș un produs cu avertisment. Produsul e deja în coș:
 * fereastra doar informează și se închide cu „Am înțeles”, × sau Esc.
 *
 * Conținutul vine din răspunsul JSON al adăugării (coșul plutitor, butonul de
 * pe pagina produsului) sau, pe drumul fără JS-ul coșului, din blocul
 * #avertisment-produs-date al paginii /cos. Scriptul se încarcă doar atunci.
 *
 * Aspectul e cel al popup-ului de anunț din layout („Bine ați venit…”).
 * Textul se pune numai cu textContent: ce scrie adminul nu devine HTML.
 */
(() => {
    if (window.bvAvertismentProdus) {
        return;
    }

    let deschisa = null;

    const curata = (lista) => {
        const vazute = new Set();
        return (Array.isArray(lista) ? lista : [lista]).filter((a) => {
            if (!a || typeof a !== "object" || !Array.isArray(a.paragrafe) || a.paragrafe.length === 0) {
                return false;
            }
            const cheie = String(a.titlu || "") + "\u0000" + a.paragrafe.join("\n\n");
            if (vazute.has(cheie)) {
                return false;
            }
            vazute.add(cheie);
            return true;
        });
    };

    const element = (tag, stil, text) => {
        const nod = document.createElement(tag);
        if (stil) {
            nod.setAttribute("style", stil);
        }
        if (text !== undefined) {
            nod.textContent = text;
        }
        return nod;
    };

    /** Un paragraf: rândurile simple din el rămân rânduri (<br>), tot ca text. */
    const paragraf = (text) => {
        const p = element("p", "margin:0 0 12px;");
        String(text).split("\n").forEach((rand, i) => {
            if (i > 0) {
                p.appendChild(document.createElement("br"));
            }
            p.appendChild(document.createTextNode(rand));
        });
        return p;
    };

    const inchide = () => {
        if (!deschisa) {
            return;
        }
        const { fundal, revino, laTasta } = deschisa;
        deschisa = null;
        document.removeEventListener("keydown", laTasta, true);
        fundal.remove();
        if (!(revino instanceof HTMLElement) || !document.contains(revino)) {
            return;
        }
        const focus = () => {
            try {
                revino.focus({ preventScroll: true });
            } catch (_) {
                revino.focus();
            }
        };
        focus();
        // Butonul de coș rămâne dezactivat puțin după adăugare; închisă repede,
        // fereastra îi dă focusul când revine (dacă între timp n-a luat altceva).
        let incercari = 0;
        const reia = () => {
            if (document.activeElement === revino || !document.contains(revino) || incercari++ > 20) {
                return;
            }
            if (document.activeElement && document.activeElement !== document.body) {
                return;
            }
            if (!revino.disabled) {
                focus();
                return;
            }
            window.setTimeout(reia, 50);
        };
        if (document.activeElement !== revino) {
            window.setTimeout(reia, 50);
        }
    };

    // „inapoi” = butonul apăsat: în timpul cererii e dezactivat și pierde focusul,
    // așa că nu ne putem baza pe elementul activ.
    const arata = (lista, inapoi) => {
        const avertismente = curata(lista);
        if (avertismente.length === 0) {
            return;
        }
        const revino = deschisa
            ? deschisa.revino
            : (inapoi instanceof HTMLElement ? inapoi : document.activeElement);
        if (deschisa) {
            const deInchis = deschisa;
            deschisa = null;
            document.removeEventListener("keydown", deInchis.laTasta, true);
            deInchis.fundal.remove();
        }

        const fundal = element("div",
            "position:fixed;inset:0;z-index:100000;display:flex;align-items:center;justify-content:center;padding:20px;background:rgba(15,23,42,.55);");
        fundal.setAttribute("data-avertisment-produs-fereastra", "1");
        const cutie = element("div",
            "position:relative;max-width:560px;width:100%;max-height:85vh;overflow-y:auto;overscroll-behavior:contain;background:#ffffff;border-radius:16px;box-shadow:0 24px 64px rgba(15,23,42,.35);padding:28px 26px 24px;outline:none;");
        cutie.setAttribute("role", "dialog");
        cutie.setAttribute("aria-modal", "true");
        cutie.setAttribute("tabindex", "-1");

        const x = element("button",
            "position:absolute;top:12px;right:12px;width:34px;height:34px;border:none;border-radius:50%;background:#f1f5f9;color:#334155;font-size:18px;line-height:1;cursor:pointer;",
            "×");
        x.type = "button";
        x.setAttribute("aria-label", "Închide");
        x.addEventListener("click", inchide);
        cutie.appendChild(x);

        const corp = element("div", "color:#334155;font-size:15px;line-height:1.6;overflow-wrap:anywhere;");
        corp.id = "avertisment-produs-text";
        let primulTitlu = null;
        avertismente.forEach((a, i) => {
            const titlu = String(a.titlu || "").trim();
            if (titlu !== "") {
                const h = element("h2",
                    "margin:" + (i === 0 ? "0" : "18px") + " 0 14px;padding-right:" + (i === 0 ? "36px" : "0") + ";font-size:22px;line-height:1.25;color:#0f172a;",
                    titlu);
                if (primulTitlu === null) {
                    h.id = "avertisment-produs-titlu";
                    primulTitlu = h;
                }
                corp.appendChild(h);
            }
            a.paragrafe.forEach((text) => corp.appendChild(paragraf(text)));
        });
        // Fără titlu deasupra, primul paragraf ține locul titlului lângă ×.
        if (corp.firstElementChild && corp.firstElementChild.tagName === "P") {
            corp.firstElementChild.style.paddingRight = "36px";
        }
        cutie.appendChild(corp);
        if (primulTitlu) {
            cutie.setAttribute("aria-labelledby", primulTitlu.id);
        } else {
            cutie.setAttribute("aria-label", "Avertisment produs");
        }
        cutie.setAttribute("aria-describedby", corp.id);

        const jos = element("div", "margin-top:18px;text-align:center;");
        const ok = element("button",
            "display:inline-block;min-width:180px;padding:12px 22px;border:none;border-radius:12px;background:#2f8d5b;color:#ffffff;font-size:15px;font-weight:700;cursor:pointer;",
            "Am înțeles");
        ok.type = "button";
        ok.addEventListener("click", inchide);
        jos.appendChild(ok);
        cutie.appendChild(jos);
        fundal.appendChild(cutie);

        // Esc închide; Tab rămâne în fereastră (×, „Am înțeles”).
        const laTasta = (e) => {
            if (e.key === "Escape") {
                e.preventDefault();
                e.stopPropagation();
                inchide();
                return;
            }
            if (e.key !== "Tab") {
                return;
            }
            const butoane = [x, ok];
            const index = butoane.indexOf(document.activeElement);
            e.preventDefault();
            const urmator = e.shiftKey
                ? butoane[(index <= 0 ? butoane.length : index) - 1]
                : butoane[(index + 1) % butoane.length];
            urmator.focus();
        };
        document.addEventListener("keydown", laTasta, true);

        document.body.appendChild(fundal);
        deschisa = { fundal, revino, laTasta };
        // Focus pe fereastră, nu pe buton: un text lung se citește de sus.
        try {
            cutie.focus({ preventScroll: true });
        } catch (_) {
            cutie.focus();
        }
    };

    window.bvAvertismentProdus = { arata, inchide };

    // Pagina /cos după adăugarea pe drumul clasic.
    const date = document.getElementById("avertisment-produs-date");
    if (date) {
        try {
            arata(JSON.parse(date.textContent || "[]"));
        } catch (_) {
            // Date stricate: fără fereastră, produsul e oricum în coș.
        }
    }
})();
