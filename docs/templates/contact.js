/* =========================================================
   BIOSCEM - FORMULAR DE CONTACT
   Endpoint-ul /contact/send primeste JSON si raspunde tot cu JSON.
   ========================================================= */

(function () {
  "use strict";

  function init() {
    var form = document.querySelector("[data-bs-contact-form]");
    if (!form) {
      return;
    }

    var status = form.querySelector("[data-bs-contact-status]");
    var submit = form.querySelector("[data-bs-contact-submit]");

    function setStatus(message, type) {
      if (!status) {
        return;
      }
      status.textContent = message;
      status.classList.remove("is-success", "is-error");
      if (type) {
        status.classList.add(type === "success" ? "is-success" : "is-error");
      }
    }

    function markInvalid(field, invalid) {
      if (field) {
        field.classList.toggle("is-invalid", !!invalid);
      }
    }

    form.addEventListener("submit", function (event) {
      event.preventDefault();

      var fields = {
        name: form.querySelector('[name="name"]'),
        email: form.querySelector('[name="email"]'),
        phone: form.querySelector('[name="phone"]'),
        subject: form.querySelector('[name="subject"]'),
        message: form.querySelector('[name="message"]')
      };

      var payload = {};
      Object.keys(fields).forEach(function (key) {
        payload[key] = fields[key] ? fields[key].value.trim() : "";
      });

      // Aceleasi verificari ca pe server, ca sa nu trimitem degeaba.
      var emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(payload.email);
      markInvalid(fields.name, payload.name === "");
      markInvalid(fields.email, !emailOk);
      markInvalid(fields.subject, payload.subject === "");
      markInvalid(fields.message, payload.message === "");

      if (payload.name === "" || payload.subject === "" || payload.message === "" || !emailOk) {
        setStatus("Completeaza numele, un email valid, subiectul si mesajul.", "error");
        return;
      }

      if (submit) {
        submit.disabled = true;
        submit.textContent = "Se trimite...";
      }
      setStatus("", null);

      fetch("/contact/send", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json"
        },
        body: JSON.stringify(payload)
      })
        .then(function (response) {
          return response.json().catch(function () {
            return { ok: false };
          });
        })
        .then(function (data) {
          if (data && data.ok) {
            form.reset();
            setStatus("Mesajul a fost trimis. Iti multumim, revenim cu un raspuns in cel mai scurt timp.", "success");
          } else {
            setStatus(
              (data && data.error) || "Mesajul nu a putut fi trimis. Incearca din nou sau scrie-ne pe email.",
              "error"
            );
          }
        })
        .catch(function () {
          setStatus("Mesajul nu a putut fi trimis. Verifica conexiunea si incearca din nou.", "error");
        })
        .then(function () {
          if (submit) {
            submit.disabled = false;
            submit.textContent = "Trimite mesajul";
          }
        });
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
