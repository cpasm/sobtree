<?php
// Reintento de leads (para cron). Solo por línea de comandos.
//
// 1) Aviso a info@: reenvía el correo de los leads del registro local cuyo último estado en el canal
//    «correo» sea «correo_error» o que no tengan estado (p. ej. si el proceso se cortó tras guardar).
//    La marca en estado.jsonl evita duplicados: un aviso que salió bien no se repite.
// 2) Respuesta automática E-02: en horario laboral (L-V 9:00-19:00 Madrid) envía las pendientes, las más
//    antiguas primero y como mucho 5 por ejecución. Fuera de horario solo las deja en cola.
// 3) Mantenimiento de Contrata (retenciones caducadas y cola de avisos de ventas).
//
// Uso:   php reintentar-leads.php [--dry-run] [--max-edad-dias=30]
// Cron (hPanel → Avanzado → Cron Jobs), cada 15 min:
//   /usr/bin/php /home/<usuario>/domains/sobtree.com/public_html/lib/reintentar-leads.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/leads.php';

$dry = in_array('--dry-run', $argv, true);
$maxDays = 30;
foreach ($argv as $a) if (preg_match('/^--max-edad-dias=(\d+)$/', $a, $m)) $maxDays = (int)$m[1];

// Un único proceso a la vez.
$lockFh = fopen(leads_ensure_dir() . '/.reintento.lock', 'c');
if (!$lockFh || !flock($lockFh, LOCK_EX | LOCK_NB)) { fwrite(STDERR, "Otro reintento en curso.\n"); exit(0); }

[$leads, $state] = leads_load_all();
$stats = ['revisados' => 0, 'avisos' => 0, 'avisos_error' => 0, 'e02_enviados' => 0, 'e02_error' => 0, 'e02_pendientes' => 0, 'e02_descartados' => 0];
$ahora = leads_ahora();
$limit = $ahora - $maxDays * 86400;
$candidatosE02 = [];

foreach ($leads as $id => $lead) {
    $ts = strtotime((string)($lead['fecha'] ?? ''));
    if ($ts === false || $ts < $limit) continue;
    $stats['revisados']++;
    $correoPrevio = $state["$id|correo"]['ultimo'] ?? null;
    $e02Previo = $state["$id|e02"]['ultimo'] ?? null;
    // Recién llegado y sin estado: contact.php puede estar entregándolo ahora mismo.
    $stale = $ts < $ahora - 600;
    if ($correoPrevio === null && $e02Previo === null && !$stale) continue;

    if ($correoPrevio !== 'correo_ok') {
        if ($dry) { echo "[dry-run] reenviaría el aviso de $id (correo=" . ($correoPrevio ?? '—') . ")\n"; }
        else {
            $ok = leads_send_mail($lead);
            leads_mark($id, 'correo', $ok ? 'correo_ok' : 'correo_error', 'aviso (cron)');
            $stats[$ok ? 'avisos' : 'avisos_error']++;
        }
    }
    if (!leads_e02_final($e02Previo)) $candidatosE02[$id] = $ts;
}

// E-02: las más antiguas primero; freno de LEADS_E02_MAX_POR_CRON envíos por ejecución.
asort($candidatosE02);
$presupuesto = LEADS_E02_MAX_POR_CRON;
foreach (array_keys($candidatosE02) as $id) {
    if ($dry) { echo "[dry-run] revisaría E-02 de $id\n"; continue; }
    $r = leads_e02_procesar($leads[$id], $presupuesto);
    if ($r === 'enviado') { $stats['e02_enviados']++; $presupuesto--; }
    elseif ($r === 'error') { $stats['e02_error']++; $presupuesto--; }
    elseif ($r === 'pendiente') $stats['e02_pendientes']++;
    elseif ($r === 'descartado') $stats['e02_descartados']++;
}

$line = gmdate('c') . ' reintentar-leads ' . json_encode($stats);
echo $line . "\n";
if ($stats['avisos_error'] || $stats['e02_error']) error_log($line);

// El mismo cron barre retenciones caducadas de Contrata y reintenta los avisos de ventas pendientes.
// Si Contrata no está configurado, no crea carpetas ni envía correo.
try {
    require_once __DIR__ . '/contrata.php';
    contrata_mantenimiento($dry);
} catch (Throwable $e) {
    error_log('contrata: ' . $e->getMessage());
}

exit($stats['avisos_error'] > 0 ? 2 : 0);
