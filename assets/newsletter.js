// Formulario de newsletter: se muestra si /api/newsletter.php está configurado y envía email + consentimiento.
(() => {
  "use strict";
  const box = document.getElementById("newsletter");
  if (!box) return;
  const API = "/api/newsletter.php";
  const form = document.getElementById("nl-form");
  const status = document.getElementById("nl-status");
  const setStatus = (msg, cls = "") => { status.textContent = msg; status.className = `nl-status ${cls}`; };

  fetch(`${API}?estado`, { headers: { Accept: "application/json" } })
    .then((r) => (r.ok ? r.json() : { ok: false })).then((d) => { if (d.ok) box.hidden = false; }).catch(() => {});

  form.addEventListener("submit", async (e) => {
    e.preventDefault();
    const email = form.email, consent = form.consentimiento;
    const okEmail = email.checkValidity() && email.value.trim() !== "";
    email.setAttribute("aria-invalid", String(!okEmail));
    consent.closest("label").classList.toggle("invalid", !consent.checked);
    if (!okEmail) { setStatus("Revisa el email: no parece válido.", "err"); email.focus(); return; }
    if (!consent.checked) { setStatus("Marca la casilla para poder enviarte la newsletter.", "err"); consent.focus(); return; }
    const btn = form.querySelector("button[type=submit]");
    btn.disabled = true; btn.textContent = "Enviando…";
    try {
      const res = await fetch(API, {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ email: email.value.trim(), consentimiento: "si", web: form.web.value, origen: location.href })
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data.ok) throw new Error(data.error || "No hemos podido apuntarte ahora mismo. Inténtalo de nuevo.");
      form.reset();
      setStatus("Hecho. El próximo Radar te llega al correo.", "ok");
    } catch (err) {
      setStatus(err.message || "Error de conexión.", "err");
    } finally {
      btn.disabled = false; btn.textContent = "Suscribirme";
    }
  });
})();
