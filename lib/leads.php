<?php
// Librería de captura de leads de sobtree.com (la usan contact.php y reintentar-leads.php).
//
// Principio: el lead se GUARDA PRIMERO en un registro local (JSONL, con bloqueo de escritura) y solo
// después se avisa por correo a info@sobtree.com. El registro local + ese correo son el registro canónico
// de contactos (la hoja «Sobtree · Contactos» ya no se escribe sola: queda como histórico).
//
// Después del aviso, la respuesta automática E-02 (aprobada, D-115) contesta UNA vez al visitante del
// formulario de contacto, solo en horario laboral; fuera de horario queda en cola para el cron.
//
// Configuración (nunca en el repositorio): en domains/sobtree.com/sobtree-secrets.php
// (fuera de public_html; ver config/sobtree-secrets.example.php) o en variables de entorno:
//   LEADS_DIR                 carpeta del registro (por defecto domains/sobtree.com/sobtree-leads)
//   LEADS_NOTIFY_TO           destinatario del aviso por correo (por defecto info@sobtree.com)
//   LEADS_NOTIFY_FROM         remitente del aviso y de E-02 (por defecto info@sobtree.com)
//   LEADS_AUTORESPUESTA       '0' apaga la respuesta automática E-02 (interruptor de emergencia)
declare(strict_types=1);

const LEADS_SCHEMA_VERSION = 1;
// Versión publicada en /privacidad/ (POLITICA_VERSION en src/data/site.ts). Va con el lead.
const LEADS_POLITICA_VERSION = '2026-11-01';
// Texto exacto de la casilla opcional (COMERCIAL_CONSENT_TEXT en src/data/site.ts). Sin el enlace.
const LEADS_COMERCIAL_TEXTO = 'Quiero recibir comunicaciones comerciales de Sobtree (novedades, análisis y ofertas) por email. Puedes darte de baja cuando quieras.';
// Asunto estable del aviso a info@ (la rutina de NEXUS lo busca en Gmail): «[Sobtree] Nuevo contacto <id>».
const LEADS_ASUNTO_AVISO = '[Sobtree] Nuevo contacto ';

// ---- E-02 · respuesta automática (especificación: docs/borradores/forge/2026-10-31-e02-respuesta-automatica.md)
const LEADS_E02_CAMPANIA = 'E-02';
const LEADS_E02_TIPOS = ['contacto'];       // ni calculadora ni asistente
const LEADS_E02_ZONA = 'Europe/Madrid';
const LEADS_E02_HORA_INICIO = 9;            // 09:00 incluida
const LEADS_E02_HORA_FIN = 19;              // 19:00 excluida
// Días laborables (L-V) en los que no se envía. Equivocarse solo retrasa la respuesta al siguiente día hábil.
// - Nacionales de la especificación E-02: 2026-12-08, 2026-12-25, 2027-01-01, 2027-01-06.
// - Comunidad de Madrid y Madrid capital: PENDIENTES DE VERIFICAR en el BOCM (calendario laboral autonómico)
//   y en el acuerdo municipal; no se pudo consultar BOE/BOCM desde el entorno de construcción. Son las fechas
//   habituales: 2-nov (traslado de Todos los Santos en domingo), 9-nov (La Almudena, local), 7-dic (traslado de
//   la Constitución en domingo), Jueves y Viernes Santo 2027, 12-oct, 1-nov, 9-nov, 6-dic y 8-dic de 2027.
const LEADS_E02_FESTIVOS = [
    '2026-11-02', '2026-11-09', '2026-12-07', '2026-12-08', '2026-12-25',
    '2027-01-01', '2027-01-06', '2027-03-25', '2027-03-26', '2027-10-12', '2027-11-01', '2027-11-09',
    '2027-12-06', '2027-12-08',
];
const LEADS_E02_MAX_POR_CRON = 5;           // freno por ejecución del cron
const LEADS_E02_MAX_POR_HORA = 20;          // freno global (contact.php + cron) en la última hora
const LEADS_E02_DIAS_IDEMPOTENCIA = 7;      // una sola respuesta por email en 7 días
const LEADS_E02_MAX_DIAS = 7;               // un lead con más de 7 días ya no se responde
const LEADS_E02_MAX_ERRORES = 3;            // tras 3 fallos de envío se deja de intentar
const LEADS_E02_ASUNTO = 'Hemos recibido tu mensaje · Sobtree';
// Dominios de correo desechable conocidos (se comparan con el dominio y sus subdominios).
const LEADS_E02_DESECHABLES = [
    'mailinator.com', 'guerrillamail.com', 'guerrillamail.net', 'guerrillamail.org', 'guerrillamailblock.com',
    'sharklasers.com', 'grr.la', '10minutemail.com', '10minutemail.net', 'yopmail.com', 'yopmail.fr', 'yopmail.net',
    'tempmail.com', 'temp-mail.org', 'temp-mail.io', 'tempmailo.com', 'tempr.email', 'trashmail.com', 'trashmail.de',
    'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc', 'throwawaymail.com', 'fakeinbox.com',
    'mintemail.com', 'emailondeck.com', 'mohmal.com', 'mailnesia.com', 'spamgourmet.com', 'discard.email',
    'moakt.com', 'mailcatch.com', 'mytemp.email', 'burnermail.io', 'spambox.us', 'getairmail.com', 'inboxkitten.com',
    'notlettersmail.com',
];

