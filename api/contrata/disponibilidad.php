<?php
// GET: semanas y plazas libres, calculadas aquí con las ventas pagadas y retenciones del registro local
// y las semanas bloqueadas de datos/bloqueos.json. Sin datos personales. Si el archivo de bloqueos
// falta o no es válido, responde fallo en cerrado: sin fechas ni escasez.
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require dirname(__DIR__, 2) . '/lib/contrata.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($method === 'GET' && isset($_GET['producto'])) {
        // Página de venta de un producto digital (sin semanas): importes y token CSRF.
        $body = contrata_estado_producto(is_string($_GET['producto']) ? $_GET['producto'] : '');
        http_response_code(200);
    } elseif ($method === 'GET') {
        $body = contrata_estado_publico();
        http_response_code(200);
    } else {
        http_response_code(405);
        $body = ['ok' => false, 'error' => 'Método no permitido.'];
    }
} catch (Throwable $e) {
    error_log('contrata disponibilidad: fallo');
    http_response_code(500);
    $body = ['ok' => false, 'fallo' => 'cerrado', 'motivo' => 'error', 'reserva' => ['href' => '/#contacto', 'texto' => CONTRATA_MSG_RESERVA]];
}
echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
