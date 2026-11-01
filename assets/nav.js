// Cabecera, menú móvil y año del pie. Se carga en todas las páginas.
(() => {
  "use strict";
  const $ = (s, el = document) => el.querySelector(s);
  /* ---------- Cabecera y menú ---------- */
  const header = $(".site-header");
  const onScroll = () => header.classList.toggle("scrolled", window.scrollY > 8);
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  const toggle = $(".menu-toggle");
  const mobileNav = $("#mobile-nav");
  const setMenu = (open) => {
    toggle.setAttribute("aria-expanded", String(open));
    toggle.setAttribute("aria-label", open ? "Cerrar menú" : "Abrir menú");
    mobileNav.hidden = !open;
  };
  toggle.addEventListener("click", () => setMenu(toggle.getAttribute("aria-expanded") !== "true"));
  mobileNav.addEventListener("click", (e) => { if (e.target.closest("a")) setMenu(false); });

  $("#year").textContent = new Date().getFullYear();
})();
