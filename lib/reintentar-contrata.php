<?php
// Barrido de retenciones caducadas y reintento de los avisos por correo de ventas y reembolsos pendientes.
// Solo por línea de comandos. El cron de leads también llama a contrata_mantenimiento().
//
// Uso: php reintentar-contrata.php [--dry-run]
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/contrata.php';

$dry = in_array('--dry-run', $argv ?? [], true);
$stats = contrata_mantenimiento($dry);
$line = gmdate('c') . ' reintentar-contrata ' . json_encode($stats, JSON_UNESCAPED_UNICODE);
echo $line . "\n";
if (($stats['errores'] ?? 0) || ($stats['agotados'] ?? 0)) error_log($line);
exit(($stats['agotados'] ?? 0) > 0 ? 2 : 0);
