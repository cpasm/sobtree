// Origen de la visita para atribuir los leads: página de entrada, referrer externo y UTM.
// Se guarda solo en sessionStorage (se borra al cerrar la pestaña), sin cookies ni envíos a terceros.
// main.js lo copia en los campos ocultos del formulario de contacto al enviarlo.
(() => {
  "use strict";
  try {
    const KEY = "sobtree_origen";
    const params = new URLSearchParams(location.search);
    const utm = {};
    ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content"].forEach((k) => {
      const v = params.get(k);
      if (v) utm[k] = v.slice(0, 150);
    });
    let ref = "";
    try { if (document.referrer && new URL(document.referrer).host !== location.host) ref = document.referrer.slice(0, 500); } catch (e) {}
    const here = location.href.split("#")[0].slice(0, 500);
    let o = null;
    try { o = JSON.parse(sessionStorage.getItem(KEY) || "null"); } catch (e) {}
    if (!o) o = { landing: here, referrer: ref, utm };
    else if (Object.keys(utm).length) o = { landing: here, referrer: ref || o.referrer, utm }; // nueva campaña: manda la última
    sessionStorage.setItem(KEY, JSON.stringify(o));
  } catch (e) {}
})();
