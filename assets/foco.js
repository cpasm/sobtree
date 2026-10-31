// Portada «Foco»: halo que sigue al ratón, paralaje del titular y diagrama desplegable.
(() => {
  "use strict";
  const foco = document.getElementById("foco");
  if (!foco) return;

  // Diagrama: el nodo activo crece y muestra su explicación (ratón, teclado o toque).
  const items = [...foco.querySelectorAll(".flow-nodes li")];
  const activate = (li) => items.forEach((x) => {
    const on = x === li;
    x.classList.toggle("on", on);
    x.querySelector("button").setAttribute("aria-expanded", String(on));
  });
  items.forEach((li) => {
    const b = li.querySelector("button");
    b.addEventListener("click", () => activate(li));
    b.addEventListener("focus", () => activate(li));
    li.addEventListener("pointerenter", (e) => { if (e.pointerType === "mouse") activate(li); });
  });

  // Foco y paralaje: solo con ratón y sin «reducir movimiento».
  const fine = matchMedia("(pointer: fine)").matches;
  const calm = matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (!fine || calm) return;
  const title = foco.querySelector("h1");
  let frame = 0, x = 0, y = 0, w = 1;
  foco.addEventListener("pointermove", (e) => {
    const r = foco.getBoundingClientRect();
    x = e.clientX - r.left; y = e.clientY - r.top; w = r.width;
    if (frame) return;
    frame = requestAnimationFrame(() => {
      frame = 0;
      foco.style.setProperty("--px", `${x}px`);
      foco.style.setProperty("--py", `${y}px`);
      title.style.setProperty("--shift", `${((x / w) - 0.5) * -16}px`);
      foco.classList.add("is-live");
    });
  });
  foco.addEventListener("pointerleave", () => { foco.classList.remove("is-live"); title.style.setProperty("--shift", "0px"); });
})();
