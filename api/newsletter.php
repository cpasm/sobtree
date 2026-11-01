<?php
// Suscripción a la newsletter: recibe el formulario de los artículos y lo reenvía al webhook de Zapier.
// La URL del webhook NO está en el repositorio ni en la página: va en el mismo fichero de secretos
// que la clave del asistente (domains/sobtree.com/sobtree-secrets.php, junto a public_html):
//   'ZAPIER_NEWSLETTER_WEBHOOK' => 'https://hooks.zapier.com/hooks/catch/...',
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

const LIMIT_PER_IP_HOUR = 5;
const LIMIT_PER_DAY = 300;

function reply(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ---------- Configuración (mismas rutas que asistente.php) ----------
$candidates = array_unique(array_filter([
    dirname((string)($_SERVER['DOCUMENT_ROOT'] ?? '')),
    dirname(__DIR__, 2),
    ($real = realpath(__DIR__)) ? dirname($real, 2) : null,
    getenv('HOME') ? getenv('HOME') . '/domains/sobtree.com' : null,
]));
$secrets = [];
foreach ($candidates as $base) {
    if (is_file("$base/sobtree-secrets.php")) { $secrets = (array)(require "$base/sobtree-secrets.php"); break; }
}
$webhook = trim((string)($secrets['ZAPIER_NEWSLETTER_WEBHOOK'] ?? getenv('ZAPIER_NEWSLETTER_WEBHOOK') ?: ''));
$ready = (bool)preg_match('#^https://hooks\.zapier\.com/#', $webhook);

// GET ?estado: el formulario solo se muestra si el webhook está configurado.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['estado'])) {
    reply(200, $ready ? ['ok' => true] : ['ok' => false, 'falta' => $webhook === '' ? 'webhook' : 'webhook_no_valido']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['error' => 'Método no permitido.']);
}
if (!$ready) {
    reply(503, ['error' => 'La suscripción no está disponible ahora mismo. Escríbenos a info@sobtree.com.']);
}

// ---------- Entrada ----------
$in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
if (!empty($in['web'])) reply(200, ['ok' => true]);          // trampa para bots: se descarta en silencio
$email = trim((string)($in['email'] ?? ''));
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    reply(422, ['error' => 'Revisa el email: no parece válido.']);
}
if (($in['consentimiento'] ?? '') !== 'si') {
    reply(422, ['error' => 'Necesitamos tu consentimiento para enviarte la newsletter.']);
}
$origen = mb_substr(trim((string)($in['origen'] ?? '')), 0, 300);

// ---------- Límites ----------
$dir = sys_get_temp_dir() . '/sobtree_newsletter';
if (!is_dir($dir)) @mkdir($dir, 0700, true);
function bump(string $file, int $window, int $max): bool {
    $fh = @fopen($file, 'c+');
    if (!$fh) return true;
    flock($fh, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fh), true) ?: ['t' => time(), 'n' => 0];
    if (time() - $data['t'] >= $window) $data = ['t' => time(), 'n' => 0];
    $ok = $data['n'] < $max;
    if ($ok) $data['n']++;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($data));
    flock($fh, LOCK_UN); fclose($fh);
    return $ok;
}
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . date('Y-m-d'));
if (!bump("$dir/ip_$ipHash", 3600, LIMIT_PER_IP_HOUR) || !bump("$dir/day_" . date('Y-m-d'), 86400, LIMIT_PER_DAY)) {
    reply(429, ['error' => 'Demasiados intentos. Vuelve a probar en un rato.']);
}

// ---------- Envío a Zapier ----------
$payload = json_encode([
    'email' => $email,
    'consentimiento' => true,
    'texto_consentimiento' => 'Acepto recibir la newsletter de Sobtree y la política de privacidad.',
    'origen' => $origen,
    'fecha' => gmdate('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$ch = curl_init($webhook);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
]);
curl_exec($ch);
$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($status < 200 || $status >= 300) {
    error_log("newsletter: Zapier respondió $status $err");
    reply(502, ['error' => 'No hemos podido apuntarte ahora mismo. Inténtalo de nuevo o escríbenos a info@sobtree.com.']);
}
reply(200, ['ok' => true]);
