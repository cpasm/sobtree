<?php
// Recibe el formulario de contacto (portada), también cuando llega desde la calculadora o el asistente.
//
// Orden (para no perder ningún lead):
//   1) valida y descarta spam (honeypot, tiempo mínimo, límite por IP);
//   2) GUARDA el lead en el registro local (lib/leads.php) ANTES de cualquier envío;
//   3) responde al visitante;
//   4) después envía el aviso a info@sobtree.com («[Sobtree] Nuevo contacto <id>») y, si corresponde,
//      la respuesta automática E-02 (solo formulario de contacto y en horario laboral; si no, en cola).
//      Lo pendiente lo reintenta lib/reintentar-leads.php (cron) sin duplicar correos.
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

require __DIR__ . '/lib/leads.php';

function reply(int $code, array $body, bool $finish = false): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    if (!$finish) exit;
    // Cerramos la respuesta al visitante y seguimos trabajando (correos) sin hacerle esperar.
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else { @ob_end_flush(); @flush(); }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

// ---------- Antispam ----------
// Trampa para bots: si el campo oculto viene relleno, se descarta en silencio.
if (!empty($_POST['web'])) {
    reply(200, ['ok' => true]);
}
// Envíos en menos de 3 s desde que se cargó el formulario: casi seguro un bot (solo si el dato viene).
$t0 = (int)($_POST['t0'] ?? 0);
if ($t0 > 0 && (int)(microtime(true) * 1000) - $t0 < 3000) {
    reply(200, ['ok' => true]);
}

// ---------- Campos ----------
$clean = function (string $k, int $max = 4000): string {
    $v = trim((string)($_POST[$k] ?? ''));
    $v = str_replace(["\r", "\0"], '', $v);
    return mb_substr($v, 0, $max);
};
$required = ['nombre' => 200, 'telefono' => 40, 'empresa' => 200, 'email' => 254, 'tamano' => 60, 'interes' => 100, 'mensaje' => 4000];
$data = [];
foreach ($required as $f => $max) {
    $raw = trim((string)($_POST[$f] ?? ''));
    if ($raw === '' || mb_strlen($raw) > $max) {
        reply(422, ['ok' => false, 'error' => 'Falta algún campo obligatorio o es demasiado largo.']);
    }
    $data[$f] = $clean($f, $max);
}
$data['cargo'] = $clean('cargo', 120); // opcional
if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
    reply(422, ['ok' => false, 'error' => 'El email no es válido. Revísalo e inténtalo de nuevo.']);
}
if (!preg_match('/^\+?[0-9 ()\.\-]{6,25}$/', $data['telefono'])) {
    reply(422, ['ok' => false, 'error' => 'El teléfono no parece válido. Revísalo e inténtalo de nuevo.']);
}
if (($_POST['consentimiento'] ?? '') !== 'si') {
    reply(422, ['ok' => false, 'error' => 'Necesitamos que aceptes la política de privacidad para responderte.']);
}
// Casilla aparte, opcional y sin marcar. Ausente = no. Cualquier valor que no sea booleano se rechaza.
$comercial = array_key_exists('consentimiento_comercial', $_POST)
    ? leads_parse_bool($_POST['consentimiento_comercial'])
    : false;
if ($comercial === null) {
    reply(422, ['ok' => false, 'error' => 'La casilla de comunicaciones comerciales no es válida.']);
}

// ---------- Origen ----------
$tipo = $clean('tipo_formulario', 20);
if (!in_array($tipo, ['contacto', 'calculadora', 'asistente'], true)) $tipo = 'contacto';
$url = fn(string $k): string => (string)(filter_var($clean($k, 500), FILTER_VALIDATE_URL) ?: '');
$utm = [];
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) $utm[$k] = $clean($k, 150);
$calc = json_decode($clean('datos_calculadora', 2000), true);
$calculadora = [];
if (is_array($calc)) {
    // Solo los campos conocidos, con tipo controlado.
    foreach (['sector' => 's', 'personas' => 'n', 'horas' => 'n', 'coste' => 'n', 'ahorro_min' => 'n', 'ahorro_max' => 'n', 'precio_min' => 'n', 'precio_max' => 'n', 'diagnostico' => 'n', 'descuento' => 'n', 'puesta_min' => 'n', 'puesta_max' => 'n', 'neto_min' => 'n', 'neto_max' => 'n'] as $k => $t) {
        if (!array_key_exists($k, $calc)) continue;
        $calculadora[$k] = $t === 'n' ? (is_numeric($calc[$k]) ? round((float)$calc[$k], 2) : null) : mb_substr((string)$calc[$k], 0, 80);
    }
    if (isset($calc['areas']) && is_array($calc['areas'])) {
        $calculadora['areas'] = array_slice(array_map(fn($a) => mb_substr((string)$a, 0, 60), $calc['areas']), 0, 10);
    }
}

// ---------- Límite: un envío cada 30 s por IP ----------
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$lock = sys_get_temp_dir() . '/sobtree_contact_' . md5($ip);
if (is_file($lock) && (time() - filemtime($lock)) < 30) {
    reply(429, ['ok' => false, 'error' => 'Acabas de enviar una solicitud. Espera unos segundos.']);
}
@touch($lock);

$lead = [
    'v' => LEADS_SCHEMA_VERSION,
    'id' => leads_uuid(),
    'fecha' => gmdate('c', leads_ahora()),
    'tipo_formulario' => $tipo,
    'nombre' => $data['nombre'],
    'cargo' => $data['cargo'],
    'telefono' => $data['telefono'],
    'empresa' => $data['empresa'],
    'email' => mb_strtolower($data['email']),
    'tamano' => $data['tamano'],
    'interes' => $data['interes'],
    'mensaje' => $data['mensaje'],
    'consentimiento' => [
        'acepta' => true,
        'texto' => 'Acepto la política de privacidad y que Sobtree use estos datos para responder a mi solicitud.',
        'fecha' => gmdate('c', leads_ahora()),
    ],
    'consentimiento_comercial' => leads_consentimiento_comercial($comercial),
    'origen' => [
        'pagina' => $url('origen_pagina'),
        'landing' => $url('origen_landing'),
        'referrer' => $url('origen_referrer'),
        'utm' => $utm,
    ],
    'calculadora' => $calculadora,
    'asistente' => ['motivo' => $clean('motivo_asistente', 300)],
    'meta' => [
        'ip_hash' => hash('sha256', $ip . gmdate('Y-m-d')),
        'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200),
    ],
];

// ---------- 1) Guardar SIEMPRE primero ----------
$saved = false;
try { $saved = leads_save($lead); } catch (Throwable $e) { error_log('contact: ' . $e->getMessage()); }

if (!$saved) {
    // Sin registro local, el correo es la única copia: lo enviamos antes de responder.
    error_log('contact: no se pudo guardar el lead ' . $lead['id'] . ' en el registro local');
    if (!leads_send_mail($lead, 'sin_registro_local')) {
        reply(500, ['ok' => false, 'error' => 'No se ha podido enviar. Escríbenos directamente a info@sobtree.com.']);
    }
    // Sin registro no hay respuesta automática: no se podría garantizar que sale una sola vez.
    reply(200, ['ok' => true]);
}

// ---------- 2) Responder ya al visitante ----------
reply(200, ['ok' => true], true);

// ---------- 3) Entrega, después de responder ----------
// Aviso a info@ (siempre) y respuesta E-02 (si corresponde). Lo que falle lo reintenta el cron.
ignore_user_abort(true);
leads_deliver($lead);
