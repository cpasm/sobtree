<?php
// Valida empresa, email y NIF/CIF, retiene la plaza con flock y abre Checkout
// (Stripe en modo test, o el simulador local). Si Stripe falla, suelta la retención.
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require dirname(__DIR__, 2) . '/lib/contrata.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 20000) {
    $in = ['_too_big' => true];
} elseif (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
    $in = json_decode($raw, true);
    $in = is_array($in) ? $in : [];
} else {
    $in = $_POST;
}
try {
    $r = contrata_abrir_sesion($in);
} catch (Throwable $e) {
    error_log('contrata crear-sesion: fallo');
    $r = ['http' => 500, 'body' => ['ok' => false, 'error' => CONTRATA_MSG_PAGO]];
}
http_response_code($r['http']);
echo json_encode($r['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
