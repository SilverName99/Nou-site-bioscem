/* =========================================================
   BIOSCEM - PAGINA PRODUS
   Ajustari mici peste HTML-ul generat de platforma.
   Caruselul, tab-urile, produsele similare si butonul de cos
   sunt deja controlate de scripturile site-ului; aici tratam
   doar starile care nu au placeholder propriu.
   ========================================================= */

(function () {
  "use strict";

  function init() {
    var page = document.querySelector(".bs-product-page");
    if (!page) {
      return;
    }

    /* --- Cod produs: ascunde eticheta daca SKU-ul lipseste --- */
    var skuValue = page.querySelector("[data-bs-sku]");
    if (skuValue && skuValue.textContent.trim() === "") {
      var skuWrap = skuValue.closest(".bs-sku");
      if (skuWrap) {
        skuWrap.style.display = "none";
      }
    }

    /* --- Stoc: platforma inlocuieste butonul cu eticheta
           "Stoc epuizat", deci deducem starea din DOM --- */
    var outOfStock = page.querySelector(".product-out-of-stock-label");
    if (outOfStock) {
      page.classList.add("is-out-of-stock");

      var stockText = page.querySelector(".bs-stock-text");
      if (stockText) {
        stockText.textContent = "Stoc epuizat";
      }
    }
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
