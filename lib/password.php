<?php

/*******************************************
*
* Function for save with Password plugin
*
*******************************************/

if (!defined('PASSWORD_SUCCESS')) {
    define('PASSWORD_SUCCESS', 0);
    define('PASSWORD_CRYPT_ERROR', 1);
    define('PASSWORD_ERROR', 2);
    define('PASSWORD_CONNECT_ERROR', 3);
    define('PASSWORD_IN_HISTORY', 4);
    define('PASSWORD_CONSTRAINT_VIOLATION', 5);
    define('PASSWORD_COMPARE_CURRENT', 6);
    define('PASSWORD_COMPARE_NEW', 7);
}


class password_vpop_recovery_pwd {

    private $rc;
    private $pr;
    private $drivers = [];
    private $sql_db;

    function __construct($pr_plugin) {
        $this->pr = $pr_plugin;
        $this->rc = $pr_plugin->rc;
    }

    // Password policy, enforced server-side regardless of what the client
    // already checked (the client-side checklist mirrors these same rules).
    function _check_strength($passwd)
    {
        $min_length = (int) ($this->pr->use_password ? $this->rc->config->get('password_minimum_length', 8) : $this->rc->config->get('pr_password_minimum_length', 8));
        $max_length = (int) ($this->pr->use_password ? $this->rc->config->get('password_maximum_length', 0) : $this->rc->config->get('pr_password_maximum_length', 0));

        if ($min_length && strlen($passwd) < $min_length) {
            return str_replace('%d', $min_length, $this->pr->gettext('password_too_short'));
        }

        if ($max_length && strlen($passwd) > $max_length) {
            return str_replace('%d', $max_length, $this->pr->gettext('password_too_long'));
        }

        if (!preg_match('/[0-9]/', $passwd)) {
            return $this->pr->gettext('policy_digit');
        }

        if (!preg_match('/[^A-Za-z0-9]/', $passwd)) {
            return $this->pr->gettext('policy_special');
        }

        $min_score = ($this->pr->use_password ? $this->rc->config->get('password_minimum_score') : $this->rc->config->get('pr_password_minimum_score'));

        if ($min_score && $this->pr->use_password && ($driver = $this->_load_driver('strength')) && method_exists($driver, 'check_strength')) {
            list($score, $reason) = $driver->check_strength($passwd);
            if ($score < $min_score) {
                return $this->pr->gettext('password_check_failed') . (!empty($reason) ? " $reason" : '');
            }
        }
    }

    function _save($passwd, $username)
    {
        if ($res = $this->_check_strength($passwd)) {
            return $res;
        }

        // Password recovery never has the user's current password (that's the
        // whole point). The 'vpopmaild' driver's protocol requires the user to
        // self-authenticate with their CURRENT password before it allows a
        // change, so a plain driver->save('', ...) always fails here.
        // pr_password_driver (separate from the core 'password' plugin's own
        // password_driver) chooses how THIS plugin resets it instead.
        $pr_driver = $this->rc->config->get('pr_password_driver', 'vpopmaild');

        if ($pr_driver === 'sql') {
            $result = $this->_save_sql($passwd, $username);
        } elseif ($pr_driver === 'vpopmaild') {
            $result = $this->_save_vpopmaild_admin($passwd, $username);
        } else {
            if (!($driver = $this->_load_driver())) {
                return $this->pr->gettext('write_failed');
            }

            $result = $driver->save('', $passwd, $username);
        }

        $message = '';

        if (is_array($result)) {
            $message = $result['message'];
            $result  = $result['code'];
        }

        switch ($result) {
            case PASSWORD_SUCCESS:
                return PASSWORD_SUCCESS;
            case PASSWORD_CRYPT_ERROR:
                $reason = $this->pr->gettext('crypt_error');
                break;
            case PASSWORD_CONNECT_ERROR:
                $reason = $this->pr->gettext('connect_error');
                break;
            case PASSWORD_IN_HISTORY:
                $reason = $this->pr->gettext('password_in_history');
                break;
            case PASSWORD_CONSTRAINT_VIOLATION:
                $reason = $this->pr->gettext('password_const_viol');
                break;
            case PASSWORD_ERROR:
            default:
                $reason = $this->pr->gettext('write_failed');
        }

        if ($message) {
            $reason .= ' ' . $message;
        }

        return $reason;
    }

    // Reset a user's password via vpopmaild using a domain-admin account
    // (vpopmaild allows an authenticated admin to run mod_user on any user
    // in its domain, unlike a self-login which only works for your own account).
    private function _save_vpopmaild_admin($passwd, $username)
    {
        $host  = $this->rc->config->get('password_vpopmaild_host');
        $port  = $this->rc->config->get('password_vpopmaild_port');
        $admin = $this->pr->resolve_vpopmaild_admin($username);
        $admin_user = $admin['user'];
        $admin_pass = $admin['pass'];

        $sock = new Net_Socket();
        $result = $sock->connect($host, $port, null);
        if (is_a($result, 'PEAR_Error')) {
            return PASSWORD_CONNECT_ERROR;
        }

        $sock->setTimeout($this->rc->config->get('password_vpopmaild_timeout'), 0);

        $result = $sock->readLine();
        if (!preg_match('/^\+OK/', $result)) {
            $sock->disconnect();
            return PASSWORD_CONNECT_ERROR;
        }

        $sock->writeLine("slogin " . $admin_user . " " . $admin_pass);
        $result = $sock->readLine();

        if (!preg_match('/^\+OK/', $result)) {
            $sock->writeLine("quit");
            $sock->disconnect();
            return PASSWORD_CONNECT_ERROR;
        }

        $sock->writeLine("mod_user " . $username);
        $sock->writeLine("clear_text_password " . $passwd);
        $sock->writeLine(".");
        $result = $sock->readLine();
        $sock->writeLine("quit");
        $sock->disconnect();

        if (!preg_match('/^\+OK/', $result)) {
            return PASSWORD_ERROR;
        }

        return PASSWORD_SUCCESS;
    }

