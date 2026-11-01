// Calculadora para pymes (/calculadora/): horquilla de ahorro anual con IA y precio cerrado orientativo.
// Mismas tarifas que los servicios publicados (src/data/site.ts). Todos los supuestos se muestran en la página.
(() => {
  "use strict";
  const $ = (s) => document.querySelector(s);
  const form = $("#calc");
  if (!form) return;

  // Números y condición del descuento: los inyecta la página desde src/data/site.ts (DIAGNOSTICO).
  // desdeCfg es la misma cuenta que precioCerrado() en ese fichero. El diagnóstico no lleva el 25 %.
  const cfg = window.__SOBTREE_PRECIO;
  if (!cfg) return;
  function desdeCfg(personas, n) {
    const big = personas > cfg.umbralRecargo ? cfg.recargo : 1;
    const train = personas > cfg.umbralFormacion ? cfg.trainBig : cfg.trainSmall;
    const areas = Math.max(1, n);
    const round10 = (v) => Math.round(v / 10) * 10;
    const puesta = [0, 1].map((i) => round10((cfg.first[i] + cfg.extra[i] * (areas - 1) + train[i]) * big));
    const descuento = cfg.descuentoCondicionado ? cfg.precio : 0;
    return {
      diagnostico: cfg.precio,
      descuento,
      puestaMin: puesta[0],
      puestaMax: puesta[1],
      netoMin: puesta[0] + cfg.precio - descuento,
      netoMax: puesta[1] + cfg.precio - descuento
    };
  }
  const WEEKS = 46;         // semanas efectivas al año
  const eur = new Intl.NumberFormat("es-ES", { style: "currency", currency: "EUR", maximumFractionDigits: 0, useGrouping: "always" });
  const nf = new Intl.NumberFormat("es-ES", { maximumFractionDigits: 0, useGrouping: "always" });
  const round = (v, step) => Math.round(v / step) * step;
  let last = null;

  function calc() {
    const personas = +$("#c-personas").value, horas = +$("#c-horas").value, coste = +$("#c-coste").value;
    const areas = [...form.querySelectorAll('input[name="area"]:checked')];
    const n = Math.max(1, areas.length);
    const sector = form.querySelector('input[name="sector"]:checked')?.value || "";

    // Pinta los controles deslizantes
    [["#c-personas", "#o-personas", (v) => (v >= 100 ? "100 o más" : nf.format(v))], ["#c-horas", "#o-horas", (v) => `${v} h`], ["#c-coste", "#o-coste", (v) => `${v} €/h`]].forEach(([i, o, f]) => {
      const inp = $(i);
      $(o).textContent = f(+inp.value);
      inp.style.setProperty("--p", `${((inp.value - inp.min) / (inp.max - inp.min)) * 100}%`);
    });

    // Ahorro: parte del tiempo repetitivo que la IA puede quitar, según cuántas áreas se automatizan
    const shareLo = Math.min(0.40, 0.15 + 0.05 * n), shareHi = Math.min(0.65, 0.30 + 0.08 * n);
    const horasAno = personas * horas * WEEKS;
    const saveLo = horasAno * shareLo * coste, saveHi = horasAno * shareHi * coste;

    // Diagnóstico fijo, sin recargo. El 25 % solo entra en la puesta en marcha (áreas + formación).
    const precio = desdeCfg(personas, n);
    const feeLo = precio.netoMin, feeHi = precio.netoMax;

    // Licencias de IA: ~20–30 €/usuario/mes para la mitad de la plantilla
    const users = Math.max(1, Math.ceil(personas * 0.5));
    const licLo = users * 20, licHi = users * 30;

    // Recuperación de la inversión, con escenario prudente (ahorro bajo, coste alto)
    const netMonth = saveLo / 12 - licHi;
    const payback = netMonth > 0 ? feeHi / netMonth : Infinity;

    $("#r-save-lo").textContent = eur.format(round(saveLo, 100));
    $("#r-save-hi").textContent = eur.format(round(saveHi, 100));
    $("#r-hours").textContent = `${nf.format(horasAno * shareLo / 12)}–${nf.format(horasAno * shareHi / 12)}`;
    $("#r-diag").textContent = eur.format(precio.diagnostico);
    $("#r-puesta-lo").textContent = eur.format(precio.puestaMin);
    $("#r-puesta-hi").textContent = eur.format(precio.puestaMax);
    $("#r-fee-lo").textContent = eur.format(feeLo);
    $("#r-fee-hi").textContent = eur.format(feeHi);
    $("#r-lic").textContent = `${eur.format(licLo)}–${eur.format(licHi)}`;
    $("#a-share").textContent = `entre un ${Math.round(shareLo * 100)} % y un ${Math.round(shareHi * 100)} %`;
    $("#r-payback").textContent = !isFinite(payback) ? "más de un año" : payback < 1 ? "menos de 1 mes" : payback > 12 ? "más de un año" : `unos ${Math.ceil(payback)} ${Math.ceil(payback) === 1 ? "mes" : "meses"}`;

    // Barras: ahorro del primer año (medio) frente a inversión del primer año (trabajo + licencias, medio)
    const save1 = (saveLo + saveHi) / 2, cost1 = (feeLo + feeHi) / 2 + ((licLo + licHi) / 2) * 12;
    const max = Math.max(save1, cost1, 1);
    $("#b-save").style.width = `${(save1 / max) * 100}%`;
    $("#b-cost").style.width = `${(cost1 / max) * 100}%`;

    last = { sector, personas, horas, coste, areas: areas.map((a) => a.dataset.name), saveLo, saveHi, feeLo, feeHi, precio };
  }

  form.addEventListener("input", calc);
  form.addEventListener("change", calc);
  calc();

  // El formulario de contacto está en la portada: dejamos el cálculo escrito para que llegue con el lead.
  // Además del texto, viajan los datos estructurados para guardarlos con el lead (contact.php → registro local y aviso a info@).
  $("#calc-cta").addEventListener("click", () => {
    if (!last) return;
    try {
      sessionStorage.setItem("sobtree_prefill", JSON.stringify({
        interes: "Diagnóstico IA exprés",
        tipo: "calculadora",
        calculadora: {
          sector: last.sector, personas: last.personas, horas: last.horas, coste: last.coste, areas: last.areas,
          ahorro_min: round(last.saveLo, 100), ahorro_max: round(last.saveHi, 100),
          precio_min: last.feeLo, precio_max: last.feeHi,
          diagnostico: last.precio.diagnostico, descuento: last.precio.descuento,
          puesta_min: last.precio.puestaMin, puesta_max: last.precio.puestaMax,
          neto_min: last.precio.netoMin, neto_max: last.precio.netoMax
        },
        mensaje: `He usado la calculadora. Sector: ${last.sector}. ${last.personas} personas, ${last.horas} h/semana en tareas repetitivas, ${last.coste} €/h. Quiero automatizar: ${last.areas.join(", ") || "por decidir"}. Ahorro estimado: ${eur.format(round(last.saveLo, 100))}–${eur.format(round(last.saveHi, 100))} al año. Diagnóstico: ${eur.format(last.precio.diagnostico)}. Puesta en marcha: ${eur.format(last.precio.puestaMin)}–${eur.format(last.precio.puestaMax)}. Descuento: ${eur.format(last.precio.descuento)}. Total neto: ${eur.format(last.feeLo)}–${eur.format(last.feeHi)}.`
      }));
    } catch (e) {}
  });
})();
