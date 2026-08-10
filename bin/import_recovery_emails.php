#!/usr/bin/php
<?php
/**
 * Carga masiva de correos de recuperación desde un CSV.
 *
 * Formato esperado del CSV (con fila de encabezado):
 *   cuenta,recuperacion
 *   informatica@fapyd.unr.edu.ar,estilo.soporte@gmail.com
 *   ...
 *
 * Los nombres de columna aceptados (sin importar mayúsculas/acentos) son:
 *   - cuenta / usuario / username / email / account
 *   - recuperacion / recovery / alt_email / altemail
 * Si no encuentra esos encabezados, usa la 1ra y 2da columna por posición.
 *
 * Uso:
 *   php import_recovery_emails.php archivo.csv [--delimiter=,] [--dry-run] [--force] [--create-missing]
 *
 *   --dry-run        No escribe nada, solo muestra qué haría con cada fila.
 *   --force          Sobrescribe el correo de recuperación aunque la cuenta ya
 *                    tenga uno cargado (por defecto esas filas se omiten).
 *   --create-missing Si la cuenta no existe todavía en Roundcube (no inició
 *                    sesión nunca), la da de alta igual que lo haría un
 *                    primer login real (fila en 'users' + identidad por
 *                    defecto), antes de cargarle el correo de recuperación.
 *                    Pensado para altas masivas en el servidor de correo que
 *                    todavía no tienen ningún login en el webmail.
 *   --delimiter      Separador del CSV (por defecto se autodetecta , o ;).
 */

// Capture the caller's original working directory BEFORE chdir()'ing into
// the Roundcube install path below — otherwise a relative CSV path given on
// the command line silently resolves against the wrong directory.
$original_cwd = getcwd();

define('INSTALL_PATH', '/usr/share/roundcube/');
// NOTE: pre-define RCMAIL_CONFIG_DIR (no trailing slash), not RCUBE_CONFIG_DIR
// directly — iniset.php derives RCUBE_CONFIG_DIR from RCMAIL_CONFIG_DIR itself
// and defines it unconditionally, so setting RCUBE_CONFIG_DIR here too would
// trigger a "Constant already defined" warning.
define('RCMAIL_CONFIG_DIR', '/etc/roundcube');
chdir(INSTALL_PATH);
require_once INSTALL_PATH . 'program/include/iniset.php';

function usage_and_exit($msg = null) {
    if ($msg) {
        fwrite(STDERR, "Error: $msg\n\n");
    }
    fwrite(STDERR, "Uso: php import_recovery_emails.php archivo.csv [--delimiter=,] [--dry-run] [--force] [--create-missing]\n");
    exit(1);
}

// --- parseo de argumentos ---
$args = array_slice($argv, 1);
$csv_path = null;
$delimiter = null;
$dry_run = false;
$force = false;
$create_missing = false;

foreach ($args as $arg) {
    if ($arg === '--dry-run') {
        $dry_run = true;
    } elseif ($arg === '--force') {
        $force = true;
    } elseif ($arg === '--create-missing') {
        $create_missing = true;
    } elseif (strpos($arg, '--delimiter=') === 0) {
        $delimiter = substr($arg, strlen('--delimiter='));
    } elseif ($arg[0] !== '-') {
        $csv_path = $arg;
    } else {
        usage_and_exit("opción desconocida '$arg'");
    }
}

if (!$csv_path) {
    usage_and_exit('falta la ruta del archivo CSV');
}
if ($csv_path[0] !== '/') {
    $csv_path = $original_cwd . '/' . $csv_path;
}
if (!is_readable($csv_path)) {
    usage_and_exit("no se puede leer el archivo '$csv_path'");
}

// --- autodetección de delimitador si no se especificó ---
$first_line = file($csv_path, FILE_IGNORE_NEW_LINES)[0] ?? '';
$first_line = preg_replace('/^\xEF\xBB\xBF/', '', $first_line); // quitar BOM si existe
if (!$delimiter) {
    $delimiter = (substr_count($first_line, ';') > substr_count($first_line, ',')) ? ';' : ',';
}

// --- bootstrap Roundcube / plugin ---
$rcmail = rcmail::get_instance(0, 'login');
require_once INSTALL_PATH . 'plugins/password_vpop_recovery/password_vpop_recovery.php';
$plugin = new password_vpop_recovery($rcmail->plugins);
$plugin->init();

$db = $rcmail->get_dbh();
$table = $rcmail->config->get('pr_users_table', 'password_recovery_data');
$fields = $rcmail->config->get('pr_fields', []);
$altemail_col = $fields['altemail'] ?? null;

if (!$altemail_col) {
    usage_and_exit("la config del plugin no tiene 'pr_fields[altemail]' definido");
}

// --- helpers ---
function find_column($header, array $candidates) {
    foreach ($header as $i => $col) {
        $norm = strtolower(trim($col));
        $norm = strtr($norm, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u']);
        if (in_array($norm, $candidates, true)) {
            return $i;
        }
    }
    return null;
}

function lookup_account($rcmail, $account) {
    $db = $rcmail->get_dbh();
    $result = $db->query(
        "SELECT u.username, i.email FROM " . $db->table_name('users') . " u"
        . " INNER JOIN " . $db->table_name('identities') . " i ON i.user_id = u.user_id"
        . " WHERE u.username = ?",
        $account
    );
    return $db->fetch_assoc($result) ?: null;
}

// Same host format Roundcube stores in users.mail_host after a real login:
// just the hostname, no scheme or port (e.g. 'tls://mail.x.com:143' -> 'mail.x.com').
function resolve_imap_host($rcmail) {
    $imap_host = $rcmail->config->get('imap_host');
    $host = is_array($imap_host) ? reset($imap_host) : $imap_host;
    $host = preg_replace('#^[a-z]+://#i', '', (string) $host);
    $host = preg_replace('/:\d+$/', '', $host);
    return $host ?: 'localhost';
}

// --- procesar CSV ---
$fh = fopen($csv_path, 'r');
$header = fgetcsv($fh, 0, $delimiter);
if (!$header) {
    usage_and_exit('el CSV está vacío');
}
$header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]); // BOM en la 1ra celda

