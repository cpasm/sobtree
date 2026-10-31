<?php
// Librería del botón «Contrata» (disponibilidad, retenciones y Checkout en modo test).
// La usan public/api/contrata/*.php y public/lib/reintentar-contrata.php.
//
// Secretos: solo en sobtree-secrets.php (fuera de public_html) o en el entorno.
// Nunca claves live de Stripe: se descartan en contrata_marca_live().
// Modo por defecto: apagado. «simulacion» no llama a Stripe ni envía correo real en las pruebas.
// Desde D-116: cada venta o reembolso se guarda en el registro local (cola.jsonl) y se avisa por correo
// a info@; la disponibilidad la calcula este PHP con las ventas locales y public/datos/bloqueos.json.
declare(strict_types=1);

const CONTRATA_PRECIO_BASE_EUR = 490;          // tiene que coincidir con DIAGNOSTICO.precio en src/data/site.ts
const CONTRATA_IVA_PORCIENTO = 21;
const CONTRATA_DIAS_DESCUENTO = 30;            // DIAGNOSTICO.dias
const CONTRATA_POLITICA_VERSION = '2026-10-31'; // POLITICA_VERSION en src/data/site.ts
const CONTRATA_CONDICIONES_VERSION = '2026-10-27'; // CONDICIONES_VERSION en src/data/site.ts
const CONTRATA_MAX_PROYECTOS = 1;
const CONTRATA_PLAZO_LABORABLES = 3;
const CONTRATA_SEMANAS_VISIBLES = 4;
const CONTRATA_RETENCION_SEG = 1800;
const CONTRATA_FIRMA_VENTANA = 300;
const CONTRATA_CSRF_VENTANA = 7200;
const CONTRATA_MSG_PAGO = 'No hemos podido abrir el pago; inténtalo en un minuto o escríbenos.';
const CONTRATA_MSG_RESERVA = 'Reserva tu diagnóstico; te confirmamos la fecha en 1 día laborable';
const CONTRATA_MSG_SEMANA = 'Esa semana ya está completa. Elige otra.';
const CONTRATA_PLAZO_TEXTO = 'máximo 10 días laborables';   // PLAZO_DIAGNOSTICO en src/data/site.ts
const CONTRATA_MSG_FECHA = 'La fecha de inicio se confirma por email en 1 día laborable; si no fuera posible, te ofrecemos otra semana o el reembolso íntegro. El informe del diagnóstico se entrega en un ' . CONTRATA_PLAZO_TEXTO . '.';
const CONTRATA_MSG_SIN_PLAZAS = 'No hay plazas en las próximas 4 semanas.';
const CONTRATA_PRICE_TEST = 'price_test_diagnostico';
const CONTRATA_PRODUCTO_DEFECTO = 'diagnostico';
const CONTRATA_DIAS_BLOQUEO_MAX = 5;           // la disponibilidad es por semana (lunes a viernes): un servicio no puede bloquear más
/**
 * Servicios que se pueden contratar online. Es la lista que el PHP acepta; la copia de textos del front
 * está en SERVICIOS de src/data/contrata.ts y una prueba comprueba que coinciden (id, nombre, base, días).
 * El ID de Price de cada servicio NO está aquí: viene de sobtree-secrets.php (clave env_price).
 */
const CONTRATA_SERVICIOS = [
    'diagnostico' => [
        'nombre' => 'Diagnóstico de implantación de IA',
        'base_eur' => CONTRATA_PRECIO_BASE_EUR,
        'dias_bloqueo' => 5,
        'env_price' => 'STRIPE_PRICE_DIAGNOSTICO',
    ],
];
const CONTRATA_TAX_RATE_TEST = 'txr_test_iva21';

function contrata_base_dirs(): array {
    $publicDir = dirname(__DIR__);
    return array_values(array_unique(array_filter([
        getenv('SOBTREE_BASE_DIR') ?: null,
        dirname((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: null,
        dirname($publicDir),
        ($real = realpath($publicDir)) ? dirname($real) : null,
        getenv('HOME') ? getenv('HOME') . '/domains/sobtree.com' : null,
    ], fn($d) => is_string($d) && $d !== '' && $d !== '.' && $d !== '/')));
}

/** Prefijo live, partido para que el build no publique una cadena de clave. */
function contrata_marca_live(string $tipo): string {
    return $tipo . implode('', ['_', 'l', 'i', 'v', 'e', '_']);
}

function contrata_clave_prohibida(string $key): bool {
    return str_starts_with($key, contrata_marca_live('sk')) || str_starts_with($key, contrata_marca_live('rk'));
}

function contrata_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $secrets = [];
    $baseFound = null;
    foreach (contrata_base_dirs() as $base) {
        if (is_file("$base/sobtree-secrets.php")) {
            $secrets = (array)(require "$base/sobtree-secrets.php");
            $baseFound = $base;
            break;
        }
    }
    $get = function (string $k, string $default = '') use ($secrets): string {
        if (array_key_exists($k, $secrets) && $secrets[$k] !== null && $secrets[$k] !== false) {
            return trim((string)$secrets[$k]);
        }
        $v = getenv($k);
        return trim((string)($v === false ? $default : $v));
    };
    $dir = $get('CONTRATA_DIR');
    if ($dir === '') {
        $base = $baseFound ?? (contrata_base_dirs()[0] ?? null);
        $dir = $base ? "$base/sobtree-contrata" : dirname(__DIR__) . '/_contrata';
    }
    $key = $get('STRIPE_RESTRICTED_KEY');
    $modo = $get('CONTRATA_MODO');
    if (!in_array($modo, ['simulacion', 'stripe_test', 'stripe_live'], true)) $modo = 'apagado';
    // Una clave live solo sirve en stripe_live; una clave test no sirve en stripe_live.
    $esLive = $key !== '' && contrata_clave_prohibida($key);
    $prohibida = $key !== '' && (($esLive && $modo !== 'stripe_live') || (!$esLive && $modo === 'stripe_live'));
    if ($prohibida) {
        error_log('contrata: la clave de Stripe no corresponde al modo; no se usará');
        $key = '';
    }
    $wh = $get('STRIPE_WEBHOOK_SECRET');
    if ($wh !== '' && (str_contains($wh, contrata_marca_live('sk')) || str_contains($wh, contrata_marca_live('rk')))) {
        error_log('contrata: secreto de webhook con clave live; rechazado');
        $wh = '';
        $prohibida = true;
    }
    $prices = [];
    foreach (CONTRATA_SERVICIOS as $sid => $sdef) $prices[$sid] = $get($sdef['env_price']);
    $public = rtrim($get('CONTRATA_PUBLIC_URL', 'https://sobtree.com'), '/');
    $httpLocal = (bool)preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $public);
    if (!preg_match('#^https://[a-z0-9.-]+$#i', $public) && !($modo === 'simulacion' && $httpLocal)) {
        $public = 'https://sobtree.com';
    }
    $cfg = [
        'dir' => rtrim($dir, '/'),
        'modo' => $modo,
        'stripe_key' => $key,
        'clave_prohibida' => $prohibida,
        'webhook_secret' => $wh,
        'stripe_price' => $get('STRIPE_PRICE_DIAGNOSTICO'),
        'stripe_prices' => $prices,
        'stripe_tax_rate' => $get('STRIPE_TAX_RATE_IVA21'),
        'live' => $modo === 'stripe_live',
        // Secreto del token CSRF del formulario de pago (ya no firma nada hacia fuera).
        'hmac_secret' => $get('CONTRATA_HMAC_SECRET'),
        'public_url' => $public,
        'bloqueos_file' => (string)(getenv('CONTRATA_BLOQUEOS_FILE') ?: ''),
        'notify_to' => $get('CONTRATA_NOTIFY_TO', 'info@sobtree.com'),
        'notify_from' => $get('CONTRATA_NOTIFY_FROM', 'info@sobtree.com'),
    ];
    return $cfg;
}

function contrata_modo(): string { return contrata_config()['modo']; }

/** Los eventos, sesiones y Prices de Stripe tienen que ser live solo en stripe_live. */
function contrata_livemode_esperado(): bool { return contrata_config()['live']; }

function contrata_stripe_utilizable(): bool {
    $c = contrata_config();
    if ($c['clave_prohibida']) return false;
    if ($c['modo'] === 'simulacion') return true;
    $comun = preg_match('/^txr_[A-Za-z0-9_]+$/', $c['stripe_tax_rate']) === 1;
    // Cada servicio de la lista necesita su Price; si falta alguno no se abre el pago.
    foreach (contrata_servicios() as $sid => $_) {
        if (preg_match('/^price_[A-Za-z0-9_]+$/', (string)($c['stripe_prices'][$sid] ?? '')) !== 1) $comun = false;
    }
    if ($c['modo'] === 'stripe_test') return $comun && preg_match('/^(rk|sk)_test_/', $c['stripe_key']) === 1;
    if ($c['modo'] === 'stripe_live') {
        // Para cobrar de verdad hace falta: clave live, webhook de Stripe firmado, secreto CSRF y web en https.
        return $comun && contrata_clave_prohibida($c['stripe_key'])
            && str_starts_with($c['webhook_secret'], 'whsec' . '_')
            && $c['hmac_secret'] !== ''
            && str_starts_with($c['public_url'], 'https://');
    }
    return false;
}

function contrata_ensure_dir(): string {
    $dir = contrata_config()['dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('No se puede crear la carpeta de contrata');
    }
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
    if (!is_file("$dir/index.html")) @file_put_contents("$dir/index.html", '');
    return $dir;
}

function contrata_ahora(): int {
    if (contrata_modo() === 'simulacion') {
        $h = (string)($_SERVER['HTTP_X_CONTRATA_AHORA'] ?? '');
        if ($h !== '' && ctype_digit($h)) return (int)$h;
        $e = getenv('CONTRATA_AHORA');
        if (is_string($e) && ctype_digit($e)) return (int)$e;
    }
    return time();
}

function contrata_hoy(): string {
    if (contrata_modo() === 'simulacion') {
        $h = (string)($_SERVER['HTTP_X_CONTRATA_HOY'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $h)) return $h;
        $e = getenv('CONTRATA_HOY');
        if (is_string($e) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $e)) return $e;
    }
    return (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d');
}

function contrata_fecha(string $iso): DateTimeImmutable {
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $iso, new DateTimeZone('UTC'));
    if (!$d || $d->format('Y-m-d') !== $iso) throw new InvalidArgumentException('fecha');
    return $d;
}

function contrata_iso(DateTimeImmutable $d): string { return $d->format('Y-m-d'); }

function contrata_sumar_dias(string $iso, int $n): string {
    return contrata_iso(contrata_fecha($iso)->modify(($n >= 0 ? '+' : '') . $n . ' days'));
}

function contrata_es_laborable(string $iso): bool { return (int)contrata_fecha($iso)->format('N') <= 5; }

function contrata_es_lunes(string $iso): bool { return (int)contrata_fecha($iso)->format('N') === 1; }

function contrata_sumar_laborables(string $iso, int $n): string {
    $d = $iso;
    $rest = $n;
    while ($rest > 0) {
        $d = contrata_sumar_dias($d, 1);
        if (contrata_es_laborable($d)) $rest--;
    }
    return $d;
}

function contrata_siguiente_lunes(string $iso): string {
    $n = (int)contrata_fecha($iso)->format('N');
    if ($n === 1) return $iso;
    return contrata_sumar_dias($iso, 8 - $n);
}

