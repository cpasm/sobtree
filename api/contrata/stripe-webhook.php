<?php
// Webhook de Stripe. Verifica la firma, deduplica por event.id, guarda la venta o el reembolso en el
// registro local y confirma o libera la retención. Después avisa por correo a info@ (el cron reintenta).
// Ningún cobro sin registro: si no se puede guardar, responde 500 y Stripe vuelve a enviar el evento.
// Solo acepta el secreto configurado. Rechaza cualquier clave live.
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require dirname(__DIR__, 2) . '/lib/contrata.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido.']);
    exit;
}
$raw = file_get_contents('php://input') ?: '';
$header = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
try {
    $r = contrata_recibir_webhook($raw, $header);
} catch (Throwable $e) {
    error_log('contrata webhook: no se pudo registrar el evento (Stripe lo reintentará)');
    $r = ['http' => 500, 'body' => ['ok' => false, 'error' => 'No se ha procesado.']];
}
http_response_code($r['http']);
echo json_encode($r['body'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