/** Carpeta base candidata a domains/sobtree.com (misma lógica que asistente.php). */
function leads_base_dirs(): array {
    $publicDir = dirname(__DIR__); // public_html
    return array_values(array_unique(array_filter([
        getenv('SOBTREE_BASE_DIR') ?: null,
        dirname((string)($_SERVER['DOCUMENT_ROOT'] ?? '')) ?: null,
        dirname($publicDir),
        ($real = realpath($publicDir)) ? dirname($real) : null,
        getenv('HOME') ? getenv('HOME') . '/domains/sobtree.com' : null,
    ], fn($d) => is_string($d) && $d !== '' && $d !== '.' && $d !== '/')));
}

/** Lee sobtree-secrets.php (si existe) y completa con variables de entorno. */
function leads_config(): array {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $secrets = [];
    $baseFound = null;
    foreach (leads_base_dirs() as $base) {
        if (is_file("$base/sobtree-secrets.php")) {
            $secrets = (array)(require "$base/sobtree-secrets.php");
            $baseFound = $base;
            break;
        }
    }
    $get = function (string $k, string $default = '') use ($secrets): string {
        $v = $secrets[$k] ?? getenv($k);
        return trim((string)($v === false || $v === null ? $default : $v));
    };
    $dir = $get('LEADS_DIR');
    if ($dir === '') {
        $base = $baseFound ?? (leads_base_dirs()[0] ?? null);
        $dir = $base ? "$base/sobtree-leads" : dirname(__DIR__) . '/_leads';
    }
    $cfg = [
        'dir' => rtrim($dir, '/'),
        'notify_to' => $get('LEADS_NOTIFY_TO', 'info@sobtree.com'),
        'notify_from' => $get('LEADS_NOTIFY_FROM', 'info@sobtree.com'),
        'autorespuesta' => $get('LEADS_AUTORESPUESTA', '1') !== '0',
    ];
    return $cfg;
}

/** Crea la carpeta del registro y la protege por si quedara dentro del webroot. */
function leads_ensure_dir(): string {
    $dir = leads_config()['dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException("No se puede crear la carpeta de leads: $dir");
    }
    if (!is_file("$dir/.htaccess")) @file_put_contents("$dir/.htaccess", "Require all denied\n");
    if (!is_file("$dir/index.html")) @file_put_contents("$dir/index.html", '');
    return $dir;
}

