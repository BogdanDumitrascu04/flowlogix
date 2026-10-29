/* ========================================
   MENIU LATERAL PE TELEFON ȘI TABLETĂ
   Sub 900px sidebar-ul e ascuns în stânga (css/sidebar.css).
   Butonul din bara de sus îl deschide; fundalul, butonul X,
   tasta Escape sau alegerea unei pagini îl închid.
   ======================================== */
(function () {
  const body = document.body;
  const sidebar = document.getElementById("appSidebar");
  const toggle = document.getElementById("menuToggle");
  const closeBtn = document.getElementById("sidebarClose");
  const backdrop = document.getElementById("sidebarBackdrop");
  if (!sidebar || !toggle || !backdrop) return;

  function setOpen(open) {
    body.classList.toggle("sidebar-open", open);
    toggle.setAttribute("aria-expanded", open ? "true" : "false");
    if (open) {
      (closeBtn || sidebar).focus();
    }
  }

  toggle.addEventListener("click", () => setOpen(true));
  backdrop.addEventListener("click", () => setOpen(false));
  if (closeBtn) {
    closeBtn.addEventListener("click", () => {
      setOpen(false);
      toggle.focus();
    });
  }

  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && body.classList.contains("sidebar-open")) {
      setOpen(false);
      toggle.focus();
    }
  });

  // Linkurile cu ancoră (#situatii-intarziate) rămân pe aceeași pagină, deci meniul trebuie închis explicit
  sidebar.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => setOpen(false));
  });

  // Pe ecran lat sidebar-ul e mereu vizibil, așa că starea „deschis" se resetează
  window.matchMedia("(min-width: 901px)").addEventListener("change", (e) => {
    if (e.matches) setOpen(false);
  });
})();