$col_account = find_column($header, ['cuenta', 'usuario', 'username', 'email', 'account']);
$col_recovery = find_column($header, ['recuperacion', 'recovery', 'recovery_email', 'alt_email', 'altemail']);

if ($col_account === null || $col_recovery === null) {
    fwrite(STDERR, "Aviso: no reconocí los encabezados, uso columna 1 y 2 por posición.\n");
    $col_account = 0;
    $col_recovery = 1;
}

$stats = ['ok' => 0, 'omitidas' => 0, 'errores' => 0];
$row_num = 1; // la fila 1 es el encabezado

while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
    $row_num++;
    if (count($row) === 1 && trim($row[0]) === '') {
        continue; // línea vacía
    }

    $account = trim($row[$col_account] ?? '');
    $recovery = trim($row[$col_recovery] ?? '');

    if ($account === '' || $recovery === '') {
        echo "Fila $row_num: OMITIDA (falta cuenta o correo de recuperación)\n";
        $stats['omitidas']++;
        continue;
    }

    $match = lookup_account($rcmail, $account);
    $created_account = false;

    if (!$match && $create_missing) {
        if (!rcube_utils::check_email($account)) {
            echo "Fila $row_num [$account]: ERROR - la cuenta no es un email válido, no se puede dar de alta\n";
            $stats['errores']++;
            continue;
        }

        if ($dry_run) {
            echo "Fila $row_num [$account]: SE DARÍA DE ALTA la cuenta en Roundcube\n";
            $match = ['username' => $account, 'email' => $account]; // simulate for the rest of the dry-run
        } else {
            $imap_host = resolve_imap_host($rcmail);
            $new_user = rcube_user::create($account, $imap_host);
            if (!$new_user || !$new_user->ID) {
                echo "Fila $row_num [$account]: ERROR - no se pudo dar de alta la cuenta en Roundcube\n";
                $stats['errores']++;
                continue;
            }
            $match = lookup_account($rcmail, $account);
            $created_account = true;
        }
    }

    if (!$match) {
        echo "Fila $row_num [$account]: ERROR - la cuenta no existe en Roundcube" . (!$create_missing ? " (usar --create-missing para darla de alta)" : '') . "\n";
        $stats['errores']++;
        continue;
    }
    $canonical_username = $match['email'];

    if (!rcube_utils::check_email($recovery)) {
        echo "Fila $row_num [$account]: ERROR - '$recovery' no es un email válido\n";
        $stats['errores']++;
        continue;
    }
    $recovery = rcube_utils::idn_to_ascii($recovery);

    if (strcasecmp($recovery, $canonical_username) === 0) {
        echo "Fila $row_num [$account]: ERROR - el correo de recuperación no puede ser igual a la cuenta principal\n";
        $stats['errores']++;
        continue;
    }

    // ¿ya tiene un correo de recuperación cargado?
    $result = $db->query("SELECT $altemail_col AS altemail FROM $table WHERE username = ?", $canonical_username);
    $existing = $db->fetch_assoc($result);
    $existing_altemail = trim($existing['altemail'] ?? '');

    if ($existing_altemail !== '' && !$force) {
        echo "Fila $row_num [$account]: OMITIDA - ya tiene '$existing_altemail' cargado (usar --force para sobrescribir)\n";
        $stats['omitidas']++;
        continue;
    }

    if ($dry_run) {
        echo "Fila $row_num [$account]: SE CARGARÍA -> $recovery" . ($existing_altemail !== '' ? " (reemplazando '$existing_altemail')" : '') . "\n";
        $stats['ok']++;
        continue;
    }

    $is_mysql = ($db->db_provider === 'mysql');
    $db->query(
        "INSERT INTO $table (username, $altemail_col) VALUES (?, ?)"
        . ($is_mysql
            ? " ON DUPLICATE KEY UPDATE $altemail_col = VALUES($altemail_col)"
            : " ON CONFLICT (username) DO UPDATE SET $altemail_col = EXCLUDED.$altemail_col"),
        $canonical_username, $recovery
    );

    // not using affected_rows(): MySQL's ON DUPLICATE KEY UPDATE reports 0
    // rows affected when the new value equals the existing one.
    if (!$db->is_error()) {
        echo "Fila $row_num [$account]: OK" . ($created_account ? " (cuenta dada de alta)" : '') . " -> $recovery\n";
        $stats['ok']++;
    } else {
        echo "Fila $row_num [$account]: ERROR - no se pudo guardar (" . $db->is_error() . ")\n";
        $stats['errores']++;
    }
}

fclose($fh);

echo "\n" . ($dry_run ? "[DRY-RUN] " : '') . "Resumen: {$stats['ok']} cargadas, {$stats['omitidas']} omitidas, {$stats['errores']} con error.\n";
