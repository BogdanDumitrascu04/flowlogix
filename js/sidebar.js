/* Off-canvas sidebar below 900px (css/sidebar.css). The top bar button opens it;
   the backdrop, the close button, Escape or a link close it. */
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

  // Anchor links (#situatii-intarziate) stay on the same page, so the menu has to be closed here.
  sidebar.querySelectorAll("a").forEach((link) => {
    link.addEventListener("click", () => setOpen(false));
  });

  // Above 900px the sidebar is always visible, so drop the open state.
  window.matchMedia("(min-width: 901px)").addEventListener("change", (e) => {
    if (e.matches) setOpen(false);
  });
})();