/** Añade una línea JSON a un fichero con bloqueo exclusivo. Devuelve false si no se pudo escribir. */
function leads_append(string $file, array $row): bool {
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

function leads_file(): string { return leads_ensure_dir() . '/leads.jsonl'; }
function leads_status_file(): string { return leads_ensure_dir() . '/estado.jsonl'; }

function leads_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Guarda el lead. Es lo PRIMERO que se hace, antes de cualquier envío. */
function leads_save(array $lead): bool {
    return leads_append(leads_file(), $lead);
}

/**
 * Registra un cambio de estado (append-only) en estado.jsonl.
 *   canal «correo» (aviso a info@): correo_ok | correo_error
 *   canal «e02» (respuesta automática): pendiente | enviando | enviado | error | descartado
 */
function leads_mark(string $id, string $canal, string $estado, string $detalle = ''): bool {
    return leads_append(leads_status_file(), [
        'id' => $id, 'canal' => $canal, 'estado' => $estado,
        'detalle' => mb_substr($detalle, 0, 300), 'fecha' => gmdate('c', leads_ahora()),
    ]);
}

/** Hora actual (epoch). LEADS_AHORA solo existe en las pruebas locales; en producción no se define. */
function leads_ahora(): int {
    $e = getenv('LEADS_AHORA');
    return (is_string($e) && ctype_digit($e)) ? (int)$e : time();
}

/**
 * Campo de formulario como booleano. Ausente lo resuelve quien llama.
 * true: 1, true, si, sí, yes, on. false: vacío, 0, false, no, off. Cualquier otro valor → null.
 */
function leads_parse_bool(mixed $value): ?bool {
    if (is_bool($value)) return $value;
    if (is_int($value)) return $value === 0 ? false : ($value === 1 ? true : null);
    if (!is_string($value)) return null;
    $s = strtolower(trim($value));
    if ($s === '' || in_array($s, ['0', 'false', 'no', 'off'], true)) return false;
    if (in_array($s, ['1', 'true', 'si', 'sí', 'yes', 'on'], true)) return true;
    return null;
}

/**
 * Casilla opcional de comunicaciones comerciales, lista para guardar con el lead.
 * $acepta true solo si el visitante la marcó. La fecha es ISO 8601 (UTC).
 */
function leads_consentimiento_comercial(bool $acepta): array {
    return [
        'acepta' => $acepta,
        'fecha' => gmdate('c'),
        'texto' => $acepta ? LEADS_COMERCIAL_TEXTO : '',
        'version_politica' => LEADS_POLITICA_VERSION,
    ];
}

/**
 * Envío de correo con los ganchos de prueba. En producción usa mail() (la misma función de siempre).
 * Ganchos SOLO para pruebas locales (tests/leads); en producción estas variables no existen:
 *   LEADS_TEST_MAIL_FAIL_FILE  si el fichero existe, el envío falla
 *   LEADS_DISABLE_MAIL=1       no envía; si LEADS_TEST_MAIL_LOG está, escribe una línea JSON por correo
 */
function leads_mail(string $tipo, string $id, string $to, string $subject, string $body, string $headers): bool {
    $failFile = getenv('LEADS_TEST_MAIL_FAIL_FILE');
    if ($failFile && is_file($failFile)) return false;
    if (getenv('LEADS_DISABLE_MAIL') === '1') {
        if ($log = getenv('LEADS_TEST_MAIL_LOG')) {
            @file_put_contents($log, json_encode([
                'tipo' => $tipo, 'id' => $id, 'to' => $to, 'subject' => $subject, 'body' => $body, 'headers' => $headers,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
        }
        return true;
    }
    return @mail($to, $subject, $body, $headers);
}

/** Asunto MIME: ASCII tal cual (para que se pueda buscar literal) y UTF-8 en base64 si hace falta. */
function leads_asunto_mime(string $s): string {
    return preg_match('/^[\x20-\x7E]*$/', $s) ? $s : '=?UTF-8?B?' . base64_encode($s) . '?=';
}

/**
 * Aviso a info@sobtree.com con todos los campos útiles. Es el aviso PRINCIPAL y el registro por correo (D-116).
 * $nota: texto opcional (p. ej. «sin_registro_local» cuando el correo es la única copia).
 */
function leads_send_mail(array $lead, string $nota = ''): bool {
    $c = leads_config();
    $one = fn($s): string => str_replace(["\r", "\n"], ' ', (string)$s);
    $v = fn($s): string => ($s = trim((string)$s)) !== '' ? $s : '—';
    $tipo = (string)($lead['tipo_formulario'] ?? '');
    $o = is_array($lead['origen'] ?? null) ? $lead['origen'] : [];
    $utm = is_array($o['utm'] ?? null) ? $o['utm'] : [];
    $subject = LEADS_ASUNTO_AVISO . $one($lead['id']);
    $b  = "Nueva solicitud desde la web\n";
    if ($nota === 'sin_registro_local') $b .= "ATENCIÓN: no se pudo guardar en el registro local del servidor. Este correo es la única copia del lead.\n";
    $b .= "\n";
    $b .= 'ID:          ' . $lead['id'] . "\n";
    $b .= 'Fecha:       ' . $v($lead['fecha'] ?? '') . "\n";
    $b .= 'Formulario:  ' . $v($tipo) . "\n";
    $b .= 'Nombre:      ' . $v($lead['nombre'] ?? '') . "\n";
    $b .= 'Cargo:       ' . $v($lead['cargo'] ?? '') . "\n";
    $b .= 'Teléfono:    ' . $v($lead['telefono'] ?? '') . "\n";
    $b .= 'Empresa:     ' . $v($lead['empresa'] ?? '') . "\n";
    $b .= 'Email:       ' . $v($lead['email'] ?? '') . "\n";
    $b .= 'Tamaño:      ' . $v($lead['tamano'] ?? '') . "\n";
    $b .= 'Interés:     ' . $v($lead['interes'] ?? '') . "\n\n";
    $b .= "Mensaje:\n" . (string)($lead['mensaje'] ?? '') . "\n\n";
    $b .= "— Consentimiento —\n";
    $b .= 'Privacidad:  ' . ((($lead['consentimiento']['acepta'] ?? false) === true) ? 'sí' : '—') . "\n";
    // Los leads en cola anteriores a esta casilla no traen el campo: no se inventa un sí ni un no.
    if (array_key_exists('consentimiento_comercial', $lead) && is_array($lead['consentimiento_comercial'])) {
        $cc = $lead['consentimiento_comercial'];
        $acepta = ($cc['acepta'] ?? false) === true;
        $b .= 'Comunicaciones comerciales: ' . ($acepta ? 'sí' : 'no') . "\n";
        if ($acepta) {
            $b .= 'Texto aceptado: ' . (string)($cc['texto'] ?? '') . "\n";
            $b .= 'Fecha: ' . (string)($cc['fecha'] ?? '') . "\n";
            $b .= 'Versión de la política: ' . (string)($cc['version_politica'] ?? '') . "\n";
        }
    }
    $b .= "\n— Origen —\n";
    $b .= 'Página:       ' . $v($o['pagina'] ?? '') . "\n";
    $b .= 'Entrada:      ' . $v($o['landing'] ?? '') . "\n";
    $b .= 'Referrer:     ' . $v($o['referrer'] ?? '') . "\n";
    foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
        $b .= str_pad($k . ':', 14) . $v($utm[$k] ?? '') . "\n";
    }
    $motivo = (string)($lead['asistente']['motivo'] ?? '');
    if ($motivo !== '') $b .= "Motivo (asistente): $motivo\n";
    if (!empty($lead['calculadora'])) $b .= 'Calculadora: ' . json_encode($lead['calculadora'], JSON_UNESCAPED_UNICODE) . "\n";
    $b .= "\n— Respuesta automática (E-02) —\n" . leads_e02_previsto($lead, $nota === 'sin_registro_local') . "\n";

    $h  = "From: Sobtree Web <{$c['notify_from']}>\r\n";
    $h .= 'Reply-To: ' . $one($lead['email'] ?? '') . "\r\n";
    $h .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
    return leads_mail('aviso', (string)$lead['id'], $c['notify_to'], leads_asunto_mime($subject), $b, $h);
}

/**
 * Entrega de un lead YA GUARDADO: aviso a info@ (si aún no salió bien) y, después, la respuesta
 * automática E-02 si corresponde. Devuelve ['correo' => estado, 'e02' => estado|null].
 */
function leads_deliver(array $lead, ?string $correoPrevio = null): array {
    $id = (string)$lead['id'];
    $correo = $correoPrevio;
    if ($correoPrevio !== 'correo_ok') {
        $ok = leads_send_mail($lead);
        $correo = $ok ? 'correo_ok' : 'correo_error';
        leads_mark($id, 'correo', $correo, 'aviso');
        if (!$ok) error_log("leads: falló el aviso por correo de $id (lo reintenta el cron)");
    }
    $e02 = leads_e02_procesar($lead, 1);
    return ['correo' => $correo, 'e02' => $e02];
}

/** Lee todos los leads y su último estado por canal (con fecha e intentos fallidos). */
function leads_load_all(): array {
    $leads = [];
    $f = leads_file();
    if (is_file($f)) {
        foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $row = json_decode($line, true);
            if (is_array($row) && isset($row['id'])) $leads[$row['id']] = $row;
        }
    }
    return [$leads, leads_load_state()];
}

/** Estado por «id|canal»: ultimo, fecha (del último), errores. Además, «_e02_enviados»: lista de [email, ts]. */
function leads_load_state(): array {
    $state = ['_e02_enviados' => []];
    $s = leads_status_file();
    if (!is_file($s)) return $state;
    foreach (file($s, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $row = json_decode($line, true);
        if (!is_array($row) || !isset($row['id'], $row['canal'], $row['estado'])) continue;
        $k = $row['id'] . '|' . $row['canal'];
        $state[$k]['ultimo'] = $row['estado'];
        $state[$k]['fecha'] = (string)($row['fecha'] ?? '');
        if (in_array($row['estado'], ['error', 'correo_error'], true)) $state[$k]['errores'] = ($state[$k]['errores'] ?? 0) + 1;
        if ($row['canal'] === 'e02' && in_array($row['estado'], ['enviando', 'enviado'], true)) {
            // El detalle de «enviando»/«enviado» es el email (en minúsculas) para la idempotencia por email.
            $state[$k]['email'] = (string)($row['detalle'] ?? '');
            $state[$k]['ts'] = (int)strtotime((string)($row['fecha'] ?? ''));
        }
    }
    // Cuenta como respondido solo el lead cuyo ÚLTIMO estado E-02 es enviando/enviado (un «error» posterior no).
    foreach ($state as $k => $st) {
        if (!is_string($k) || !str_ends_with($k, '|e02') || !in_array($st['ultimo'] ?? '', ['enviando', 'enviado'], true)) continue;
        $state['_e02_enviados'][] = [(string)($st['email'] ?? ''), (int)($st['ts'] ?? 0)];
    }
    return $state;
}

// =====================================================================================================
// E-02 · respuesta automática
// =====================================================================================================

/** ¿Estamos en horario de envío? L-V 09:00–19:00 Europe/Madrid, sin los festivos de la lista. */
function leads_e02_en_horario(?int $ts = null): bool {
    $d = (new DateTimeImmutable('@' . ($ts ?? leads_ahora())))->setTimezone(new DateTimeZone(LEADS_E02_ZONA));
    $dia = (int)$d->format('N');
    $hora = (int)$d->format('G');
    if ($dia > 5 || in_array($d->format('Y-m-d'), LEADS_E02_FESTIVOS, true)) return false;
    return $hora >= LEADS_E02_HORA_INICIO && $hora < LEADS_E02_HORA_FIN;
}

/** Limpia un texto para el correo: sin controles ni invisibles, sin < >, espacios colapsados y recortado. */
function leads_e02_limpiar(mixed $v, int $max): string {
    $s = (string)($v ?? '');
    $s = (string)preg_replace('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', ' ', $s);
    $s = str_replace(['<', '>'], '', $s);
    $s = trim((string)preg_replace('/\s+/u', ' ', $s));
    return trim(mb_substr($s, 0, $max));
}

/**
 * Filtro de E-02 (conservador: ante la duda NO se responde; el lead sigue avisado a info@).
 * Devuelve null si se puede responder o el motivo del descarte:
 *   tipo        tipo_formulario distinto de «contacto» (calculadora, asistente)
 *   email       email ausente o no válido
 *   prueba      lead marcado como prueba (campo prueba/es_prueba = true), dominio reservado
 *               (example.com/.net/.org, *.example, *.test, *.invalid, *.localhost) o la palabra
 *               «prueba» o «test» suelta en el nombre o la empresa
 *   desechable  dominio de correo desechable conocido (LEADS_E02_DESECHABLES) o subdominio suyo
 *   spam        enlaces en nombre o empresa; 2 o más enlaces en el mensaje; nombre, empresa y mensaje
 *               idénticos; nombre sin ninguna vocal (con 4 o más letras); alfabeto cirílico o CJK en el
 *               mensaje; una «palabra» de 30 o más caracteres seguidos sin espacios en nombre o empresa;
 *               letras con 3+ dígitos incrustados en medio (p. ej. «NAEWTRER276562NERTH») en nombre, empresa o
 *               mensaje; la misma secuencia de 3+ dígitos en el nombre y en el mensaje
 *               («Talleres 2000 S.L.» o «Calle Mayor 123» no se filtran: los dígitos van separados por espacios)
 * El honeypot (campo «web») y el tiempo mínimo ya los descarta contact.php antes de guardar.
 */
function leads_e02_filtro(array $lead): ?string {
    if (!in_array((string)($lead['tipo_formulario'] ?? ''), LEADS_E02_TIPOS, true)) return 'tipo';
    $email = mb_strtolower(trim((string)($lead['email'] ?? '')));
    if ($email === '' || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) return 'email';
    $dominio = substr($email, strrpos($email, '@') + 1);
    if (($lead['prueba'] ?? false) === true || ($lead['es_prueba'] ?? false) === true) return 'prueba';
    if (in_array($dominio, ['example.com', 'example.net', 'example.org'], true)
        || preg_match('/(^|\.)(example|test|invalid|localhost)$/', $dominio)) return 'prueba';
    $nombre = (string)($lead['nombre'] ?? '');
    $empresa = (string)($lead['empresa'] ?? '');
    $mensaje = (string)($lead['mensaje'] ?? '');
    if (preg_match('/(^|[^\p{L}])(prueba|test)([^\p{L}]|$)/iu', $nombre . ' | ' . $empresa)) return 'prueba';
    foreach (LEADS_E02_DESECHABLES as $d) {
        if ($dominio === $d || str_ends_with($dominio, '.' . $d)) return 'desechable';
    }
    $enlace = '~(https?://|www\.)~i';
    if (preg_match($enlace, $nombre) || preg_match($enlace, $empresa)) return 'spam';
    if (preg_match_all($enlace, $mensaje) >= 2) return 'spam';
    $norm = fn(string $s): string => mb_strtolower(trim((string)preg_replace('/\s+/u', ' ', $s)));
    if ($norm($nombre) !== '' && $norm($nombre) === $norm($empresa) && $norm($nombre) === $norm($mensaje)) return 'spam';
    $letras = (string)preg_replace('/[^\p{L}]/u', '', $nombre);
    if (mb_strlen($letras) >= 4 && !preg_match('/[aeiouyáéíóúàèìòùäëïöüâêîôû]/iu', $letras)) return 'spam';
    if (preg_match('/[\p{Cyrillic}\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $mensaje)) return 'spam';
    if (preg_match('/\S{30,}/u', $nombre) || preg_match('/\S{30,}/u', $empresa)) return 'spam';
    $incrustados = '/\p{L}{2,}\d{3,}\p{L}{2,}/u';
    if (preg_match($incrustados, $nombre) || preg_match($incrustados, $empresa) || preg_match($incrustados, $mensaje)) return 'spam';
    if (preg_match_all('/\d{3,}/', $nombre, $dn) && preg_match_all('/\d{3,}/', $mensaje, $dm) && array_intersect($dn[0], $dm[0])) return 'spam';
    return null;
}

/**
 * ¿Está publicada la página de condiciones? Solo existe con el flag de Contrata encendido. En la web desplegada (hay
 * index.html en public_html) se comprueba que exista su index.html; si no, la frase del enlace se
 * omite para no mandar a una página que no existe. En el código fuente (pruebas) se da por publicada.
 * La ruta va partida, como contrata_ruta(), para que el build con el flag apagado no publique el literal.
 */
function leads_e02_condiciones_publicadas(): bool {
    $web = dirname(__DIR__);
    if (!is_file($web . '/index.html')) return true;
    return is_file($web . '/' . 'condiciones' . '/index.html');
}

/** Texto fijo aprobado (texto v2 de PULSE), con nombre y empresa saneados. Texto plano. */
function leads_e02_cuerpo(array $lead): string {
    $nombre = leads_e02_limpiar($lead['nombre'] ?? '', 60);
    $empresa = leads_e02_limpiar($lead['empresa'] ?? '', 80);
    if ($empresa === '') $empresa = 'vuestra empresa';
    return implode("\n", [
        'Hola' . ($nombre !== '' ? ' ' . $nombre : '') . ':',
        '',
        "Gracias por escribirnos desde sobtree.com. Hemos recibido tu mensaje y nos gustaría entender mejor qué tarea de $empresa os quita más tiempo.",
        '',
        '¿Te viene bien una llamada de 25 minutos esta semana? Dinos dos o tres horarios y nos adaptamos. Con lo que nos cuentes te diremos con sinceridad si un diagnóstico tiene sentido para vosotros.',
        '',
        'El Diagnóstico IA exprés cuesta 490 € + IVA y el informe se entrega en un máximo de 10 días laborables desde la fecha de inicio.'
            . (leads_e02_condiciones_publicadas() ? ' Las condiciones están en https://sobtree.com' . '/' . 'condiciones' . '/.' : ''),
        '',
        'Si prefieres contarnos algo por escrito antes de la llamada, también vale: ¿qué te frenaría a la hora de dar el paso?',
        '',
        'El equipo de Sobtree',
    ]);
}

/** Línea para el aviso a info@: qué pasará con la respuesta automática de este lead. */
function leads_e02_previsto(array $lead, bool $sinRegistro = false): string {
    if (!leads_config()['autorespuesta']) return 'No se envía: respuesta automática apagada (LEADS_AUTORESPUESTA=0).';
    if ($sinRegistro) return 'No se envía: sin registro local no se puede garantizar una sola respuesta.';
    $motivo = leads_e02_filtro($lead);
    if ($motivo !== null) return "No se envía (descartado: $motivo).";
    return leads_e02_en_horario()
        ? 'Se envía ahora (en horario), salvo que ese email ya tenga respuesta en los últimos 7 días.'
        : 'En cola: se enviará en el próximo horario laboral (L-V 9:00-19:00, Madrid).';
}

/** ¿Es estado final de E-02? (enviando cuenta como enviado: ante la duda no se repite). */
function leads_e02_final(?string $estado): bool {
    return in_array($estado, ['enviando', 'enviado', 'descartado'], true);
}

/**
 * Decide y, si toca, envía la respuesta E-02 de un lead. Idempotente: bloqueo exclusivo, relectura del
 * estado y marca «enviando» ANTES de llamar a mail() (si el proceso muere a medias, no se reenvía).
 * $presupuesto: envíos que aún se permiten en esta ejecución (0 = solo clasificar/encolar).
 * Devuelve el estado E-02 resultante (null si E-02 está apagado).
 */
function leads_e02_procesar(array $lead, int $presupuesto = 1): ?string {
    if (!leads_config()['autorespuesta']) return null;
    $id = (string)$lead['id'];
    $lockFh = @fopen(leads_ensure_dir() . '/.e02.lock', 'c');
    if (!$lockFh || !flock($lockFh, LOCK_EX)) {
        if ($lockFh) fclose($lockFh);
        return null; // sin bloqueo no se envía; el cron lo intentará
    }
    try {
        $state = leads_load_state();
        $prev = $state["$id|e02"]['ultimo'] ?? null;
        if (leads_e02_final($prev)) return $prev;
        $ahora = leads_ahora();
        $motivo = leads_e02_filtro($lead);
        $alta = strtotime((string)($lead['fecha'] ?? ''));
        if ($motivo === null && ($alta === false || $ahora - $alta > LEADS_E02_MAX_DIAS * 86400)) $motivo = 'caducado';
        if ($motivo === null && ($state["$id|e02"]['errores'] ?? 0) >= LEADS_E02_MAX_ERRORES) $motivo = 'errores_de_envio';
        $email = mb_strtolower(trim((string)($lead['email'] ?? '')));
        if ($motivo === null) {
            foreach ($state['_e02_enviados'] as [$e, $ts]) {
                if ($e === $email && $ahora - $ts < LEADS_E02_DIAS_IDEMPOTENCIA * 86400) { $motivo = 'ya_respondido_7_dias'; break; }
            }
        }
        if ($motivo !== null) {
            leads_mark($id, 'e02', 'descartado', $motivo);
            return 'descartado';
        }
        $enHora = 0;
        foreach ($state['_e02_enviados'] as [, $ts]) if ($ahora - $ts < 3600) $enHora++;
        if (!leads_e02_en_horario($ahora) || $presupuesto < 1 || $enHora >= LEADS_E02_MAX_POR_HORA) {
            $det = !leads_e02_en_horario($ahora) ? 'fuera_de_horario' : 'freno';
            if ($prev !== 'pendiente') leads_mark($id, 'e02', 'pendiente', $det);
            return 'pendiente';
        }
        if (!leads_mark($id, 'e02', 'enviando', $email)) return $prev; // sin poder registrar, no se envía
        $c = leads_config();
        $h  = "From: Sobtree <{$c['notify_from']}>\r\n";
        $h .= "Reply-To: {$c['notify_from']}\r\n";
        $h .= "Auto-Submitted: auto-replied\r\n";
        $h .= "X-Sobtree-Campania: " . LEADS_E02_CAMPANIA . "\r\n";
        $h .= "MIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n";
        $ok = leads_mail('e02', $id, $email, leads_asunto_mime(LEADS_E02_ASUNTO), leads_e02_cuerpo($lead), $h);
        leads_mark($id, 'e02', $ok ? 'enviado' : 'error', $ok ? $email : 'mail_fallo');
        if (!$ok) error_log("leads: falló la respuesta E-02 de $id (se reintenta en el cron)");
        return $ok ? 'enviado' : 'error';
    } finally {
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
    }
}
