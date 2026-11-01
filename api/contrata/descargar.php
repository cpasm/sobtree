<?php
// Descarga de un producto digital (hoy, el Kit IA para asesorías y gestorías).
// GET ?sesion=cs_… (vuelta de Stripe) o ?k=<token de la factura>. Con &comprobar=1 solo responde JSON con el estado.
// Solo entrega el ZIP si la venta está pagada y registrada en el registro local por el webhook de Stripe.
// No consulta Stripe. Máximo CONTRATA_DESCARGAS_MAX descargas por compra. El archivo no es público:
// vive fuera de public_html (sobtree-kits/) o en lib/kits/, denegado por .htaccess.
declare(strict_types=1);
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
require dirname(__DIR__, 2) . '/lib/contrata.php';

function descargar_json(int $http, array $body): void {
    http_response_code($http);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    descargar_json(405, ['ok' => false, 'error' => 'Método no permitido.']);
}
$sesion = is_string($_GET['sesion'] ?? null) ? (string)$_GET['sesion'] : '';
$token = is_string($_GET['k'] ?? null) ? strtolower((string)$_GET['k']) : '';
$comprobar = ($_GET['comprobar'] ?? '') === '1';
try {
    $e = contrata_descarga_estado($sesion, $token);
} catch (Throwable $ex) {
    error_log('contrata descargar: fallo al comprobar');
    descargar_json(503, ['ok' => false, 'motivo' => 'no_disponible', 'reintentar' => true, 'error' => contrata_texto_descarga('no_disponible')]);
}
$resp = [
    'ok' => $e['http'] === 200,
    'motivo' => $e['motivo'],
    'reintentar' => $e['reintentar'],
    'mensaje' => contrata_texto_descarga($e['motivo']),
    'descargas_max' => CONTRATA_DESCARGAS_MAX,
];
if (isset($e['restantes'])) $resp['restantes'] = $e['restantes'];
if ($e['reintentar']) header('Retry-After: 3');
if ($comprobar || $e['http'] !== 200) descargar_json($e['http'], $resp);

$archivo = contrata_archivo_descarga($e['servicio']);
if ($archivo === null) {
    error_log('contrata descargar: falta el archivo del producto ' . (string)$e['servicio']['id']);
    descargar_json(503, ['ok' => false, 'motivo' => 'no_disponible', 'reintentar' => false, 'mensaje' => contrata_texto_descarga('no_disponible')]);
}
try {
    $n = contrata_descarga_consumir($e['sesion']);
} catch (Throwable $ex) {
    error_log('contrata descargar: no se pudo contar la descarga');
    descargar_json(503, ['ok' => false, 'motivo' => 'no_disponible', 'reintentar' => true, 'mensaje' => contrata_texto_descarga('no_disponible')]);
}
if ($n === null) {
    descargar_json(403, ['ok' => false, 'motivo' => 'limite', 'reintentar' => false, 'mensaje' => contrata_texto_descarga('limite'), 'restantes' => 0]);
}
$nombre = (string)($e['servicio']['nombre_descarga'] ?? basename($archivo));
if (preg_match('/^[A-Za-z0-9._-]{1,100}$/', $nombre) !== 1) $nombre = 'descarga.zip';
http_response_code(200);
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Content-Length: ' . (string)filesize($archivo));
header('X-Descargas-Restantes: ' . (string)max(0, CONTRATA_DESCARGAS_MAX - $n));
readfile($archivo);
