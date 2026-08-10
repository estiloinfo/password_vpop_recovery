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
        // change, so a plain driver->save('', ...) always fails here. When a
        // vpopmail domain-admin account is configured, use it to reset the
        // target user's password instead (vpopmaild allows an admin session
        // to run mod_user on any user in its domain).
        if ($this->rc->config->get('password_driver') == 'vpopmaild'
            && $this->rc->config->get('pr_vpopmaild_admin_user')
        ) {
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
        $host       = $this->rc->config->get('password_vpopmaild_host');
        $port       = $this->rc->config->get('password_vpopmaild_port');
        $admin_user = $this->rc->config->get('pr_vpopmaild_admin_user');
        $admin_pass = $this->rc->config->get('pr_vpopmaild_admin_pass');

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
