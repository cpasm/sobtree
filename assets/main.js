(() => {
  "use strict";
  const $ = (s, el = document) => el.querySelector(s);
  const $$ = (s, el = document) => [...el.querySelectorAll(s)];
  const reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  const scrollOpts = (block = "start") => ({ behavior: reduce ? "auto" : "smooth", block });

  /* ---------- Preselección del formulario desde los CTA ---------- */
  const interest = $("#f-interes");
  const message = $('#contact textarea[name="mensaje"]');
  // extra: { tipo: "calculadora" | "asistente", calculadora: {...}, motivo: "..." } → campos ocultos de origen.
  function prefill(opt, text, extra) {
    if (opt && interest) interest.value = opt;
    if (text && message && !message.value.trim()) message.value = text;
    const f = $("#contact");
    if (!f || !extra) return;
    if (extra.tipo) f.elements.tipo_formulario.value = extra.tipo;
    if (extra.calculadora) f.elements.datos_calculadora.value = JSON.stringify(extra.calculadora).slice(0, 2000);
    if (extra.motivo) f.elements.motivo_asistente.value = String(extra.motivo).slice(0, 300);
  }
  $$("[data-interest]").forEach((a) => a.addEventListener("click", () => prefill(a.dataset.interest)));

  /* ---------- Casos de uso por sector ---------- */
  const CASES = JSON.parse($("#casos-data").textContent);
  const ucPanel = $("#uc-panel");
  const tabs = $$(".tabs button");
  function renderCases(sector) {
    ucPanel.innerHTML = CASES[sector].casos.map((c) => `
      <article class="uc">
        <span class="uc-area">${c.area}</span>
        <h3>${c.titulo}</h3>
        <p>${c.descripcion}</p>
        <ul class="uc-kpis" aria-label="KPIs de impacto">${c.kpis.map((k) => `<li>${k}</li>`).join("")}</ul>
      </article>`).join("");
    tabs.forEach((b) => b.setAttribute("aria-selected", String(b.dataset.sector === sector)));
  }
  tabs.forEach((b, i) => {
    b.addEventListener("click", () => renderCases(b.dataset.sector));
    b.addEventListener("keydown", (e) => {
      if (e.key !== "ArrowRight" && e.key !== "ArrowLeft") return;
      const next = tabs[(i + (e.key === "ArrowRight" ? 1 : tabs.length - 1)) % tabs.length];
      next.focus(); next.click();
    });
  });
  renderCases(Object.keys(CASES)[0]);
  $$("[data-goto-sector]").forEach((a) => a.addEventListener("click", () => renderCases(a.dataset.gotoSector)));

  /* ---------- Datos que llegan de otra página (calculadora) ---------- */
  try {
    const pending = JSON.parse(sessionStorage.getItem("sobtree_prefill") || "null");
    if (pending) { prefill(pending.interes, pending.mensaje, pending); sessionStorage.removeItem("sobtree_prefill"); }
  } catch (e) {}

  /* ---------- Autodiagnóstico (una pregunta cada vez) ---------- */
  const QUESTIONS = [
    { q: "¿Cuánto usáis hoy la IA en el trabajo?", o: ["Nada", "Alguien la prueba por su cuenta", "Varios la usan a diario sin un criterio común", "La usamos en procesos concretos y medimos el ahorro"] },
    { q: "¿Cuánto tiempo se va en tareas repetitivas (emails, presupuestos, facturas, datos)?", o: ["Muchísimo, nos ahoga", "Bastante", "Algo", "Poco, ya está casi todo automatizado"] },
    { q: "¿Dónde está la información de clientes y ventas?", o: ["En papel, cabezas y correos", "En hojas de cálculo sueltas", "En un programa de gestión o CRM", "En un sistema conectado con el resto"] },
    { q: "¿Quién decidiría implantar IA en la empresa?", o: ["Nadie lo ha planteado", "Lo hemos hablado, sin responsable", "Hay alguien interesado", "Hay un responsable y presupuesto"] },
    { q: "¿Cómo protegéis los datos al usar herramientas online?", o: ["No lo hemos pensado", "Cada uno hace lo que puede", "Tenemos algunas normas", "Normas claras y planes de empresa"] },
    { q: "¿Cuándo os gustaría ver resultados?", o: ["Algún día", "Este año", "Este trimestre", "Este mes"] }
  ];
  const LEVELS = [
    { max: 5, title: "Nivel 1 · Punto de partida", text: "Tienes todo por ganar: las primeras automatizaciones suelen ser las que más tiempo ahorran.", rec: "un diagnóstico exprés para elegir la tarea que más tiempo os quita y tenerla automatizada en 2–3 semanas." },
    { max: 11, title: "Nivel 2 · Curiosidad", text: "Ya hay interés, pero cada uno va por su lado y el ahorro no se nota en la cuenta de resultados.", rec: "poner orden: un caso de uso con ahorro medible y una formación corta para todo el equipo." },
    { max: 15, title: "Nivel 3 · En marcha", text: "Usáis IA de verdad. El reto es extenderla sin multiplicar herramientas ni riesgos.", rec: "un plan de casos de uso por área y normas sencillas de uso y datos." },
    { max: 18, title: "Nivel 4 · Por delante", text: "Estás por delante de la mayoría de pymes. El valor está en automatizar procesos completos.", rec: "acompañamiento mensual para ir a por los procesos de punta a punta." }
  ];
  const answers = new Array(QUESTIONS.length).fill(null);
  let idx = 0;
  const qBox = $("#diag-q"), bar = $("#diag-bar"), count = $("#diag-count"), prev = $("#diag-prev");
  const form = $("#diag"), result = $("#diag-result");

  function renderQ() {
    const item = QUESTIONS[idx];
    count.textContent = `Pregunta ${idx + 1} de ${QUESTIONS.length}`;
    bar.style.width = `${(idx / QUESTIONS.length) * 100}%`;
    qBox.innerHTML = `<p class="diag-question" id="dq">${item.q}</p>
      <div class="diag-opts" role="group" aria-labelledby="dq">
        ${item.o.map((t, i) => `<button type="button" class="diag-opt" data-v="${i}" aria-pressed="${answers[idx] === i}"><span class="k">${"ABCD"[i]}</span>${t}</button>`).join("")}
      </div>`;
    prev.style.visibility = idx === 0 ? "hidden" : "visible";
  }
  qBox.addEventListener("click", (e) => {
    const b = e.target.closest(".diag-opt");
    if (!b) return;
    answers[idx] = Number(b.dataset.v);
    $$(".diag-opt", qBox).forEach((x) => x.setAttribute("aria-pressed", String(x === b)));
    setTimeout(() => {
      if (idx < QUESTIONS.length - 1) { idx++; renderQ(); $(".diag-opt", qBox).focus({ preventScroll: true }); }
      else showResult();
    }, reduce ? 0 : 220);
  });
  prev.addEventListener("click", () => { if (idx > 0) { idx--; renderQ(); } });

  function showResult() {
    const score = answers.reduce((a, b) => a + b, 0);
    const level = LEVELS.find((l) => score <= l.max);
    $("#r-score").textContent = score;
    $("#r-title").textContent = level.title;
    $("#r-text").textContent = level.text;
    $("#r-rec").textContent = level.rec;
    $("#r-cta").dataset.level = `${level.title} (${score}/18)`;
    form.hidden = true; result.hidden = false;
    requestAnimationFrame(() => { $("#gauge-fill").style.width = `${(score / 18) * 100}%`; });
    result.focus({ preventScroll: true });
  }
  $("#diag-reset").addEventListener("click", () => {
    answers.fill(null); idx = 0;
    $("#gauge-fill").style.width = "0";
    result.hidden = true; form.hidden = false; renderQ();
  });
  $("#r-cta").addEventListener("click", (e) => {
    prefill("Diagnóstico IA exprés", `He hecho el autodiagnóstico y estamos en «${e.currentTarget.dataset.level}». Me gustaría comentarlo.`);
  });
  renderQ();

  /* ---------- Formulario de contacto ---------- */
  // En Hostinger se envía a contact.php: guarda el lead en el servidor y avisa a info@sobtree.com por correo.
  // Si el PHP no está disponible (p. ej. abriendo el fichero en local), abre el correo del visitante.
  const contact = $("#contact");
  const status = $("#contact-status");
  const setStatus = (msg, cls = "") => { status.textContent = msg; status.className = `form-status ${cls}`; };

  function mailtoFallback(d) {
    const subject = `Solicitud de conversación — ${d.get("empresa")}`;
    const body = [["nombre", "nombre"], ["cargo", "cargo"], ["teléfono", "telefono"], ["empresa", "empresa"], ["email", "email"], ["tamaño", "tamano"], ["interés", "interes"]].map(([label, k]) => `${label}: ${d.get(k)}`).join("\n") + `\n\n${d.get("mensaje")}`;
    window.location.href = `mailto:info@sobtree.com?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;
    setStatus("Se ha abierto tu correo con la solicitud preparada. Envíala desde allí.", "ok");
  }

  // Momento de carga del formulario (antispam: los envíos instantáneos se descartan en el servidor).
  const stamp = () => { contact.elements.t0.value = String(Date.now()); };
  stamp();

  // Copia el origen de la visita (origen.js) en los campos ocultos justo antes de enviar.
  function fillOrigin() {
    const f = contact.elements;
    let o = {};
    try { o = JSON.parse(sessionStorage.getItem("sobtree_origen") || "{}") || {}; } catch (e) {}
    f.origen_pagina.value = location.href.split("#")[0].slice(0, 500);
    f.origen_landing.value = o.landing || "";
    f.origen_referrer.value = o.referrer || "";
    ["utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content"].forEach((k) => { f[k].value = (o.utm && o.utm[k]) || ""; });
  }

  contact.addEventListener("submit", async (e) => {
    e.preventDefault();
    let firstInvalid = null;
    $$("input[required]:not([type=checkbox]), select[required], textarea[required]", contact).forEach((el) => {
      const ok = el.checkValidity() && el.value.trim() !== "";
      el.setAttribute("aria-invalid", String(!ok));
      if (!ok && !firstInvalid) firstInvalid = el;
    });
    const consent = $('input[name="consentimiento"]', contact);
    consent.closest("label").classList.toggle("invalid", !consent.checked);
    if (!consent.checked && !firstInvalid) firstInvalid = consent;
    if (firstInvalid) {
      setStatus("Revisa los campos marcados: todos son obligatorios y el email debe ser válido.", "err");
      firstInvalid.focus();
      return;
    }
    fillOrigin();
    const data = new FormData(contact);
    const btn = $("button[type=submit]", contact);
    btn.disabled = true; btn.textContent = "Enviando…";
    try {
      if (location.protocol === "file:") throw new Error("local");
      const res = await fetch(contact.action, { method: "POST", body: data, headers: { Accept: "application/json" } });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) throw new Error(json.error || "server");
      contact.reset();
      // Los campos ocultos no vuelven a su valor inicial con reset(): los limpiamos a mano.
      $$('input[type=hidden]', contact).forEach((el) => { el.value = ""; });
      contact.elements.tipo_formulario.value = "contacto";
      stamp();
      setStatus("Solicitud enviada. Te responderemos en menos de 24 horas laborables. ¿Qué duda te queda antes de hablar con nosotros? Cuéntanosla en una línea respondiendo a info@sobtree.com", "ok");
    } catch (err) {
      if (err.message === "local" || err.message === "server" || err instanceof TypeError) mailtoFallback(data);
      else setStatus(err.message, "err");
    } finally {
      btn.disabled = false; btn.textContent = "Solicitar conversación";
    }
  });
})();
