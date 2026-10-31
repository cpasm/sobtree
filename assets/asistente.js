// Asistente: burbuja «Deja tu nombre y comienza a ahorrar» → diálogo que pregunta a /api/asistente.php.
// La respuesta llega con citas y, si hay intención de contratar, con oferta de reunión.
(() => {
  "use strict";
  const $ = (s, el = document) => el.querySelector(s);
  const API = "/api/asistente.php";

  const assist = $("#assist"), card = $("#assist-card"), fab = $("#assist-fab");
  const chat = $("#chat"), log = $("#chat-log"), chips = $("#chat-chips");
  if (!assist || !chat || typeof chat.showModal !== "function") return;

  let ready = false;     // el servidor tiene clave: se puede conversar
  let name = "";
  try { name = sessionStorage.getItem("sobtree_nombre") || ""; } catch (e) {}
  fetch(`${API}?estado`, { headers: { Accept: "application/json" } })
    .then((r) => (r.ok ? r.json() : { ok: false })).then((d) => { ready = !!d.ok; }).catch(() => {});

  const setCard = (open) => { card.hidden = !open; fab.setAttribute("aria-expanded", String(open)); if (open) $("#assist-name").focus({ preventScroll: true }); };
  // Si el visitante la cierra, no vuelve a aparecer sola en esta sesión.
  const dismiss = () => { setCard(false); try { sessionStorage.setItem("sobtree_assist_cerrado", "1"); } catch (e) {} };
  fab.addEventListener("click", () => (card.hidden ? setCard(true) : dismiss()));
  $("#assist-close").addEventListener("click", () => { dismiss(); fab.focus(); });
  // La tarjeta aparece sola a los pocos segundos, salvo que el visitante ya la cerrara.
  let closed = false;
  try { closed = sessionStorage.getItem("sobtree_assist_cerrado") === "1"; } catch (e) {}
  if (!closed) setTimeout(() => {
    if (chat.open || !card.hidden) return;
    card.hidden = false; fab.setAttribute("aria-expanded", "true");
  }, 3500);

  const esc = (s) => s.replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]);
  function md(text) {
    return esc(text).replace(/\*\*(.+?)\*\*/g, "<strong>$1</strong>").split(/\n{2,}/).map((block) => {
      const items = block.split("\n");
      if (items.every((l) => /^\s*[-•]\s+/.test(l))) return `<ul>${items.map((l) => `<li>${l.replace(/^\s*[-•]\s+/, "")}</li>`).join("")}</ul>`;
      return `<p>${block.replace(/\n/g, "<br>")}</p>`;
    }).join("");
  }
  function bubble(cls, html) {
    const el = document.createElement("div");
    el.className = `msg ${cls}`;
    el.innerHTML = html;
    log.appendChild(el);
    log.scrollTop = log.scrollHeight;
    return el;
  }

  // Lleva al formulario de contacto con el nombre y el motivo ya escritos.
  function toContact(motivo) {
    if (chat.open) chat.close();
    setCard(false);
    const f = $("#contact");
    if (!f) {
      // Fuera de la portada: dejamos el contexto para que main.js lo cargue en el formulario.
      try {
        sessionStorage.setItem("sobtree_prefill", JSON.stringify({
          interes: "Aún no lo sé", tipo: "asistente", motivo,
          mensaje: motivo ? `Vengo del asistente de la web. Me gustaría resolver: ${motivo}` : ""
        }));
      } catch (e) {}
      location.href = "/#contacto"; return;
    }
    // Marca el lead como llegado desde el asistente, con el motivo detectado.
    if (f.elements.tipo_formulario) f.elements.tipo_formulario.value = "asistente";
    if (f.elements.motivo_asistente && motivo) f.elements.motivo_asistente.value = String(motivo).slice(0, 300);
    const nombre = $('input[name="nombre"]', f), interes = $("#f-interes"), msg = $('textarea[name="mensaje"]', f);
    if (nombre && name && !nombre.value) nombre.value = name;
    if (interes && !interes.value) interes.value = "Aún no lo sé";
    if (msg && !msg.value.trim() && motivo) msg.value = `Vengo del asistente de la web. Me gustaría resolver: ${motivo}`;
    $("#contacto").scrollIntoView({ behavior: "smooth" });
    setTimeout(() => (nombre && !nombre.value ? nombre : $('input[name="telefono"]', f))?.focus({ preventScroll: true }), 500);
  }

  function offer(motivo) {
    const el = bubble("offer", `<p><strong>¿Lo vemos para tu caso${name ? `, ${esc(name)}` : ""}?</strong> 25 minutos con un perfil senior, sin compromiso.</p>
      <button type="button" class="btn btn-primary btn-sm">Reservar conversación</button>`);
    $("button", el).addEventListener("click", () => toContact(motivo));
  }

  function render(data) {
    const byN = Object.fromEntries((data.sources || []).map((s) => [s.n, s]));
    const cites = (c) => c.map((n) => byN[n] ? `<sup><a href="${esc(byN[n].url)}" title="${esc(byN[n].title)}">${n}</a></sup>` : "").join("");
    let html = md(data.segments.map((s, i) => s.t + (s.c.length ? `\u0000${i}\u0000` : "")).join(""));
    html = html.replace(/\u0000(\d+)\u0000/g, (_, i) => cites(data.segments[+i].c));
    if (data.sources?.length) html += `<ol class="msg-sources">${data.sources.map((s) => `<li value="${s.n}"><a href="${esc(s.url)}">${esc(s.title)}</a></li>`).join("")}</ol>`;
    bubble("bot", html);
    if (data.offer) offer(data.offer);
    return data.segments.map((s) => s.t).join("");
  }

  const history = [];
  let busy = false;
  function openChat() {
    setCard(false);
    if (!chat.open) chat.showModal();
    if (!log.childElementCount) {
      bubble("bot", `<p><strong>${name ? `Hola, ${esc(name)}.` : "Hola."}</strong> Vamos a por ese ahorro. ¿En qué tareas se os va más tiempo cada semana: emails, presupuestos, facturas…?</p>`);
    }
    $("#chat-q").focus();
  }

  async function send(question) {
    question = question.trim();
    if (!question || busy) return;
    chips.hidden = true;
    bubble("user", `<p>${esc(question)}</p>`);
    if (!ready) { // sin asistente configurado: directo al formulario
      bubble("bot", `<p>Esto lo vemos mejor en persona. Te llevo al formulario con tu pregunta ya escrita.</p>`);
      setTimeout(() => toContact(question), 900);
      return;
    }
    busy = true;
    const typing = bubble("bot typing", "<span></span><span></span><span></span>");
    chat.querySelectorAll("form input, form button").forEach((el) => (el.disabled = true));
    history.push({ role: "user", content: question });
    try {
      const res = await fetch(API, { method: "POST", headers: { "Content-Type": "application/json", Accept: "application/json" }, body: JSON.stringify({ messages: history }) });
      const data = await res.json().catch(() => ({}));
      typing.remove();
      if (!res.ok || !data.segments) throw new Error(data.error || "No he podido responder ahora mismo. Inténtalo de nuevo.");
      history.push({ role: "assistant", content: render(data) });
    } catch (err) {
      typing.remove();
      history.pop();
      bubble("error", `<p>${esc(err.message || "Error de conexión.")}</p>`);
    } finally {
      busy = false;
      chat.querySelectorAll("form input, form button").forEach((el) => (el.disabled = false));
      $("#chat-q").focus();
    }
  }

  $("#assist-form").addEventListener("submit", (e) => {
    e.preventDefault();
    name = $("#assist-name").value.trim().slice(0, 80);
    try { sessionStorage.setItem("sobtree_nombre", name); } catch (err) {}
    openChat();
  });
  $("#chat-form").addEventListener("submit", (e) => { e.preventDefault(); const q = $("#chat-q"); send(q.value); q.value = ""; });
  chips.addEventListener("click", (e) => { const b = e.target.closest("[data-ask]"); if (b) send(b.dataset.ask); });
  $("#chat-close").addEventListener("click", () => chat.close());
  chat.addEventListener("click", (e) => { if (e.target === chat) chat.close(); });
})();