function contrata_primer_lunes(string $hoy, int $plazo = CONTRATA_PLAZO_LABORABLES): string {
    return contrata_siguiente_lunes(contrata_sumar_laborables($hoy, $plazo));
}

function contrata_solapa(string $aIni, string $aFin, string $bIni, string $bFin): bool {
    return $aIni < $bFin && $aFin > $bIni;
}

/** @param list<array{inicio:string,fin:string,sesion?:string}> $proyectos @param list<array{inicio:string,fin:string}> $bloqueos */
function contrata_calcular_semanas(string $hoy, array $proyectos, array $bloqueos, int $max = CONTRATA_MAX_PROYECTOS, int $plazo = CONTRATA_PLAZO_LABORABLES, int $visibles = CONTRATA_SEMANAS_VISIBLES): array {
    $primero = contrata_primer_lunes($hoy, $plazo);
    $semanas = [];
    for ($i = 0; $i < $visibles; $i++) {
        $lunes = contrata_sumar_dias($primero, $i * 7);
        $fin = contrata_sumar_dias($lunes, 5);
        $eventos = [];
        foreach ($proyectos as $p) {
            if (!isset($p['inicio'], $p['fin'])) throw new InvalidArgumentException('proyecto');
            if (contrata_solapa($p['inicio'], $p['fin'], $lunes, $fin)) $eventos[] = $p;
        }
        $bloqueada = false;
        foreach ($bloqueos as $b) {
            if (!isset($b['inicio'], $b['fin'])) throw new InvalidArgumentException('bloqueo');
            if (contrata_solapa($b['inicio'], $b['fin'], $lunes, $fin)) $bloqueada = true;
        }
        $plazas = $bloqueada ? 0 : max(0, $max - count($eventos));
        $sesiones = [];
        foreach ($eventos as $e) if (!empty($e['sesion'])) $sesiones[] = (string)$e['sesion'];
        $semanas[] = [
            'lunes' => $lunes,
            'plazas' => $plazas,
            'bloqueada' => $bloqueada,
            'proyectos' => count($eventos),
            'sesiones' => $sesiones,
        ];
    }
    return $semanas;
}

function contrata_meses(): array {
    return [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
}

function contrata_fecha_larga(string $iso, ?string $hoy = null): string {
    $hoy = $hoy ?? $iso;
    $d = contrata_fecha($iso);
    $meses = contrata_meses();
    $txt = (int)$d->format('j') . ' de ' . $meses[(int)$d->format('n')];
    if ($d->format('Y') !== contrata_fecha($hoy)->format('Y')) $txt .= ' de ' . $d->format('Y');
    return $txt;
}

function contrata_texto_plazas(int $n, string $lunes, int $max, string $hoy): ?string {
    if ($n < 1 || $n > $max) return null;
    $fecha = contrata_fecha_larga($lunes, $hoy);
    if ($n === 1) return "Queda 1 plaza para la semana del $fecha";
    return "Quedan $n plazas para la semana del $fecha";
}

function contrata_texto_proxima(string $lunes, string $hoy): string {
    return 'Nuestros agentes pueden empezar a partir del lunes ' . contrata_fecha_larga($lunes, $hoy);
}

/** Servicios de la lista con datos coherentes (id con letras minúsculas, base entera > 0, 1 a 5 días de bloqueo). */
function contrata_servicios(): array {
    $out = [];
    foreach (CONTRATA_SERVICIOS as $id => $d) {
        if (!is_string($id) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $id) !== 1) continue;
        if (!is_int($d['base_eur'] ?? null) || $d['base_eur'] < 1) continue;
        $dias = $d['dias_bloqueo'] ?? 0;
        if (!is_int($dias) || $dias < 1 || $dias > CONTRATA_DIAS_BLOQUEO_MAX) continue;
        $out[$id] = ['id' => $id] + $d;
    }
    return $out;
}

/** El servicio solo existe si su id está en la lista; cualquier otro valor (también «Diagnostico» o con espacios) da null. */
function contrata_servicio(string $id): ?array {
    return contrata_servicios()[$id] ?? null;
}

function contrata_price_de(string $servicio): string {
    return (string)(contrata_config()['stripe_prices'][$servicio] ?? '');
}

function contrata_importes(string $servicio = CONTRATA_PRODUCTO_DEFECTO): array {
    $def = contrata_servicio($servicio);
    if ($def === null) throw new InvalidArgumentException('servicio');
    $baseEur = (int)$def['base_eur'];
    $base = $baseEur * 100;
    $iva = intdiv($base * CONTRATA_IVA_PORCIENTO, 100);
    $total = $base + $iva;
    return [
        'base_eur' => $baseEur,
        'iva_porcentaje' => CONTRATA_IVA_PORCIENTO,
        'base_centimos' => $base,
        'iva_centimos' => $iva,
        'total_centimos' => $total,
        'base_texto' => contrata_euros($base),
        'iva_texto' => contrata_euros($iva),
        'total_texto' => contrata_euros($total),
    ];
}

/** Ruta de la página, partida para que el build con el flag apagado no publique el literal. */
function contrata_ruta(string $cola = ''): string {
    return '/' . 'contratar' . '/' . $cola;
}

/** Ruta de las condiciones (marcador hasta que Nexus entregue el texto aprobado). Partida, como contrata_ruta. */
function contrata_ruta_condiciones(): string {
    return '/' . 'condiciones' . '/';
}

function contrata_euros(int $centimos): string {
    $signo = $centimos < 0 ? '-' : '';
    $centimos = abs($centimos);
    $entero = number_format(intdiv($centimos, 100), 0, ',', '.');
    return $signo . $entero . ',' . str_pad((string)($centimos % 100), 2, '0', STR_PAD_LEFT) . ' €';
}

function contrata_price_coincide(int $unitAmount, string $currency, bool $livemode, string $servicio = CONTRATA_PRODUCTO_DEFECTO): bool {
    return $livemode === contrata_livemode_esperado() && strtolower($currency) === 'eur' && $unitAmount === contrata_importes($servicio)['total_centimos'];
}

function contrata_texto_descuento(): string {
    return 'Si contratas el proyecto en los ' . CONTRATA_DIAS_DESCUENTO
        . ' días siguientes a la entrega del informe, te descontamos los ' . CONTRATA_PRECIO_BASE_EUR
        . ' €. Se aplica solo a proyectos (no a formación, acompañamiento ni licencias), uno por empresa, no es acumulable y no se devuelve en dinero.';
}

function contrata_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

function contrata_append(string $file, array $row): bool {
    $line = json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($line === false) return false;
    $line .= "\n";
    $fh = @fopen($file, 'ab');
    if (!$fh) return false;
    $ok = false;
    if (flock($fh, LOCK_EX)) {
        $ok = fwrite($fh, $line) === strlen($line);
        fflush($fh);
        flock($fh, LOCK_UN);
    }
    fclose($fh);
    @chmod($file, 0600);
    return $ok;
}

function contrata_guardar_json(string $file, array $data): void {
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ($json === false) throw new RuntimeException('json');
    $tmp = $file . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json) === false) throw new RuntimeException('escritura');
    @chmod($tmp, 0600);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('rename');
    }
}

function contrata_leer_json(string $file, array $defecto): array {
    if (!is_file($file)) return $defecto;
    $j = json_decode((string)file_get_contents($file), true);
    return is_array($j) ? $j : $defecto;
}

function contrata_auditar(string $tipo, array $detalle): void {
    contrata_append(contrata_ensure_dir() . '/auditoria.jsonl', [
        'fecha' => gmdate('c'),
        'tipo' => $tipo,
        'detalle' => $detalle,
    ]);
}