    // Alternative to vpopmaild for installs where vpopmail itself runs on a
    // SQL backend: write the new password hash directly into vpopmail's own
    // table instead of going through the vpopmaild daemon. One set of DB
    // credentials covers every domain, which scales far better than a
    // postmaster password per domain - but the hash MUST exactly match what
    // vpopmail's own vchkpw expects, which depends on how that specific
    // vpopmail was compiled (see README: "SQL driver" for how to verify this
    // against your install before relying on it in production).
    //
    // Reuses rcube_db - the same DB layer/pattern the plugin already uses
    // for password_recovery_data (see get_user_props/set_user_props) -
    // instead of talking to PDO directly, so DSN format, placeholders and
    // error handling stay consistent across the whole plugin. It's a SEPARATE
    // rcube_db instance (not $this->rc->db) because vpopmail's SQL table
    // normally lives on a different database/server than Roundcube's own.
    private function _save_sql($passwd, $username)
    {
        $dsn = $this->rc->config->get('pr_sql_dsn');
        if (!$dsn) {
            rcube::raise_error([
                    'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                    'message' => "password_vpop_recovery: pr_password_driver='sql' but pr_sql_dsn is not configured"
                ], true, false
            );
            return PASSWORD_CONNECT_ERROR;
        }

        $parts  = explode('@', $username, 2);
        $user   = $parts[0] ?? '';
        $domain = strtolower($parts[1] ?? '');

        if (empty($this->sql_db)) {
            $this->sql_db = new rcube_db($dsn);
        }
        $db = $this->sql_db;

        $table   = $this->rc->config->get('pr_sql_table', 'vpopmail');
        $columns = $this->rc->config->get('pr_sql_columns', []) + [
            'user' => 'pw_name', 'domain' => 'pw_domain',
            'passwd' => 'pw_passwd', 'clear_passwd' => 'pw_clear_passwd',
        ];

        $sets   = ["{$columns['passwd']} = ?"];
        $params = [$this->_hash_password($passwd)];

        if ($this->rc->config->get('pr_sql_store_clear', false)) {
            $sets[] = "{$columns['clear_passwd']} = ?";
            $params[] = $passwd;
        }

        $sql = "UPDATE $table SET " . implode(', ', $sets)
             . " WHERE {$columns['user']} = ? AND {$columns['domain']} = ?";
        $params[] = $user;
        $params[] = $domain;

        $result = $db->query($sql, $params);

        if ($db->is_error($result)) {
            rcube::write_log('errors', 'password_vpop_recovery sql driver: ' . $db->is_error($result));
            return $db->is_connected() ? PASSWORD_ERROR : PASSWORD_CONNECT_ERROR;
        }

        // A fresh random salt is used every time, so the new hash can never
        // equal the stored one - 0 rows affected reliably means no row
        // matched (user, domain), not "value unchanged".
        return $db->affected_rows($result) >= 1 ? PASSWORD_SUCCESS : PASSWORD_ERROR;
    }

    // Hashes a password the way vpopmail itself would store it, per
    // pr_sql_hash_scheme. MUST be validated against your own vpopmail
    // install (compare against a password change made with vpasswd/vuserinfo)
    // before trusting this in production - see README.
    private function _hash_password($passwd)
    {
        $scheme = $this->rc->config->get('pr_sql_hash_scheme', 'sha512-crypt');

        switch ($scheme) {
            case 'crypt-blowfish':
                $salt = '$2y$10$' . substr(strtr(base64_encode(random_bytes(16)), '+', '.'), 0, 22);
                return crypt($passwd, $salt);
            case 'sha512-crypt':
                $salt = '$6$' . substr(strtr(base64_encode(random_bytes(12)), '+', '.'), 0, 16) . '$';
                return crypt($passwd, $salt);
            case 'crypt-md5':
                $salt = '$1$' . substr(strtr(base64_encode(random_bytes(6)), '+', '.'), 0, 8) . '$';
                return crypt($passwd, $salt);
            case 'system':
            default:
                // Traditional DES crypt: PHP requires an explicit salt since
                // PHP 8 (crypt($passwd) alone is an ArgumentCountError now).
                $alphabet = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
                $salt = $alphabet[random_int(0, 63)] . $alphabet[random_int(0, 63)];
                return crypt($passwd, $salt);
        }
    }

    function _load_driver($type = 'password')
    {
        if (!($type && $driver = $this->rc->config->get('password_' . $type . '_driver'))) {
            $driver = $this->rc->config->get('password_driver', 'sql');
        }

        if (empty($this->drivers[$type])) {
            $class  = "rcube_{$driver}_password";
            $file = __DIR__ . "/../../password/drivers/$driver.php";

            if (!file_exists($file)) {
                rcube::raise_error([
                        'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Password plugin: Driver file does not exist ($file)"
                    ], true, false
                );
                return false;
            }

            include_once $file;

            if (!class_exists($class, false) || (!method_exists($class, 'save') && !method_exists($class, 'check_strength'))) {
                rcube::raise_error([
                        'code' => 600, 'file' => __FILE__, 'line' => __LINE__,
                        'message' => "Password plugin: Broken driver $driver"
                    ], true, false
                );
                return false;
            }

            $this->drivers[$type] = new $class;
        }

        return $this->drivers[$type];
    }

}

?>