function contrata_con_bloqueo(callable $fn): mixed {
    $dir = contrata_ensure_dir();
    $fh = fopen($dir . '/.estado.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX)) {
        if ($fh) fclose($fh);
        throw new RuntimeException('No se pudo bloquear el estado');
    }
    try {
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function contrata_sign(string $secret, string $timestamp, string $body): string {
    return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

function contrata_csrf_emitir(): string {
    $secret = contrata_config()['hmac_secret'];
    if ($secret === '') return '';
    $ts = (string)contrata_ahora();
    $nonce = bin2hex(random_bytes(16));
    return $ts . '.' . $nonce . '.' . contrata_sign($secret, $ts, $nonce);
}

function contrata_csrf_validar(string $token): bool {
    $secret = contrata_config()['hmac_secret'];
    if ($secret === '' || !preg_match('/^(\d+)\.([a-f0-9]{32})\.([a-f0-9]{64})$/', $token, $m)) return false;
    $esperada = contrata_sign($secret, $m[1], $m[2]);
    if (!hash_equals($esperada, $m[3])) return false;
    $edad = abs(contrata_ahora() - (int)$m[1]);
    return $edad <= CONTRATA_CSRF_VENTANA;
}

function contrata_validar_nif(string $nif): bool {
    if (!preg_match('/^\d{8}[A-Z]$/', $nif)) return false;
    $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';
    return $letras[(int)substr($nif, 0, 8) % 23] === $nif[8];
}

function contrata_validar_nie(string $nie): bool {
    if (!preg_match('/^[XYZ]\d{7}[A-Z]$/', $nie)) return false;
    $map = ['X' => '0', 'Y' => '1', 'Z' => '2'];
    $num = $map[$nie[0]] . substr($nie, 1, 7);
    $letras = 'TRWAGMYFPDXBNJZSQVHLCKE';
    return $letras[(int)$num % 23] === $nie[8];
}

function contrata_validar_cif(string $cif): bool {
    if (!preg_match('/^[ABCDEFGHJNPQRSUVW]\d{7}[0-9A-J]$/', $cif)) return false;
    $sum = 0;
    for ($i = 0; $i < 7; $i++) {
        $n = (int)$cif[$i + 1];
        if ($i % 2 === 0) {
            $n *= 2;
            $sum += intdiv($n, 10) + ($n % 10);
        } else {
            $sum += $n;
        }
    }
    $digit = (10 - ($sum % 10)) % 10;
    $letter = 'JABCDEFGHI'[$digit];
    $control = $cif[8];
    $tipo = $cif[0];
    if (str_contains('PQRSNW', $tipo)) return $control === $letter;
    if (str_contains('ABEH', $tipo)) return $control === (string)$digit;
    return $control === (string)$digit || $control === $letter;
}

function contrata_documento_es(string $doc): ?array {
    $doc = strtoupper((string)preg_replace('/[\s\-\.]/', '', $doc));
    if (contrata_validar_nif($doc)) return ['pais' => 'ES', 'tipo' => 'nif', 'normalizado' => $doc];
    if (contrata_validar_nie($doc)) return ['pais' => 'ES', 'tipo' => 'nie', 'normalizado' => $doc];
    if (contrata_validar_cif($doc)) return ['pais' => 'ES', 'tipo' => 'cif', 'normalizado' => $doc];
    return null;
}

/** Formato de NIF/CIF español o NIF-IVA de la UE. Nunca marca verificado: no consultamos VIES. */
function contrata_validar_identificacion(string $raw): ?array {
    $id = strtoupper((string)preg_replace('/[\s\-\.]/', '', $raw));
    if ($id === '') return null;
    $base = ['verificado' => false, 'alerta' => 'revisar_antes_de_facturar'];
    if (str_starts_with($id, 'ES') && strlen($id) > 2) {
        $es = contrata_documento_es(substr($id, 2));
        if (!$es) return null;
        return $base + $es + ['normalizado' => 'ES' . $es['normalizado'], 'iva_ue' => true];
    }
    $es = contrata_documento_es($id);
    if ($es) return $base + $es + ['iva_ue' => false];
    $patrones = [
        'AT' => '/^U\d{8}$/', 'BE' => '/^\d{10}$/', 'BG' => '/^\d{9,10}$/', 'CY' => '/^\d{8}[A-Z]$/',
        'CZ' => '/^\d{8,10}$/', 'DE' => '/^\d{9}$/', 'DK' => '/^\d{8}$/', 'EE' => '/^\d{9}$/',
        'EL' => '/^\d{9}$/', 'FI' => '/^\d{8}$/', 'FR' => '/^[A-Z0-9]{2}\d{9}$/', 'HR' => '/^\d{11}$/',
        'HU' => '/^\d{8}$/', 'IE' => '/^[A-Z0-9]{8,9}$/', 'IT' => '/^\d{11}$/', 'LT' => '/^\d{9}(\d{3})?$/',
        'LU' => '/^\d{8}$/', 'LV' => '/^\d{11}$/', 'MT' => '/^\d{8}$/', 'NL' => '/^\d{9}B\d{2}$/',
        'PL' => '/^\d{10}$/', 'PT' => '/^\d{9}$/', 'RO' => '/^\d{2,10}$/', 'SE' => '/^\d{12}$/',
        'SI' => '/^\d{8}$/', 'SK' => '/^\d{10}$/', 'XI' => '/^(\d{9}|\d{12}|(GD|HA)\d{3})$/',
    ];
    if (!preg_match('/^([A-Z]{2})([A-Z0-9]{2,14})$/', $id, $m) || !isset($patrones[$m[1]])) return null;
    if (!preg_match($patrones[$m[1]], $m[2])) return null;
    return $base + ['pais' => $m[1], 'tipo' => 'iva_ue', 'normalizado' => $id, 'iva_ue' => true];
}

function contrata_file_ret(): string { return contrata_ensure_dir() . '/retenciones.json'; }
function contrata_file_ses(): string { return contrata_ensure_dir() . '/sesiones.json'; }
function contrata_file_eventos(): string { return contrata_ensure_dir() . '/eventos.jsonl'; }
// Registro local de ventas y reembolsos confirmados por Stripe (una fila por evento) y estado de su aviso.
function contrata_file_cola(): string { return contrata_ensure_dir() . '/cola.jsonl'; }
function contrata_file_cola_estado(): string { return contrata_ensure_dir() . '/cola-estado.jsonl'; }

/**
 * Semanas bloqueadas: archivo del repositorio public/datos/bloqueos.json (se despliega con la web en
 * public_html/datos/, protegido con .htaccess). Lo actualiza la rutina de NEXUS cuando ve eventos BLOQUEO
 * en el calendario. CONTRATA_BLOQUEOS_FILE solo sirve para las pruebas locales.
 */
function contrata_file_bloqueos(): string {
    $f = contrata_config()['bloqueos_file'];
    return $f !== '' ? $f : dirname(__DIR__) . '/datos/bloqueos.json';
}

/**
 * Valida el contenido del archivo de bloqueos. Formato:
 *   {"bloqueos": [{"desde": "AAAA-MM-DD", "hasta": "AAAA-MM-DD", "nota": "opcional, sin datos personales"}]}
 * «hasta» es inclusive. Cualquier clave desconocida, fecha imposible, «hasta» < «desde» o nota con @ invalida
 * el archivo entero. Devuelve los bloqueos como [inicio, fin exclusivo] o null si no vale.
 * @return list<array{inicio:string,fin:string}>|null
 */
function contrata_validar_bloqueos(mixed $j): ?array {
    if (!is_array($j) || !isset($j['bloqueos']) || !is_array($j['bloqueos']) || !array_is_list($j['bloqueos'])) return null;
    foreach (array_keys($j) as $k) if (!in_array($k, ['bloqueos', 'actualizado', '_leeme'], true)) return null;
    $out = [];
    foreach ($j['bloqueos'] as $b) {
        if (!is_array($b)) return null;
        foreach (array_keys($b) as $k) if (!in_array($k, ['desde', 'hasta', 'nota'], true)) return null;
        $desde = $b['desde'] ?? null;
        $hasta = $b['hasta'] ?? null;
        if (!is_string($desde) || !is_string($hasta)) return null;
        try { contrata_fecha($desde); contrata_fecha($hasta); } catch (Throwable $e) { return null; }
        if ($hasta < $desde) return null;
        if (array_key_exists('nota', $b) && (!is_string($b['nota']) || mb_strlen($b['nota']) > 80 || str_contains($b['nota'], '@'))) return null;
        $out[] = ['inicio' => $desde, 'fin' => contrata_sumar_dias($hasta, 1)];
    }
    return $out;
}

/** Bloqueos del archivo, o null si falta o no es válido (entonces la disponibilidad falla en cerrado). */
function contrata_cargar_bloqueos(): ?array {
    $f = contrata_file_bloqueos();
    if (!is_file($f)) return null;
    $raw = @file_get_contents($f);
    if (!is_string($raw)) return null;
    return contrata_validar_bloqueos(json_decode($raw, true));
}

function contrata_leer_retenciones(): array {
    $j = contrata_leer_json(contrata_file_ret(), ['retenciones' => []]);
    if (!isset($j['retenciones']) || !is_array($j['retenciones'])) return ['retenciones' => []];
    return $j;
}

/** Una retención ocupa plaza en su semana si está pagada o retenida y sin caducar. */
function contrata_retencion_cuenta(array $r, string $semana, int $ahora): bool {
    if (($r['semana'] ?? '') !== $semana) return false;
    $estado = $r['estado'] ?? '';
    if ($estado === 'pagada') return true;
    if ($estado === 'retenida' && (int)($r['expira'] ?? 0) > $ahora) return true;
    return false;
}

/**
 * Disponibilidad calculada por el propio PHP: capacidad de cada semana (0 si un bloqueo la toca) menos las
 * ventas pagadas y las retenciones vivas del registro local. null si el archivo de bloqueos no es válido.
 * @return list<array{lunes:string,plazas:int,bloqueada:bool,ocupadas:int,libres:int}>|null
 */
function contrata_semanas_calculadas(?array $rets = null, ?int $ahora = null): ?array {
    $bloqueos = contrata_cargar_bloqueos();
    if ($bloqueos === null) return null;
    $ahora = $ahora ?? contrata_ahora();
    $rets = $rets ?? contrata_leer_retenciones()['retenciones'];
    $out = [];
    foreach (contrata_calcular_semanas(contrata_hoy(), [], $bloqueos) as $s) {
        $ocupadas = 0;
        foreach ($rets as $r) if (is_array($r) && contrata_retencion_cuenta($r, $s['lunes'], $ahora)) $ocupadas++;
        $out[] = [
            'lunes' => $s['lunes'],
            'plazas' => $s['plazas'],
            'bloqueada' => $s['bloqueada'],
            'ocupadas' => $ocupadas,
            'libres' => max(0, $s['plazas'] - $ocupadas),
        ];
    }
    return $out;
}

function contrata_estado_publico(): array {
    $cerrado = function (string $motivo): array {
        return [
            'ok' => false,
            'fallo' => 'cerrado',
            'motivo' => $motivo,
            'reserva' => ['href' => '/#contacto', 'texto' => CONTRATA_MSG_RESERVA],
        ];
    };
    $ahora = contrata_ahora();
    $calc = contrata_semanas_calculadas(null, $ahora);
    if ($calc === null) return $cerrado('bloqueos');
    $hoy = contrata_hoy();
    $semanas = [];
    $proxima = null;
    foreach ($calc as $s) {
        $libres = $s['libres'];
        $semanas[] = [
            'lunes' => $s['lunes'],
            'libres' => $libres,
            'plazas_publicadas' => $s['plazas'],
            'bloqueada' => $s['bloqueada'],
            'texto_plazas' => contrata_texto_plazas($libres, $s['lunes'], CONTRATA_MAX_PROYECTOS, $hoy),
        ];
        if ($proxima === null && $libres >= 1) {
            $proxima = ['lunes' => $s['lunes'], 'texto' => contrata_texto_proxima($s['lunes'], $hoy)];
        }
    }
    $precio = contrata_importes();
    unset($precio['base_eur']);
    $servicios = [];
    foreach (contrata_servicios() as $sid => $sdef) {
        $im = contrata_importes($sid);
        $servicios[] = [
            'id' => $sid,
            'nombre' => $sdef['nombre'],
            'base_centimos' => $im['base_centimos'],
            'total_centimos' => $im['total_centimos'],
            'total_texto' => $im['total_texto'],
            'dias_bloqueo' => (int)$sdef['dias_bloqueo'],
        ];
    }
    $out = [
        'ok' => true,
        'generado' => gmdate('c', $ahora),
        'max_proyectos_simultaneos' => CONTRATA_MAX_PROYECTOS,
        'precio' => $precio,
        'servicios' => $servicios,
        'proxima' => $proxima,
        'semanas' => $semanas,
        'csrf' => contrata_csrf_emitir(),
        'retencion_minutos' => (int)(CONTRATA_RETENCION_SEG / 60),
        'condiciones' => contrata_texto_descuento(),
        'aviso_fecha' => CONTRATA_MSG_FECHA,
    ];
    if ($proxima === null) $out['sin_plazas_texto'] = CONTRATA_MSG_SIN_PLAZAS;
    return $out;
}

function contrata_barrer(int $ahora = 0): int {
    $ahora = $ahora ?: contrata_ahora();
    return (int)contrata_con_bloqueo(function () use ($ahora) {
        $data = contrata_leer_retenciones();
        $n = 0;
        foreach ($data['retenciones'] as &$r) {
            if (($r['estado'] ?? '') === 'retenida' && (int)($r['expira'] ?? 0) <= $ahora) {
                $r['estado'] = 'liberada';
                $r['liberada'] = gmdate('c', $ahora);
                $r['motivo'] = 'caducada';
                unset($r['nif']);
                $n++;
            }
        }
        unset($r);
        if ($n > 0) contrata_guardar_json(contrata_file_ret(), $data);
        return $n;
    });
}

function contrata_origen_ok(): bool {
    $hosts = ['sobtree.com', 'www.sobtree.com'];
    $h = parse_url(contrata_config()['public_url'], PHP_URL_HOST);
    if (is_string($h) && $h !== '') $hosts[] = $h;
    if (contrata_modo() === 'simulacion') array_push($hosts, '127.0.0.1', 'localhost');
    $hosts = array_unique($hosts);
    $origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    $referer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    $candidato = $origin !== '' ? $origin : $referer;
    if ($candidato !== '') {
        $host = parse_url($candidato, PHP_URL_HOST);
        return is_string($host) && in_array($host, $hosts, true);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return contrata_modo() === 'simulacion' && in_array($ip, ['127.0.0.1', '::1'], true);
}

function contrata_rate_ok(string $ip): bool {
    if (contrata_modo() === 'simulacion' && getenv('CONTRATA_SIN_LIMITE') === '1') return true;
    $lock = sys_get_temp_dir() . '/sobtree_contrata_' . hash('sha256', $ip);
    if (is_file($lock) && (time() - filemtime($lock)) < 10) return false;
    @touch($lock);
    return true;
}

/** @return array{http:int,body:array} */
function contrata_abrir_sesion(array $in, array $ctx = []): array {
    if (!empty($in['web'])) return ['http' => 200, 'body' => ['ok' => true]];
    if (!empty($in['_too_big'])) return ['http' => 413, 'body' => ['ok' => false, 'error' => 'Cuerpo demasiado grande.']];
    $cfg = contrata_config();
    if ($cfg['clave_prohibida'] || !contrata_stripe_utilizable()) {
        return ['http' => 503, 'body' => ['ok' => false, 'error' => CONTRATA_MSG_PAGO, 'motivo' => 'pago_no_disponible']];
    }
    if (empty($ctx['cli']) && !contrata_origen_ok()) {
        return ['http' => 403, 'body' => ['ok' => false, 'error' => 'Origen no permitido.']];
    }
    if (!contrata_csrf_validar((string)($in['csrf'] ?? ''))) {
        return ['http' => 400, 'body' => ['ok' => false, 'error' => 'La sesión del formulario ha caducado. Vuelve a cargar la página.']];
    }
    $producto = (string)($in['producto'] ?? '');
    $servicio = contrata_servicio($producto);
    if ($servicio === null) {
        return ['http' => 422, 'body' => ['ok' => false, 'error' => 'Ese producto no se puede contratar online.']];
    }
    $empresa = trim((string)($in['empresa'] ?? ''));
    $empresa = str_replace(["\r", "\n", "\0"], ' ', $empresa);
    if ($empresa === '' || mb_strlen($empresa) > 200) {
        return ['http' => 422, 'body' => ['ok' => false, 'error' => 'Indica el nombre de la empresa.']];
    }
    $email = mb_strtolower(trim((string)($in['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        return ['http' => 422, 'body' => ['ok' => false, 'error' => 'El email no es válido.']];
    }
    $nif = contrata_validar_identificacion((string)($in['nif'] ?? ''));
    if ($nif === null) {
        return ['http' => 422, 'body' => ['ok' => false, 'error' => 'El NIF o CIF no tiene un formato válido. Solo vendemos a empresas y autónomos.']];
    }
    if (($nif['pais'] ?? '') !== 'ES') {
        return ['http' => 422, 'body' => ['ok' => false, 'motivo' => 'fuera_de_espana', 'error' => 'Por ahora solo cobramos online a empresas y autónomos con NIF o CIF español. Si tu empresa está en otro país, escríbenos a info@sobtree.com y te preparamos la factura sin IVA español cuando corresponda.']];
    }
    if (($in['condiciones'] ?? '') !== 'si') {
        return ['http' => 422, 'body' => ['ok' => false, 'error' => 'Hay que aceptar las condiciones y la política de privacidad.']];
    }
    $semana = (string)($in['semana'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $semana) || !contrata_es_lunes($semana)) {
        return ['http' => 422, 'body' => ['ok' => false, 'error' => 'Elige un lunes de inicio.']];
    }
    $estado = contrata_estado_publico();
    if (($estado['ok'] ?? false) !== true) {
        return ['http' => 409, 'body' => ['ok' => false, 'fallo' => 'cerrado', 'error' => CONTRATA_MSG_RESERVA]];
    }
    $fila = null;
    foreach ($estado['semanas'] as $s) if ($s['lunes'] === $semana) $fila = $s;
    if ($fila === null) return ['http' => 409, 'body' => ['ok' => false, 'error' => 'Esa semana no está disponible.']];
    if ((int)$fila['libres'] < 1) return ['http' => 409, 'body' => ['ok' => false, 'error' => CONTRATA_MSG_SEMANA, 'motivo' => 'semana_completa']];
    $ip = (string)($ctx['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'cli'));
    if (!contrata_rate_ok($ip)) {
        return ['http' => 429, 'body' => ['ok' => false, 'error' => 'Acabas de abrir un pago. Espera unos segundos.']];
    }

    $utm = [];
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
        $v = trim((string)($in[$k] ?? ''));
        $v = str_replace(["\r", "\n", "\0"], '', $v);
        if ($v !== '') $utm[$k] = mb_substr($v, 0, 150);
    }
    $leadId = trim((string)($in['lead_id'] ?? ''));
    if ($leadId !== '' && !preg_match('/^[A-Za-z0-9_-]{1,64}$/', $leadId)) $leadId = '';

    $ret = null;
    try {
        $ret = contrata_con_bloqueo(function () use ($semana, $producto) {
            $ahora = contrata_ahora();
            $data = contrata_leer_retenciones();
            foreach ($data['retenciones'] as &$r) {
                if (($r['estado'] ?? '') === 'retenida' && (int)$r['expira'] <= $ahora) {
                    $r['estado'] = 'liberada';
                    $r['motivo'] = 'caducada';
                    unset($r['nif']);
                }
            }
            unset($r);
            $calc = contrata_semanas_calculadas($data['retenciones'], $ahora);
            if ($calc === null) return null;
            $fila = null;
            foreach ($calc as $s) if ($s['lunes'] === $semana) $fila = $s;
            if ($fila === null) return ['fuera' => true];
            if ($fila['libres'] < 1) {
                contrata_guardar_json(contrata_file_ret(), $data);
                return ['completa' => true];
            }
            $id = 'ret_' . bin2hex(random_bytes(8));
            $expira = $ahora + CONTRATA_RETENCION_SEG;
            $data['retenciones'][] = [
                'id' => $id,
                'semana' => $semana,
                'expira' => $expira,
                'estado' => 'retenida',
                'sesion' => '',
                'servicio' => $producto,
                'creada' => gmdate('c', $ahora),
            ];
            contrata_guardar_json(contrata_file_ret(), $data);
            return ['id' => $id, 'semana' => $semana, 'expira' => $expira, 'servicio' => $producto];
        });
    } catch (Throwable $e) {
        error_log('contrata retener: ' . $e->getMessage());
        return ['http' => 503, 'body' => ['ok' => false, 'error' => CONTRATA_MSG_PAGO]];
    }
    if (!is_array($ret) || isset($ret['completa']) || isset($ret['fuera']) || $ret === null) {
        $msg = (is_array($ret) && isset($ret['completa'])) ? CONTRATA_MSG_SEMANA : (($ret === null) ? CONTRATA_MSG_RESERVA : 'Esa semana no está disponible.');
        $code = ($ret === null) ? 409 : 409;
        $body = ['ok' => false, 'error' => $msg];
        if (is_array($ret) && isset($ret['completa'])) $body['motivo'] = 'semana_completa';
        if ($ret === null) $body['fallo'] = 'cerrado';
        return ['http' => $code, 'body' => $body];
    }

    $cliente = ['email' => $email, 'empresa' => $empresa, 'nif' => $nif, 'utm' => $utm, 'lead_id' => $leadId];
    try {
        $sesion = contrata_stripe_crear_sesion($ret, $cliente);
    } catch (Throwable $e) {
        error_log('contrata stripe: ' . $e->getMessage());
        contrata_marcar_retencion($ret['id'], ['estado' => 'liberada', 'motivo' => 'stripe_error', 'liberada' => gmdate('c'), 'nif' => null]);
        return ['http' => 502, 'body' => ['ok' => false, 'error' => CONTRATA_MSG_PAGO]];
    }
    // El NIF se guarda solo en la retención (fuera de la web, 0600) hasta que llega el pago; ahí se usa y se borra.
    contrata_marcar_retencion($ret['id'], ['sesion' => $sesion['id'], 'nif' => contrata_nif_normal((string)$nif['normalizado'])]);
    $imp = contrata_importes($producto);
    return ['http' => 200, 'body' => [
        'ok' => true,
        'servicio' => $producto,
        'url' => $sesion['url'],
        'sesion' => $sesion['id'],
        'expira' => $ret['expira'],
        'total_centimos' => $imp['total_centimos'],
        'total_texto' => $imp['total_texto'],
    ]];
}

function contrata_marcar_retencion(string $id, array $cambios): void {
    contrata_con_bloqueo(function () use ($id, $cambios) {
        $data = contrata_leer_retenciones();
        foreach ($data['retenciones'] as &$r) {
            if (($r['id'] ?? '') === $id) {
                foreach ($cambios as $k => $v) $r[$k] = $v;
            }
        }
        unset($r);
        contrata_guardar_json(contrata_file_ret(), $data);
    });
}

/** NIF/CIF sin el prefijo ES y sin separadores, que es como lo guarda Stripe en un tax_id es_cif. */
function contrata_nif_normal(string $v): string {
    $v = strtoupper((string)preg_replace('/[^A-Za-z0-9]/', '', $v));
    return str_starts_with($v, 'ES') && strlen($v) === 11 ? substr($v, 2) : $v;
}

/**
 * Parámetros de Checkout. Cliente creado antes (con su NIF como tax_id), dirección de facturación obligatoria,
 * IVA 21 % incluido en el Price (tax rate manual inclusive) y factura automática tras el pago.
 * $nifEnFactura: solo si Stripe no aceptó el NIF como tax_id; entonces va como campo personalizado de la factura.
 */
function contrata_parametros_sesion(array $ret, array $cliente, string $customerId = '', bool $nifEnFactura = false): array {
    $servicioId = (string)($ret['servicio'] ?? CONTRATA_PRODUCTO_DEFECTO);
    $servicio = contrata_servicio($servicioId);
    if ($servicio === null) throw new RuntimeException('servicio');
    $imp = contrata_importes($servicioId);
    $cfg = contrata_config();
    $price = contrata_price_de($servicioId);
    if ($price === '') {
        if (contrata_modo() !== 'simulacion') throw new RuntimeException('sin price');
        $price = CONTRATA_PRICE_TEST;
    }
    if (!preg_match('/^price_[A-Za-z0-9_]+$/', $price)) throw new RuntimeException('price');
    $tax = $cfg['stripe_tax_rate'];
    if ($tax === '') {
        if (contrata_modo() !== 'simulacion') throw new RuntimeException('sin tax rate');
        $tax = CONTRATA_TAX_RATE_TEST;
    }
    if (!preg_match('/^txr_[A-Za-z0-9_]+$/', $tax)) throw new RuntimeException('tax rate');
    if ($customerId === '') {
        if (contrata_modo() !== 'simulacion') throw new RuntimeException('sin cliente');
        $customerId = 'cus_test_simulado';
    }
    $semanaTxt = 'lunes ' . contrata_fecha_larga($ret['semana'], contrata_hoy());
    $mensaje = 'Semana de inicio: ' . $semanaTxt . '. '
        . contrata_texto_descuento() . ' Total a pagar: ' . $imp['total_texto']
        . ' (' . $imp['base_texto'] . ' + IVA ' . CONTRATA_IVA_PORCIENTO . ' %: ' . $imp['iva_texto'] . '). '
        . CONTRATA_MSG_FECHA;
    if (mb_strlen($mensaje) > 1100) $mensaje = mb_substr($mensaje, 0, 1100);
    $meta = [
        'producto' => $servicioId,
        'semana_lunes' => $ret['semana'],
        'retencion_id' => $ret['id'],
        'politica_version' => CONTRATA_POLITICA_VERSION,
        'condiciones_version' => CONTRATA_CONDICIONES_VERSION,
        'importe_base_centimos' => (string)$imp['base_centimos'],
        'iva_porcentaje' => (string)CONTRATA_IVA_PORCIENTO,
        'importe_total_centimos' => (string)$imp['total_centimos'],
        'price_id' => $price,
        'nif_pais' => $cliente['nif']['pais'],
        'nif_tipo' => $cliente['nif']['tipo'],
        'nif_verificado' => '0',
        'empresa' => mb_substr($cliente['empresa'], 0, 200),
    ];
    foreach ($cliente['utm'] as $k => $v) $meta[$k] = $v;
    if ($cliente['lead_id'] !== '') $meta['lead_id'] = $cliente['lead_id'];
    $base = $cfg['public_url'];
    $datosFactura = [
        'description' => $servicio['nombre'] . '. Semana de inicio: ' . $semanaTxt . '.',
        'metadata' => ['retencion_id' => $ret['id'], 'semana_lunes' => $ret['semana'], 'producto' => $servicioId],
    ];
    if ($nifEnFactura) {
        $datosFactura['custom_fields'] = [['name' => 'NIF/CIF', 'value' => contrata_nif_normal((string)$cliente['nif']['normalizado'])]];
    }
    return [
        'mode' => 'payment',
        'line_items' => [[
            'price' => $price,
            'quantity' => 1,
            'tax_rates' => [$tax],
        ]],
        'expires_at' => $ret['expira'],
        'success_url' => $base . contrata_ruta('gracias/?sesion={CHECKOUT_SESSION_ID}'),
        'cancel_url' => $base . contrata_ruta('?cancelado=1'),
        'customer' => $customerId,
        'customer_update' => ['address' => 'auto'],
        'billing_address_collection' => 'required',
        'invoice_creation' => ['enabled' => 'true', 'invoice_data' => $datosFactura],
        'locale' => 'es',
        'consent_collection' => ['terms_of_service' => 'required'],
        'custom_text' => [
            'submit' => ['message' => $mensaje],
            // La casilla de Stripe enlaza nuestras condiciones. La URL de condiciones también va en Dashboard → Ajustes → Datos públicos.
            'terms_of_service_acceptance' => ['message' => 'Acepto las [condiciones de contratación](' . $base . contrata_ruta_condiciones() . ') y la [política de privacidad](' . $base . '/privacidad/) (versión ' . CONTRATA_POLITICA_VERSION . ').'],
        ],
        'metadata' => $meta,
        'payment_method_types' => ['card', 'link'],
    ];
}

function contrata_stripe_crear_sesion(array $ret, array $cliente): array {
    $servicioId = (string)($ret['servicio'] ?? CONTRATA_PRODUCTO_DEFECTO);
    $imp = contrata_importes($servicioId);
    $live = contrata_livemode_esperado();
    if (contrata_modo() === 'simulacion') {
        $params = contrata_parametros_sesion($ret, $cliente);
        if (!contrata_price_coincide($imp['total_centimos'], 'eur', false, $servicioId)) {
            throw new RuntimeException('importe');
        }
        $id = 'cs_test_' . bin2hex(random_bytes(12));
        $url = contrata_config()['public_url'] . '/api/contrata/sim/checkout.php?sesion=' . rawurlencode($id);
        if (!contrata_url_pago_permitida($url)) throw new RuntimeException('url');
        $sesion = [
            'id' => $id,
            'livemode' => false,
            'currency' => 'eur',
            'amount_total' => $imp['total_centimos'],
            'status' => 'open',
            'url' => $url,
            'params' => $params,
            'payment_intent' => 'pi_test_' . bin2hex(random_bytes(10)),
            'cliente' => [
                'email' => $cliente['email'],
                'empresa' => $cliente['empresa'],
                'nif' => $cliente['nif'],
            ],
            'retencion_id' => $ret['id'],
            'creada' => contrata_ahora(),
        ];
        contrata_con_bloqueo(function () use ($id, $sesion) {
            $all = contrata_leer_json(contrata_file_ses(), ['sesiones' => []]);
            $all['sesiones'][$id] = $sesion;
            contrata_guardar_json(contrata_file_ses(), $all);
        });
        return ['id' => $id, 'url' => $url];
    }
    $cfg = contrata_config();
    $price = contrata_stripe_leer_price(contrata_price_de($servicioId));
    if (!contrata_price_coincide((int)($price['unit_amount'] ?? 0), (string)($price['currency'] ?? ''), (bool)($price['livemode'] ?? false), $servicioId)) {
        throw new RuntimeException('El Price de Stripe no coincide con el servicio (base + IVA) o su modo (test/live) no es el configurado.');
    }
    $tr = contrata_stripe_request('GET', '/v1/tax_rates/' . rawurlencode($cfg['stripe_tax_rate']));
    if (!(abs((float)($tr['percentage'] ?? 0) - CONTRATA_IVA_PORCIENTO) < 0.0001 && ($tr['inclusive'] ?? null) === true
        && ($tr['active'] ?? null) === true && (bool)($tr['livemode'] ?? false) === $live)) {
        throw new RuntimeException('El tax rate de Stripe no es el IVA 21 % incluido, activo y del modo configurado.');
    }
    // 1) Cliente con su NIF como tax_id. Si Stripe no lo acepta, el cliente se crea sin él y el NIF va en la factura.
    $idem = 'contrata-' . $ret['id'];
    $formCliente = [
        'name' => $cliente['empresa'],
        'email' => $cliente['email'],
        'metadata' => ['origen' => 'contrata', 'retencion_id' => $ret['id']],
    ];
    $nifEnFactura = false;
    try {
        $cus = contrata_stripe_request('POST', '/v1/customers', contrata_form_aplanar($formCliente + [
            'tax_id_data' => [['type' => 'es_cif', 'value' => contrata_nif_normal((string)$cliente['nif']['normalizado'])]],
        ]), $idem . '-cus');
    } catch (RuntimeException $e) {
        if ($e->getMessage() !== 'stripe_http_400') throw $e;
        $nifEnFactura = true;
        $cus = contrata_stripe_request('POST', '/v1/customers', contrata_form_aplanar($formCliente), $idem . '-cus-sin-nif');
        contrata_auditar('nif_sin_tax_id', ['retencion' => $ret['id']]);
    }
    $customerId = (string)($cus['id'] ?? '');
    if (!preg_match('/^cus_[A-Za-z0-9_]+$/', $customerId) || (bool)($cus['livemode'] ?? false) !== $live) {
        throw new RuntimeException('cliente de Stripe no aceptado');
    }
    // 2) Sesión de Checkout.
    $params = contrata_parametros_sesion($ret, $cliente, $customerId, $nifEnFactura);
    $resp = contrata_stripe_request('POST', '/v1/checkout/sessions', contrata_form_aplanar($params), $idem . '-ses');
    if ((bool)($resp['livemode'] ?? false) !== $live) throw new RuntimeException('modo de la sesión no coincide');
    $url = (string)($resp['url'] ?? '');
    $id = (string)($resp['id'] ?? '');
    $prefijo = $live ? 'cs_' . 'live_' : 'cs_test_';
    if (!str_starts_with($id, $prefijo) || !contrata_url_pago_permitida($url)) {
        throw new RuntimeException('respuesta de Stripe no aceptada');
    }
    return ['id' => $id, 'url' => $url];
}

function contrata_url_pago_permitida(string $url): bool {
    $p = parse_url($url);
    if (!is_array($p) || empty($p['host'])) return false;
    if (contrata_modo() === 'simulacion') {
        $base = parse_url(contrata_config()['public_url']);
        $path = $p['path'] ?? '';
        return ($p['host'] === ($base['host'] ?? ''))
            && (int)($p['port'] ?? ($p['scheme'] === 'https' ? 443 : 80)) === (int)($base['port'] ?? ($base['scheme'] === 'https' ? 443 : 80))
            && $path === '/api/contrata/sim/checkout.php'
            && str_contains((string)($p['query'] ?? ''), 'sesion=cs_test_');
    }
    return ($p['scheme'] ?? '') === 'https' && strtolower((string)$p['host']) === 'checkout.stripe.com';
}

function contrata_form_aplanar(array $data, string $prefix = ''): array {
    $out = [];
    foreach ($data as $k => $v) {
        $key = $prefix === '' ? (string)$k : $prefix . '[' . $k . ']';
        if (is_array($v)) $out += contrata_form_aplanar($v, $key);
        else $out[$key] = (string)$v;
    }
    return $out;
}

function contrata_stripe_leer_price(string $priceId): array {
    return contrata_stripe_request('GET', '/v1/prices/' . rawurlencode($priceId));
}

function contrata_stripe_request(string $method, string $path, array $form = [], string $idempotencia = ''): array {
    $c = contrata_config();
    $reClave = $c['live'] ? '/^(rk|sk)_' . 'live_/' : '/^(rk|sk)_test_/';
    if ($c['clave_prohibida'] || !in_array($c['modo'], ['stripe_test', 'stripe_live'], true) || !preg_match($reClave, $c['stripe_key'])) {
        throw new RuntimeException('clave de Stripe no utilizable');
    }
    if (!function_exists('curl_init')) throw new RuntimeException('curl');
    $baseEnv = getenv('CONTRATA_STRIPE_API_BASE');
    $base = 'https://api.stripe.com';
    if (is_string($baseEnv) && $baseEnv !== '') {
        // Solo pruebas: Stripe simulado en 127.0.0.1 con CONTRATA_STRIPE_API_LOCAL=1. Si la variable existe pero no
        // vale, se falla en cerrado: nunca se cae a la API real por una configuración de prueba incompleta.
        if (preg_match('#^https://api\.stripe\.com$#', $baseEnv)) $base = $baseEnv;
        elseif (getenv('CONTRATA_STRIPE_API_LOCAL') === '1' && preg_match('#^http://127\.0\.0\.1:\d+$#', $baseEnv)) $base = $baseEnv;
        else throw new RuntimeException('stripe_api_base');
    }
    $ch = curl_init($base . $path);
    $cabeceras = [
        'Authorization: Bearer ' . $c['stripe_key'],
        'Content-Type: application/x-www-form-urlencoded',
    ];
    if ($idempotencia !== '' && $method === 'POST') $cabeceras[] = 'Idempotency-Key: ' . substr($idempotencia, 0, 200);
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $cabeceras,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ];
    if ($method !== 'GET') $opts[CURLOPT_POSTFIELDS] = http_build_query($form);
    curl_setopt_array($ch, $opts);
    $resp = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($resp) || $status < 200 || $status >= 300) {
        throw new RuntimeException($err !== '' ? 'stripe_red' : 'stripe_http_' . $status);
    }
    $json = json_decode($resp, true);
    if (!is_array($json)) throw new RuntimeException('stripe_json');
    return $json;
}

function contrata_verificar_firma_stripe(string $payload, string $header, string $secret): array {
    if ($secret === '' || str_contains($secret, contrata_marca_live('sk')) || str_contains($secret, contrata_marca_live('rk'))) {
        throw new RuntimeException('secreto');
    }
    $t = '';
    $v1 = [];
    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if (str_starts_with($part, 't=')) $t = substr($part, 2);
        elseif (str_starts_with($part, 'v1=')) $v1[] = substr($part, 3);
    }
    if (!preg_match('/^\d+$/', $t) || abs(contrata_ahora() - (int)$t) > CONTRATA_FIRMA_VENTANA) {
        throw new RuntimeException('timestamp');
    }
    $esperada = hash_hmac('sha256', $t . '.' . $payload, $secret);
    $ok = false;
    foreach ($v1 as $firma) {
        if (strlen($firma) === strlen($esperada) && hash_equals($esperada, $firma)) $ok = true;
    }
    if (!$ok) throw new RuntimeException('firma');
    $event = json_decode($payload, true);
    if (!is_array($event) || !isset($event['id'], $event['type']) || !is_string($event['id'])) {
        throw new RuntimeException('evento');
    }
    return $event;
}

/** ¿Ya se procesó (y registró) este evento de Stripe? */
function contrata_evento_visto(string $id): bool {
    $f = contrata_file_eventos();
    if (!is_file($f)) return false;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (is_array($row) && ($row['id'] ?? '') === $id) return true;
    }
    return false;
}

/**
 * Webhook de Stripe. Ningún cobro sin registro: si no se puede guardar la venta, el reembolso, la retención o
 * la marca del evento, lanza una excepción y stripe-webhook.php responde 500 para que Stripe lo reintente.
 * El evento solo se marca como procesado DESPUÉS de registrarlo; la cola deduplica por event_id.
 * El correo a info@ se envía al final y, si falla, lo reintenta el cron (no hace fallar el webhook).
 * @return array{http:int,body:array}
 */
function contrata_recibir_webhook(string $raw, string $header): array {
    try {
        $event = contrata_verificar_firma_stripe($raw, $header, contrata_config()['webhook_secret']);
    } catch (Throwable $e) {
        contrata_auditar('webhook_rechazado', ['motivo' => 'firma']);
        return ['http' => 400, 'body' => ['ok' => false, 'error' => 'Firma no válida.']];
    }
    if ((bool)($event['livemode'] ?? false) !== contrata_livemode_esperado()) {
        // Un evento de otro modo (test en live o live en test) se acusa sin tocar nada.
        contrata_auditar('webhook_modo_distinto', ['tipo' => (string)($event['type'] ?? '')]);
        return ['http' => 200, 'body' => ['ok' => true, 'ignorado' => 'livemode']];
    }
    $eventId = (string)$event['id'];
    $tipo = (string)$event['type'];
    $obj = $event['data']['object'] ?? [];
    if (!is_array($obj)) $obj = [];
    $aviso = null;
    // Un webhook a la vez (fichero de bloqueo propio: los aplicar_* usan el bloqueo de estado).
    $fh = fopen(contrata_ensure_dir() . '/.webhook.lock', 'c');
    if (!$fh || !flock($fh, LOCK_EX)) {
        if ($fh) fclose($fh);
        throw new RuntimeException('bloqueo_webhook');
    }
    try {
        if (contrata_evento_visto($eventId)) {
            return ['http' => 200, 'body' => ['ok' => true, 'duplicado' => true]];
        }
        if ($tipo === 'checkout.session.completed') {
            $aviso = contrata_aplicar_pago($obj, $eventId);
        } elseif ($tipo === 'checkout.session.expired') {
            contrata_aplicar_expiracion($obj);
        } elseif ($tipo === 'charge.refunded') {
            $aviso = contrata_aplicar_reembolso($obj, $eventId);
        } else {
            contrata_auditar('webhook_ignorado', ['tipo' => $tipo]);
        }
        if (!contrata_append(contrata_file_eventos(), ['id' => $eventId, 'fecha' => gmdate('c')])) {
            throw new RuntimeException('registro_eventos');
        }
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    if ($aviso !== null) {
        try { contrata_entregar($aviso); } catch (Throwable $e) { error_log('contrata: aviso pendiente (lo reintenta el cron)'); }
    }
    return ['http' => 200, 'body' => ['ok' => true]];
}

function contrata_meta(array $obj): array {
    $m = $obj['metadata'] ?? [];
    return is_array($m) ? $m : [];
}

/** Alerta de territorio con otro régimen de IVA (Canarias, Ceuta y Melilla) o dirección fuera de España. */
function contrata_alerta_territorio(array $detalles): string {
    $dir = is_array($detalles['address'] ?? null) ? $detalles['address'] : [];
    $pais = strtoupper((string)($dir['country'] ?? ''));
    if ($pais === '') return '';
    if ($pais !== 'ES') return 'direccion_fuera_de_espana';
    $cp = (string)($dir['postal_code'] ?? '');
    if (preg_match('/^(35|38|51|52)\d{3}$/', $cp)) return 'territorio_sin_iva';
    return '';
}

/** Retención que corresponde a un objeto de Stripe (por retención, sesión o payment_intent). */
function contrata_retencion_de(string $retId, string $sesion, string $pi = ''): ?array {
    foreach (contrata_leer_retenciones()['retenciones'] as $r) {
        if (!is_array($r)) continue;
        if (($retId !== '' && ($r['id'] ?? '') === $retId) || ($sesion !== '' && ($r['sesion'] ?? '') === $sesion)
            || ($pi !== '' && ($r['payment_intent'] ?? '') === $pi)) return $r;
    }
    return null;
}

/** Pago confirmado: 1) registro de la venta, 2) retención pagada. Devuelve la fila registrada (o null si no hay pago). */
function contrata_aplicar_pago(array $obj, string $eventId): ?array {
    $meta = contrata_meta($obj);
    $retId = (string)($meta['retencion_id'] ?? '');
    $sesion = (string)($obj['id'] ?? '');
    if (($obj['payment_status'] ?? '') !== 'paid') {
        // Solo un pago confirmado crea venta, plaza y aviso.
        contrata_auditar('pago_no_confirmado', ['estado' => (string)($obj['payment_status'] ?? '')]);
        return null;
    }
    $pi = (string)($obj['payment_intent'] ?? '');
    $ret = contrata_retencion_de($retId, $sesion);
    $fuera = $ret !== null && ($ret['estado'] ?? '') === 'liberada';
    $nifGuardado = ($ret !== null && is_string($ret['nif'] ?? null)) ? $ret['nif'] : '';
    $servicioId = (string)($meta['producto'] ?? CONTRATA_PRODUCTO_DEFECTO);
    $servicio = contrata_servicio($servicioId);
    $imp = contrata_importes($servicio !== null ? $servicioId : CONTRATA_PRODUCTO_DEFECTO);
    $detalles = is_array($obj['customer_details'] ?? null) ? $obj['customer_details'] : [];
    // NIF: el de la retención (lo que validó la web) y el tax_id que guarda Stripe tienen que coincidir.
    $nifStripe = '';
    foreach ((is_array($detalles['tax_ids'] ?? null) ? $detalles['tax_ids'] : []) as $t) {
        if (is_array($t) && ($t['type'] ?? '') === 'es_cif' && is_string($t['value'] ?? null)) { $nifStripe = contrata_nif_normal($t['value']); break; }
    }
    $nif = $nifGuardado !== '' ? contrata_nif_normal($nifGuardado) : $nifStripe;
    $coincide = ($nifGuardado !== '' && $nifStripe !== '') ? ($nifStripe === contrata_nif_normal($nifGuardado)) : null;
    $alerta = '';
    if ($servicio === null) {
        $alerta = 'servicio_desconocido';
    } elseif ((int)($obj['amount_total'] ?? $imp['total_centimos']) !== $imp['total_centimos'] || strtolower((string)($obj['currency'] ?? 'eur')) !== 'eur') {
        $alerta = 'importe_inesperado';
    } elseif ($coincide === false) {
        $alerta = 'nif_distinto';
    } elseif (($obj['consent']['terms_of_service'] ?? '') !== 'accepted') {
        $alerta = 'condiciones_sin_aceptar';
    } elseif ($nif === '') {
        $alerta = 'nif_ausente';
    } else {
        $alerta = contrata_alerta_territorio($detalles);
    }
    if ($alerta !== '') contrata_auditar('pago_con_alerta', ['alerta' => $alerta]);
    $email = (string)($detalles['email'] ?? $obj['customer_email'] ?? '');
    // 1) Registro de la venta. Si falla, lanza y Stripe reintenta.
    $row = contrata_registrar([
        'tipo' => 'checkout.session.completed',
        'event_id' => $eventId,
        'cuerpo' => [
            'sesion' => $sesion,
            'retencion_id' => $retId,
            'producto' => $servicio !== null ? $servicioId : '',
            'servicio_nombre' => $servicio !== null ? (string)$servicio['nombre'] : '',
            'dias_bloqueo' => $servicio !== null ? (int)$servicio['dias_bloqueo'] : CONTRATA_DIAS_BLOQUEO_MAX,
            'semana_lunes' => (string)($meta['semana_lunes'] ?? ($ret['semana'] ?? '')),
            'importe_base_centimos' => $imp['base_centimos'],
            'iva_porcentaje' => CONTRATA_IVA_PORCIENTO,
            'importe_total_centimos' => (int)($obj['amount_total'] ?? $imp['total_centimos']),
            'empresa' => (string)($meta['empresa'] ?? ''),
            'email' => $email,
            'nif' => $nif,
            'nif_coincide' => $coincide,
            'nif_pais' => (string)($meta['nif_pais'] ?? ''),
            'nif_tipo' => (string)($meta['nif_tipo'] ?? ''),
            'nif_verificado' => false,
            'condiciones_aceptadas' => ($obj['consent']['terms_of_service'] ?? '') === 'accepted',
            'alerta_facturacion' => $alerta,
            'factura' => (string)($obj['invoice'] ?? ''),
            'payment_intent' => $pi,
            'politica_version' => (string)($meta['politica_version'] ?? ''),
            'lead_id' => (string)($meta['lead_id'] ?? ''),
            'fuera_de_orden' => $fuera,
            'livemode' => contrata_livemode_esperado(),
        ],
    ]);
    // 2) La retención pasa a pagada (ocupa la plaza) y deja de guardar el NIF (ya está en la venta).
    contrata_con_bloqueo(function () use ($retId, $sesion, $pi) {
        $data = contrata_leer_retenciones();
        $tocada = false;
        foreach ($data['retenciones'] as &$r) {
            $match = ($retId !== '' && ($r['id'] ?? '') === $retId) || ($sesion !== '' && ($r['sesion'] ?? '') === $sesion);
            if (!$match) continue;
            if (($r['estado'] ?? '') !== 'reembolsada') $r['estado'] = 'pagada';
            if ($sesion !== '') $r['sesion'] = $sesion;
            if ($pi !== '') $r['payment_intent'] = $pi;
            $r['pagada'] = gmdate('c');
            unset($r['nif']);
            $tocada = true;
        }
        unset($r);
        if ($tocada) contrata_guardar_json(contrata_file_ret(), $data);
    });
    if (contrata_modo() === 'simulacion' && $sesion !== '') {
        contrata_con_bloqueo(function () use ($sesion) {
            $all = contrata_leer_json(contrata_file_ses(), ['sesiones' => []]);
            if (isset($all['sesiones'][$sesion])) $all['sesiones'][$sesion]['status'] = 'paid';
            contrata_guardar_json(contrata_file_ses(), $all);
        });
    }
    return $row;
}

function contrata_aplicar_expiracion(array $obj): void {
    $meta = contrata_meta($obj);
    $retId = (string)($meta['retencion_id'] ?? '');
    $sesion = (string)($obj['id'] ?? '');
    contrata_con_bloqueo(function () use ($retId, $sesion) {
        $data = contrata_leer_retenciones();
        foreach ($data['retenciones'] as &$r) {
            $match = ($retId !== '' && ($r['id'] ?? '') === $retId) || ($sesion !== '' && ($r['sesion'] ?? '') === $sesion);
            if (!$match) continue;
            if (($r['estado'] ?? '') === 'retenida') {
                $r['estado'] = 'liberada';
                $r['motivo'] = 'checkout_expired';
                $r['liberada'] = gmdate('c');
                unset($r['nif']);
            }
        }
        unset($r);
        contrata_guardar_json(contrata_file_ret(), $data);
    });
    contrata_auditar('expiracion', ['sesion' => $sesion !== '' ? 'presente' : 'ausente']);
}

/** Reembolso: 1) registro, 2) si es total, la retención pasa a reembolsada y libera la plaza. */
function contrata_aplicar_reembolso(array $obj, string $eventId): array {
    $meta = contrata_meta($obj);
    $sesion = (string)($meta['checkout_session_id'] ?? '');
    $retId = (string)($meta['retencion_id'] ?? '');
    $pi = (string)($obj['payment_intent'] ?? '');
    $amount = (int)($obj['amount'] ?? 0);
    $devuelto = (int)($obj['amount_refunded'] ?? $amount);
    // Un reembolso parcial no libera la plaza ni cierra la venta.
    $total = array_key_exists('refunded', $obj) ? ($obj['refunded'] === true) : ($amount === 0 || $devuelto >= $amount);
    $semana = (string)($meta['semana_lunes'] ?? '');
    $ret = contrata_retencion_de($retId, $sesion, $pi);
    $servicioRet = '';
    if ($ret !== null) {
        $servicioRet = (string)($ret['servicio'] ?? '');
        if ($retId === '') $retId = (string)($ret['id'] ?? '');
        if ($sesion === '') $sesion = (string)($ret['sesion'] ?? '');
        if ($semana === '') $semana = (string)($ret['semana'] ?? '');
    } else {
        contrata_auditar('reembolso_sin_retencion', ['pi' => $pi !== '' ? 'presente' : 'ausente']);
    }
    $row = contrata_registrar([
        'tipo' => 'charge.refunded',
        'event_id' => $eventId,
        'cuerpo' => [
            'sesion' => $sesion,
            'retencion_id' => $retId,
            'semana_lunes' => $semana,
            'producto' => contrata_servicio($servicioRet) !== null ? $servicioRet : CONTRATA_PRODUCTO_DEFECTO,
            'estado' => $total ? 'reembolsada' : 'reembolso_parcial',
            'importe_reembolsado_centimos' => $devuelto,
            'importe_cobrado_centimos' => $amount,
            'payment_intent' => $pi,
            'retencion_encontrada' => $ret !== null,
            'livemode' => contrata_livemode_esperado(),
        ],
    ]);
    if ($total && $ret !== null) {
        contrata_con_bloqueo(function () use ($retId, $sesion, $pi) {
            $data = contrata_leer_retenciones();
            foreach ($data['retenciones'] as &$r) {
                $match = ($retId !== '' && ($r['id'] ?? '') === $retId) || ($sesion !== '' && ($r['sesion'] ?? '') === $sesion)
                    || ($pi !== '' && ($r['payment_intent'] ?? '') === $pi);
                if (!$match) continue;
                $r['estado'] = 'reembolsada';
                $r['reembolsada'] = gmdate('c');
            }
            unset($r);
            contrata_guardar_json(contrata_file_ret(), $data);
        });
    }
    return $row;
}

/**
 * Registro local (cola.jsonl) de una venta o reembolso. Idempotente por event_id: si Stripe reintenta, devuelve
 * la fila que ya existe. Si no puede escribir, lanza (el webhook responde 500 y Stripe reintenta).
 */
function contrata_registrar(array $evento): array {
    return contrata_con_bloqueo(function () use ($evento) {
        $f = contrata_file_cola();
        if (is_file($f)) {
            foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $row = json_decode($line, true);
                if (is_array($row) && ($row['event_id'] ?? '') === $evento['event_id']) return $row;
            }
        }
        $row = [
            'id' => contrata_uuid(),
            'fecha' => gmdate('c'),
            'tipo' => $evento['tipo'],
            'event_id' => $evento['event_id'],
            'cuerpo' => $evento['cuerpo'],
        ];
        if (!contrata_append($f, $row)) {
            error_log('contrata: no se pudo registrar el evento ' . $evento['event_id']);
            throw new RuntimeException('registro');
        }
        return $row;
    });
}

/**
 * Aviso por correo de una fila del registro. Marca correo_ok / correo_error en cola-estado.jsonl.
 * Si esa fila ya tiene correo_ok (p. ej. el cron lo envió y luego Stripe reintentó el evento tras un 500), no repite.
 */
function contrata_entregar(array $row): array {
    [, $state] = contrata_cargar_cola();
    if (in_array($state[$row['id']]['ultimo'] ?? null, ['correo_ok', 'enviado'], true)) return ['correo_ok', 'ya_enviado'];
    $ok = contrata_enviar_aviso($row);
    $estado = $ok ? 'correo_ok' : 'correo_error';
    contrata_marcar_cola($row['id'], $estado, $ok ? '' : 'mail_fallo');
    if (!$ok) error_log('contrata: falló el aviso por correo de ' . $row['id'] . ' (lo reintenta el cron)');
    return [$estado, ''];
}

function contrata_marcar_cola(string $id, string $estado, string $detalle = ''): void {
    contrata_append(contrata_file_cola_estado(), [
        'id' => $id,
        'estado' => $estado,
        'detalle' => mb_substr($detalle, 0, 300),
        'fecha' => gmdate('c'),
    ]);
    if ($estado === 'correo_ok') contrata_purgar_nif_cola($id);
}

/** Enviado el aviso (que lleva el NIF para facturar), el NIF deja de guardarse en el registro. */
function contrata_purgar_nif_cola(string $id): void {
    contrata_con_bloqueo(function () use ($id) {
        $f = contrata_file_cola();
        if (!is_file($f)) return;
        $out = [];
        $cambio = false;
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && ($row['id'] ?? '') === $id && isset($row['cuerpo']['nif']) && $row['cuerpo']['nif'] !== '') {
                $row['cuerpo']['nif'] = '';
                $line = (string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $cambio = true;
            }
            $out[] = $line;
        }
        if ($cambio) {
            $tmp = $f . '.tmp';
            if (@file_put_contents($tmp, implode("\n", $out) . "\n", LOCK_EX) !== false) {
                @chmod($tmp, 0600);
                @rename($tmp, $f);
            }
        }
    });
}

/**
 * Correo con los ganchos de prueba. En producción usa mail(), igual que el aviso de leads.
 * Solo pruebas: CONTRATA_DISABLE_MAIL=1 (o LEADS_DISABLE_MAIL=1) no envía y, con CONTRATA_TEST_MAIL_LOG,
 * escribe una línea JSON por correo; CONTRATA_TEST_MAIL_FAIL_FILE (si existe) hace fallar el envío.
 */
function contrata_mail(string $tipo, string $subject, string $body, string $replyTo = ''): bool {
    $c = contrata_config();
    $failFile = getenv('CONTRATA_TEST_MAIL_FAIL_FILE');
    if ($failFile && is_file($failFile)) return false;
    if (getenv('CONTRATA_DISABLE_MAIL') === '1' || getenv('LEADS_DISABLE_MAIL') === '1') {
        if ($log = getenv('CONTRATA_TEST_MAIL_LOG')) {
            @file_put_contents($log, json_encode(['tipo' => $tipo, 'to' => $c['notify_to'], 'subject' => $subject, 'body' => $body],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        }
        return true;
    }
    $subj = preg_match('/^[\x20-\x7E]*$/', $subject) ? $subject : '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $h = "From: Sobtree Web <{$c['notify_from']}>\r\n";
    $replyTo = str_replace(["\r", "\n"], '', $replyTo);
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) $h .= "Reply-To: $replyTo\r\n";
    $h .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    return @mail($c['notify_to'], $subj, $body, $h);
}

/** Texto de cada alerta para el correo. */
function contrata_texto_alerta(string $a): string {
    return [
        'servicio_desconocido' => 'el servicio pagado no está en la lista de la web: revisar antes de confirmar',
        'importe_inesperado' => 'el importe cobrado no es el del servicio: revisar en Stripe',
        'nif_distinto' => 'el NIF validado en la web no coincide con el que guarda Stripe: revisar antes de facturar',
        'condiciones_sin_aceptar' => 'Stripe no marca las condiciones como aceptadas',
        'nif_ausente' => 'no hay NIF: pedirlo antes de facturar',
        'territorio_sin_iva' => 'dirección en Canarias, Ceuta o Melilla: revisar el IVA de la factura',
        'direccion_fuera_de_espana' => 'dirección de facturación fuera de España: revisar el IVA de la factura',
    ][$a] ?? $a;
}

/** Asunto y cuerpo del aviso de una venta o reembolso. @return array{0:string,1:string,2:string} */
function contrata_correo_evento(array $row): array {
    $c = is_array($row['cuerpo'] ?? null) ? $row['cuerpo'] : [];
    $one = fn($s): string => str_replace(["\r", "\n"], ' ', (string)$s);
    $v = fn($s): string => ($s = trim($one($s))) !== '' ? $s : '—';
    $sesion = trim($one($c['sesion'] ?? ''));
    $ref = $sesion !== '' ? $sesion : 'sin-sesion-' . $one($row['event_id'] ?? '');
    $lunes = (string)($c['semana_lunes'] ?? '');
    $valida = preg_match('/^\d{4}-\d{2}-\d{2}$/', $lunes) === 1;
    $alertas = [];
    if (($row['tipo'] ?? '') === 'charge.refunded') {
        $total = ($c['estado'] ?? '') === 'reembolsada';
        $b  = ($total ? 'Reembolso TOTAL' : 'Reembolso PARCIAL') . " confirmado por Stripe.\n\n";
        $b .= 'Sesión:            ' . $v($sesion) . "\n";
        $b .= 'Estado:            ' . $v($c['estado'] ?? '') . "\n";
        $b .= 'Reembolsado:       ' . contrata_euros((int)($c['importe_reembolsado_centimos'] ?? 0)) . "\n";
        if ((int)($c['importe_cobrado_centimos'] ?? 0) > 0) $b .= 'Cobrado:           ' . contrata_euros((int)$c['importe_cobrado_centimos']) . "\n";
        $b .= 'Servicio:          ' . $v($c['producto'] ?? '') . "\n";
        $b .= 'Semana de inicio:  ' . $v($lunes) . "\n";
        $b .= 'Payment intent:    ' . $v($c['payment_intent'] ?? '') . "\n";
        $b .= 'Retención:         ' . $v($c['retencion_id'] ?? '') . "\n";
        if (($c['retencion_encontrada'] ?? true) === false) $alertas[] = 'reembolso_sin_retencion: no se encontró la venta en el registro local; revisar a mano';
        if ($total) $alertas[] = 'plaza liberada en la web' . ($valida ? " (semana del $lunes)" : '');
        $rutina = $total
            ? 'Para la rutina: cancelar en Google Calendar (info@) el evento CONTRATA de esta sesión' . ($valida ? " (semana del $lunes)" : '') . '.'
            : 'Para la rutina: reembolso parcial; el evento CONTRATA se mantiene.';
        $subject = '[Sobtree] Reembolso ' . $ref;
        $replyTo = '';
    } else {
        $dias = max(1, min(CONTRATA_DIAS_BLOQUEO_MAX, (int)($c['dias_bloqueo'] ?? CONTRATA_DIAS_BLOQUEO_MAX)));
        $fin = $valida ? contrata_sumar_dias($lunes, $dias - 1) : '';
        $coincide = $c['nif_coincide'] ?? null;
        $b  = "Venta confirmada por Stripe (pago cobrado).\n\n";
        $b .= 'Sesión:            ' . $v($sesion) . "\n";
        $b .= 'Servicio:          ' . $v($c['servicio_nombre'] ?? '') . ' (' . $v($c['producto'] ?? '') . ")\n";
        $b .= 'Semana de inicio:  ' . $v($lunes) . ($valida ? " (lunes; bloquea $dias días laborables, hasta $fin)" : '') . "\n";
        $b .= 'Importe cobrado:   ' . contrata_euros((int)($c['importe_total_centimos'] ?? 0)) . ' (base ' . contrata_euros((int)($c['importe_base_centimos'] ?? 0)) . ' + IVA ' . (int)($c['iva_porcentaje'] ?? CONTRATA_IVA_PORCIENTO) . " %)\n";
        $b .= 'Empresa:           ' . $v($c['empresa'] ?? '') . "\n";
        $b .= 'Email:             ' . $v($c['email'] ?? '') . "\n";
        $b .= 'NIF/CIF:           ' . $v($c['nif'] ?? '') . ' (' . $v($c['nif_tipo'] ?? '') . ', ' . $v($c['nif_pais'] ?? '') . ", formato validado, no verificado en VIES)\n";
        $b .= 'NIF igual a Stripe: ' . ($coincide === true ? 'sí' : ($coincide === false ? 'NO' : 'sin dato')) . "\n";
        $b .= 'Condiciones:       ' . ((($c['condiciones_aceptadas'] ?? false) === true) ? 'aceptadas' : 'NO aceptadas') . ' (política ' . $v($c['politica_version'] ?? '') . ")\n";
        $b .= 'Factura Stripe:    ' . $v($c['factura'] ?? '') . "\n";
        $b .= 'Payment intent:    ' . $v($c['payment_intent'] ?? '') . "\n";
        $b .= 'Lead:              ' . $v($c['lead_id'] ?? '') . "\n";
        $b .= 'Retención:         ' . $v($c['retencion_id'] ?? '') . "\n";
        $a = (string)($c['alerta_facturacion'] ?? '');
        if ($a !== '') $alertas[] = "$a: " . contrata_texto_alerta($a);
        if (($c['fuera_de_orden'] ?? false) === true) $alertas[] = 'fuera_de_orden: el pago llegó con la retención ya caducada; comprobar que la semana no está dada a otro cliente';
        $rutina = 'Para la rutina: crear en Google Calendar (info@) el evento «CONTRATA ' . $v($c['empresa'] ?? '') . ' · ' . $v($sesion) . '»'
            . ($valida ? " del $lunes al $fin" : ' (semana sin dato: revisar)') . '.';
        $subject = '[Sobtree] Venta ' . $ref;
        $replyTo = (string)($c['email'] ?? '');
    }
    $b .= 'Modo:              ' . ((($c['livemode'] ?? false) === true) ? 'live (real)' : 'test') . "\n";
    $b .= 'Evento Stripe:     ' . $v($row['event_id'] ?? '') . "\n";
    $b .= 'Registro local:    ' . $v($row['id'] ?? '') . ' (' . $v($row['fecha'] ?? '') . ")\n\n";
    $b .= "ALERTAS:\n" . ($alertas ? implode("\n", array_map(fn($x) => '- ' . $x, $alertas)) : '- ninguna') . "\n\n";
    $b .= $rutina . "\n";
    return [$subject, $b, $replyTo];
}

function contrata_enviar_aviso(array $row): bool {
    [$subject, $body, $replyTo] = contrata_correo_evento($row);
    return contrata_mail(($row['tipo'] ?? '') === 'charge.refunded' ? 'reembolso' : 'venta', $subject, $body, $replyTo);
}

function contrata_cargar_cola(): array {
    $items = [];
    $f = contrata_file_cola();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['id'])) $items[$row['id']] = $row;
        }
    }
    $state = [];
    $s = contrata_file_cola_estado();
    if (is_file($s)) {
        foreach (file($s, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (!is_array($row) || !isset($row['id'])) continue;
            $state[$row['id']]['ultimo'] = $row['estado'];
            if (in_array($row['estado'] ?? '', ['error', 'correo_error'], true)) $state[$row['id']]['intentos'] = ($state[$row['id']]['intentos'] ?? 0) + 1;
        }
    }
    return [$items, $state];
}

/**
 * Reintenta los avisos por correo que no salieron (últimos 30 días). «enviado» es el estado antiguo de las
 * filas que ya entregó la integración anterior a D-116: también cuenta como hecho y no se repite.
 */
function contrata_reintentar_cola(bool $dry = false): array {
    $stats = ['revisados' => 0, 'enviados' => 0, 'errores' => 0];
    if (!is_file(contrata_file_cola())) return $stats;
    [$items, $state] = contrata_cargar_cola();
    $limite = time() - 30 * 86400;
    foreach ($items as $id => $row) {
        $prev = $state[$id]['ultimo'] ?? null;
        if (in_array($prev, ['correo_ok', 'enviado'], true)) continue;
        $ts = strtotime((string)($row['fecha'] ?? ''));
        if ($ts !== false && $ts < $limite) continue;
        // Recién registrada y sin estado: el webhook puede estar enviando su correo ahora mismo.
        if ($prev === null && $ts !== false && $ts > time() - 300) continue;
        $stats['revisados']++;
        if ($dry) continue;
        [$estado] = contrata_entregar($row);
        $stats[$estado === 'correo_ok' ? 'enviados' : 'errores']++;
    }
    return $stats;
}

function contrata_enviar_alerta(string $asunto, string $texto): bool {
    return contrata_mail('alerta', '[Sobtree] ' . $asunto, $texto . "\n");
}

/** Si el archivo de bloqueos falta o no es válido, la web no ofrece fechas: avisa a info@ como mucho una vez al día. */
function contrata_alertar_bloqueos(): bool {
    if (contrata_cargar_bloqueos() !== null) return false;
    $marker = contrata_ensure_dir() . '/alerta-bloqueos';
    if (is_file($marker) && (time() - filemtime($marker)) < 86400) return false;
    $ok = contrata_enviar_alerta('Contrata: archivo de bloqueos no válido',
        'El archivo de semanas bloqueadas (public/datos/bloqueos.json) falta o no es válido. Mientras tanto la web no muestra fechas ni abre el pago (el botón lleva al formulario de contacto). Corrige el archivo en el repositorio y vuelve a desplegar.');
    if ($ok) @touch($marker);
    return $ok;
}

function contrata_mantenimiento(bool $dry = false): array {
    $stats = ['revisados' => 0, 'enviados' => 0, 'errores' => 0, 'liberadas' => 0, 'alerta' => 0];
    $cfg = contrata_config();
    $dir = $cfg['dir'];
    $hay = is_file($dir . '/cola.jsonl') || is_file($dir . '/retenciones.json');
    if ($cfg['modo'] === 'apagado' && !$hay) return $stats;
    if ($dry) return $stats;
    try {
        $stats['liberadas'] = contrata_barrer();
        $re = contrata_reintentar_cola(false);
        foreach (['revisados', 'enviados', 'errores'] as $k) $stats[$k] = $re[$k];
        if ($cfg['modo'] !== 'apagado') $stats['alerta'] = contrata_alertar_bloqueos() ? 1 : 0;
    } catch (Throwable $e) {
        error_log('contrata mantenimiento: ' . $e->getMessage());
    }
    return $stats;
}

function contrata_leer_sesion_sim(string $id): ?array {
    if (!preg_match('/^cs_test_[A-Za-z0-9_]+$/', $id)) return null;
    $all = contrata_leer_json(contrata_file_ses(), ['sesiones' => []]);
    $s = $all['sesiones'][$id] ?? null;
    return is_array($s) ? $s : null;
}

function contrata_firmar_evento_stripe(array $event): array {
    $payload = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($payload === false) throw new RuntimeException('json');
    $t = (string)contrata_ahora();
    $secret = contrata_config()['webhook_secret'];
    if ($secret === '') throw new RuntimeException('sin secreto de webhook');
    $sig = hash_hmac('sha256', $t . '.' . $payload, $secret);
    return [$payload, 't=' . $t . ',v1=' . $sig];
}

/**
 * Evento de prueba firmado, sin procesarlo. $eventId fijo sirve para reenviar el mismo aviso.
 * Solo en simulación.
 * @return array{0:string,1:string} payload y cabecera Stripe-Signature
 */
function contrata_evento_firmado_sim(string $sesionId, string $tipo, array $extra = [], ?string $eventId = null): array {
    if (contrata_modo() !== 'simulacion') throw new RuntimeException('no simulacion');
    $ses = contrata_leer_sesion_sim($sesionId);
    if (!$ses) throw new RuntimeException('sesion');
    $meta = $ses['params']['metadata'];
    $meta['checkout_session_id'] = $sesionId;
    if ($tipo === 'checkout.session.completed' || $tipo === 'checkout.session.expired') {
        $obj = [
            'id' => $sesionId,
            'object' => 'checkout.session',
            'payment_status' => $tipo === 'checkout.session.completed' ? 'paid' : 'unpaid',
            'mode' => 'payment',
            'livemode' => false,
            'currency' => 'eur',
            'amount_total' => $ses['amount_total'],
            'customer_email' => null,
            'customer_details' => [
                'email' => $ses['cliente']['email'],
                'name' => $ses['cliente']['empresa'],
                'address' => ['country' => 'ES', 'postal_code' => '28001'],
                'tax_ids' => [['type' => 'es_cif', 'value' => contrata_nif_normal((string)$ses['cliente']['nif']['normalizado'])]],
            ],
            'payment_intent' => $ses['payment_intent'] ?? null,
            'consent' => ['terms_of_service' => 'accepted'],
            'invoice' => 'in_test_' . substr(md5($sesionId), 0, 12),
            'metadata' => $meta,
        ];
    } else {
        unset($meta['checkout_session_id']);
        $obj = [
            'id' => 'ch_test_' . bin2hex(random_bytes(6)),
            'object' => 'charge',
            'livemode' => false,
            'payment_intent' => $ses['payment_intent'] ?? null,
            'amount' => $ses['amount_total'],
            'amount_refunded' => $ses['amount_total'],
            'refunded' => true,
            'metadata' => [],
        ];
    }
    foreach ($extra as $k => $v) $obj[$k] = $v;
    $event = [
        'id' => $eventId ?: ('evt_test_' . bin2hex(random_bytes(8))),
        'object' => 'event',
        'type' => $tipo,
        'livemode' => false,
        'data' => ['object' => $obj],
    ];
    return contrata_firmar_evento_stripe($event);
}

/** Construye y aplica un evento firmado de la sesión simulada. Solo en simulación. */
function contrata_sim_evento(string $sesionId, string $tipo, array $extra = []): array {
    [$payload, $header] = contrata_evento_firmado_sim($sesionId, $tipo, $extra);
    return contrata_recibir_webhook($payload, $header);
}
