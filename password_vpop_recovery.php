<?php
/**
 * Password recovery
 *
 * Plugin to reset an account password
 *
 * @version 1.0
 * @original_author Alexander Alferov
 *
 * @url https://github.com/AlfnRU/roundcube-password_vpop_recovery
 */

class password_vpop_recovery extends rcube_plugin {

    public $task = 'login|logout|settings|mail';
    public $rc;
    public $db;
    public $user;
    public $use_password;
    public $pwd_conf;
    private $pwd;
    private $fields;
    private $send;

    function init() {
        $this->rc = rcmail::get_instance();
        // Built-in defaults first (versioned, no secrets), then the real
        // per-install config.inc.php, which only needs to override what
        // differs — see config.inc.default.php for details.
        $this->load_config('config.inc.default.php');
        $this->load_config();

        if (!$this->rc->config->get('pr_use_confirm_code') && !$this->rc->config->get('pr_use_question'))
            return;

        $this->init_ui();
        $this->add_texts('localization/');

        if ($this->rc->task == 'login' || $this->rc->task == 'logout') {
            $this->add_hook('render_page', [$this, 'add_labels_to_login_page']);
            $this->add_hook('startup', [$this, 'startup']);
            $this->register_action('plugin.get_confirm_code_count', [$this, 'get_confirm_code_count']);
        } else if ($this->rc->task == 'mail') {
            $this->add_hook('render_page', [$this, 'add_labels_to_mail_page']);
            $this->add_hook('messages_list', [$this, 'check_identities']);
        } else if ($this->rc->task == 'settings') {
            $this->add_hook('identity_form', [$this, 'identity_form']);
            $this->add_hook('identity_update', [$this, 'identity_update']);
        }

        $this->include_script('password_vpop_recovery.js');
    }

    /*******************
    * STARTUP
    *******************/

    function init_ui() {
        if (!$this->fields) $this->fields = $this->rc->config->get('pr_fields');
        if (!$this->db) $this->get_dbh();
        if (!$this->user) $this->get_user_props();

        $this->use_password = ($this->rc->config->get('pr_use_password_plugin') && $this->rc->plugins->load_plugin('password', true));

        $new_fields = [
            'token'          => ['type' => 'VARCHAR(255)', 'default' => '\'\''],
            'token_validity' => ['type' => 'DATETIME'    , 'default' => '\'2000-01-01 00:00:00\'']
        ];

        foreach($this->fields as $field => $field_name){
            $new_fields[$field_name] = ['type' => ($field == 'phone' ? 'VARCHAR(30)' : 'VARCHAR(255)'), 'default' => '\'\''];
        }

        foreach($new_fields as $field_name => $field_props){
            $query = "SELECT " . $field_name . " FROM " . $this->rc->config->get('pr_users_table');
            $result = $this->db->query($query);
            if (!$result) {
                $query = "ALTER TABLE " . $this->rc->config->get('pr_users_table') . " ADD " . $field_name . " " . $field_props['type'] . " DEFAULT " . $field_props['default'];
                $result = $this->db->query($query);
            }
        }

        require_once $this->home . '/lib/send.php';
        $this->send = new password_vpop_recovery_send($this);

        require_once $this->home . '/lib/password.php';
        $this->pwd = new password_vpop_recovery_pwd($this);
    }

    function startup($p) {
        if ($this->rc->action != 'plugin.password_vpop_recovery' || !isset($_SESSION['temp']))
            return $p;

        switch ($this->get_action()) {
            case 'init':
                $this->recovery_password_form();
                break;

            case 'renew':
                $this->renew_confirm_code();
                break;

            case 'new':
                $this->new_password_form();
                break;

            case 'reset':
                $this->reset_password();
                break;

            case 'save':
                $this->save_password();
                break;

            case 'cancel':
                $this->rc->kill_session();
                $this->rc->output->command('redirect', './');
                break;
        }
        return $p;
    }

    function add_labels_to_login_page($p) {
        if ($p['template'] == 'login') {
            $this->rc->output->add_label('password_vpop_recovery.forgot_password');
        }
        return $p;
    }

    function add_labels_to_mail_page($p) {
        $this->rc->output->add_label('password_vpop_recovery.no_identities');
        $this->rc->output->add_script('rcmail.message_time = 10000;');
        return $p;
    }

    /*******************
    * PASSWORD
    *******************/

    // Creating form for reset password
    private function recovery_password_form() {
        $this->rc->output->add_label(
            'password_vpop_recovery.recovery_password',
            'password_vpop_recovery.no_username',
            'password_vpop_recovery.account_email',
            'password_vpop_recovery.altemail',
            'password_vpop_recovery.no_altemail'
        );

        $this->rc->output->set_pagetitle($this->gettext('recovery_password'));
        $this->rc->output->add_gui_object('recoverypasswordform', 'recovery-password-form');
        $this->rc->output->send('password_vpop_recovery.recovery_password_form');
    }

    // Creating form for new password
    private function new_password_form() {
        $this->rc->output->add_label(
            'password_vpop_recovery.newpassword',
            'password_vpop_recovery.newpassword_confirm',
            'password_vpop_recovery.question',
            'password_vpop_recovery.answer',
            'password_vpop_recovery.code',
            'password_vpop_recovery.recovery_password',
            'password_vpop_recovery.renew_code',
            'password_vpop_recovery.count_send_code_maximum',
            'password_vpop_recovery.no_code',
            'password_vpop_recovery.no_answer',
            'password_vpop_recovery.no_password',
            'password_vpop_recovery.no_password_confirm',
            'password_vpop_recovery.password_inconsistency',
            'password_vpop_recovery.password_too_short',
            'password_vpop_recovery.password_too_long',
            'password_vpop_recovery.policy_length',
            'password_vpop_recovery.policy_digit',
            'password_vpop_recovery.policy_special',
            'password_vpop_recovery.policy_match'
        );

        $this->rc->output->set_pagetitle($this->gettext('recovery_password'));
        $this->rc->output->add_gui_object('newpasswordform', 'new-password-form');

        $password_minimum_length = ($this->use_password ? $this->rc->config->get('password_minimum_length',8) : $this->rc->config->get('pr_password_minimum_length',8));
        $password_maximum_length = ($this->use_password ? $this->rc->config->get('password_maximum_length',0) : $this->rc->config->get('pr_password_maximum_length',0));

        // NOTE: pr_use_question/pr_use_confirm_code must depend only on what
        // this installation supports (config), never on whether *this*
        // account actually has recovery data configured. Otherwise the
        // rendered form itself (which fields appear) becomes an oracle for
        // guessing valid usernames / alt-emails, even though the message
        // text is generic (see reset_password()).
        $this->rc->output->set_env('pr_username', $this->user['username'] ?? '');
        $this->rc->output->set_env('pr_question', $this->user['question'] ?? '');
        $this->rc->output->set_env('pr_use_question', (bool) $this->rc->config->get('pr_use_question'));
        $this->rc->output->set_env('pr_use_confirm_code', (bool) $this->rc->config->get('pr_use_confirm_code'));
        $this->rc->output->set_env('pr_password_minimum_length', (int) $password_minimum_length);
        $this->rc->output->set_env('pr_password_maximum_length', (int) $password_maximum_length);

        $this->rc->output->send('password_vpop_recovery.new_password_form');
    }

    // Renew and send confirmation code to user (to alternative email and phone)
    private function renew_confirm_code() {
        $ip = rcube_utils::remote_addr();
        $username = $this->user['username'] ?? '';
        $this->record_attempt($username, $ip);

        if (empty($username) || $this->rate_limit_exceeded($username, $ip)) {
            $message = $this->gettext('check_account_generic');
            $type = 'notice';
        } else if ($this->get_confirm_code_count() < $this->rc->config->get('pr_confirm_code_count_max')) {
            $result = $this->send->send_confirm_code_to_user();
            if ($result['send']) {
                $this->update_confirm_code_count(1);
                $message = $result['message'];
                $type = 'confirmation';
            } else {
                $message = $this->gettext('send_failed') . "\n" . $result['message'];
                $type = 'error';
            }
        } else {
            $message = $this->gettext('count_send_code_maximum');
            $type = 'error';
        }
        $this->rc->output->command('display_message', $message, $type);
        $this->rc->output->send('plugin');
    }

    // Verify username + alternate email together, apply rate limiting, and
    // send the confirmation code. The response is intentionally identical
    // regardless of *why* it didn't work (account missing, alt email wrong,
    // no recovery data, rate limited) so the flow can't be used as an oracle
    // to enumerate accounts or guess someone's personal recovery email.
    private function reset_password() {
        // kill remember_me cookies
        setcookie ('rememberme_user', '', time()-3600);
        setcookie ('rememberme_pass', '', time()-3600);

        $params = rcube_utils::request2param(rcube_utils::INPUT_POST);
        $submitted_altemail = trim($params['altemail'] ?? '');
        $ip = rcube_utils::remote_addr();
        $username = $this->user['username'] ?? trim(rcube_utils::get_input_value('_username', rcube_utils::INPUT_POST));

        $this->record_attempt($username, $ip);

        $allow_code = ($this->rc->config->get('pr_use_confirm_code') && ($this->user['have_code'] ?? false));
        $verified = !empty($this->user['username']) && $allow_code && $submitted_altemail !== ''
            && strcasecmp($submitted_altemail, $this->user['altemail'] ?? '') === 0;

        if ($verified && !$this->rate_limit_exceeded($this->user['username'], $ip)) {
            if (!empty($this->user['token_validity']) && !($this->user['token_expired'] ?? true)) {
                // a code is already pending; don't send another one
                $this->update_confirm_code_count(1);
            } else {
                $this->send->send_confirm_code_to_user();
                $this->update_confirm_code_count(1);
            }
        }

        $this->logging("Password recovery request for '" . $username . "' (IP: $ip)" . ($verified ? '' : ' [not verified]'));

        // When not verified, scrub the account data before rendering the
        // next screen: otherwise the hidden username field (visible via
        // "view source") would confirm whether the account exists, even
        // though the displayed message and form fields are already generic.
        if (!$verified) {
            $this->user = [];
        }

        $this->rc->output->command('display_message', $this->gettext('check_account_generic'), 'notice');
        $this->new_password_form();
    }

    // Record an attempt for rate-limiting purposes (per account and per IP),
    // independent of PHP session state which a script can trivially bypass.
    function record_attempt($username, $ip) {
        $table = $this->rc->config->get('pr_recovery_attempts_table', 'password_recovery_attempts');
        $this->db->query("INSERT INTO $table (username, ip) VALUES (?, ?)", (string) $username, (string) $ip);

        // opportunistic cleanup of old rows, no cron needed
        if (random_int(1, 200) === 1) {
            $cutoff = ($this->db->db_provider === 'mysql') ? "NOW() - INTERVAL 7 DAY" : "NOW() - INTERVAL '7 days'";
            $this->db->query("DELETE FROM $table WHERE created_at < $cutoff");
        }
    }

    // True if this account or this IP has made too many recovery attempts
    // in the last 24 hours.
    function rate_limit_exceeded($username, $ip) {
        $table       = $this->rc->config->get('pr_recovery_attempts_table', 'password_recovery_attempts');
        $max_account = (int) $this->rc->config->get('pr_rate_limit_account_per_day', 5);
        $max_ip      = (int) $this->rc->config->get('pr_rate_limit_ip_per_day', 20);
        $cutoff      = ($this->db->db_provider === 'mysql') ? "NOW() - INTERVAL 24 HOUR" : "NOW() - INTERVAL '24 hours'";

        $result = $this->db->query("SELECT COUNT(*) AS c FROM $table WHERE username = ? AND created_at > $cutoff", (string) $username);
        $row = $this->db->fetch_assoc($result);
        if ($max_account && (int) ($row['c'] ?? 0) > $max_account) {
            return true;
        }

        $result = $this->db->query("SELECT COUNT(*) AS c FROM $table WHERE ip = ? AND created_at > $cutoff", (string) $ip);
        $row = $this->db->fetch_assoc($result);
        if ($max_ip && (int) ($row['c'] ?? 0) > $max_ip) {
            return true;
        }

        return false;
    }

    // Save new password to DB
    private function save_password() {
        $params = rcube_utils::request2param(rcube_utils::INPUT_POST);
        $this->debug("Save new password: " . print_r($params, true));

        if ($this->rc->config->get('pr_use_question') && ($this->user['have_answer'] ?? false) && $this->user['answer'] != $params['answer']) {
            $message = $this->gettext('answer_failed');
            $type = 'error';
        } else if ($this->rc->config->get('pr_use_confirm_code') && ($this->user['token_expired'] ?? true)) {
            $message = $this->gettext('code_expired');
            $type = 'error';
        } else if ($this->rc->config->get('pr_use_confirm_code') && !(password_verify($params['code'], $this->user['token'] ?? ''))) {
            $message = $this->gettext('code_failed');
            $type = 'error';
        } else {
            // props to save
            $save = ['token'=>'', 'token_validity'=>''];

            // check allowed characters according to the configured 'password_charset' option
            // by converting the password entered by the user to this charset and back to UTF-8
            $rc_charset = strtoupper($this->rc->output->get_charset());
            $orig_pwd = $params['newpassword'];
            $chk_pwd = rcube_charset::convert($orig_pwd, $rc_charset, 'UTF-8');
            $chk_pwd = rcube_charset::convert($chk_pwd, 'UTF-8', $rc_charset);

            // We're doing this for consistence with Roundcube core
            $newpassword = rcube_charset::convert($params['newpassword'], $rc_charset, 'UTF-8');

            if ($chk_pwd != $orig_pwd || preg_match('/[\x00-\x1F\x7F]/', $newpassword)) {
                $message = $this->gettext('password_forbidden');
                $type = 'error';
            } else if (!$this->use_password && ($chk_strength = $this->pwd->_check_strength($newpassword))) {
                $message = $chk_strength;
                $type = 'error';
            } else {
                $password_saved = false;

                if ($this->use_password) {
                    $result = $this->pwd->_save($newpassword, $this->user['username']);
                    $password_saved = ($result == 0);
                    if (!$password_saved) {
                        $message = $this->gettext('write_failed') . ": " . $result;
                        $type = 'error';
                        $this->debug($message);
                    }
                } else {
                    $save['password'] = crypt($newpassword, '$1$' . rcube_utils::random_bytes(9));
                    //$save['password'] = crypt($newpassword, '$6$' . rcube_utils::random_bytes(16));
                    $password_saved = true;
                }

                if ($password_saved) {
                    $props_saved = $this->set_user_props($save);

                    // With the Password plugin, the credential itself is already
                    // changed at this point; a failed token-cleanup shouldn't be
                    // reported to the user as "password not changed".
                    if ($props_saved || $this->use_password) {
                        if (!$props_saved) {
                            $this->logging("Password changed for '" . $this->user['username'] . "' but failed to clear the recovery token");
                        }
                        $this->logging("Save new password for '" . $this->user['username'] . "' (IP: " . rcube_utils::remote_addr() . ")");
                        $message = $this->gettext('password_changed');
                        $type = 'confirmation';
                    } else {
                        $message = $this->gettext('password_not_changed');
                        $type = 'error';
                    }
                }
            }
        }

        $this->rc->output->command('display_message', $message, $type);

        if ($type != 'error') {
            $this->rc->kill_session();
            $this->rc->output->command('redirect', './', 2);
//            $this->rc->output->send('login');
        }
    }

    /*******************
    * IDENTITIES
    *******************/

    // Verifying the user identities needed to recover the password
    function check_identities() {
        if (!isset($_SESSION['show_notice_identities']) && !$this->user['have_altemail']) {
            $link = "<a href='./?_task=settings&_action=identities'>". $this->gettext('click_here') ."</a>";
            $this->rc->output->command('display_message', sprintf($this->gettext('no_identities'), $link), 'notice');
            $_SESSION['show_notice_identities'] = true;
        }
    }

    // Handler for 'identity_form' hook (executed on identities form create)
    function identity_form($p) {
        if (isset($p['form']['addressing']) && !empty($p['record']['identity_id'])) {
            $new_fields = [];
            foreach ($p['form']['addressing']['content'] as $col => $colprop) {
                $new_fields[$col] = $colprop;
                if ($col == 'email') {
                    // add ext fields after 'email'
                    foreach ($this->fields as $field => $field_name){
                        $new_fields[$field] = ['type' => 'text', 'size' => 40, 'label' => $this->gettext($field)];
                    }
                }
            }

            if (!$this->rc->config->get('pr_use_question')) {
                unset($new_fields['question']);
                unset($new_fields['answer']);
            }

            $p['form']['addressing']['content'] = $new_fields;

            if($this->user['username']){
                foreach ($this->fields as $field => $field_name){
                    $p['record'][$field] = $this->user[$field];
                }
            }
        }
        return $p;
    }

    // Handler for identity_update hook (executed on identities form submit)
    function identity_update($p) {
        $save = [];
        foreach ($this->fields as $field => $field_name) {
            $save[$field] = rcube_utils::get_input_value("_".$field,rcube_utils::INPUT_POST);
        }

        foreach ($save as $par => $val) {
            if ((!$this->user['username'] && empty($val)) || ($this->user['username'] && $val == $this->user[$par])) {
                unset($save[$par]);
            }
        }

        if ($save['altemail']) {
            $save['altemail'] = rcube_utils::idn_to_ascii($save['altemail']);
            if (empty($save['altemail'])) {
                $this->rc->output->command('display_message', $this->gettext('altemail_cleared'), 'confirmation');
            } else if($save['altemail'] == $p['record']['email']) {
                unset($save['altemail']);
                $p['abort'] = true;
                $p['message'] = $this->gettext('altemail_match_primary');
                return $p;
            } else if(!rcube_utils::check_email($save['altemail'])) {
                unset($save['altemail']);
                $p['abort'] = true;
                $p['message'] = $this->gettext('altemail_invalid');
                return $p;
            }
        }

        if (count($save)) {
            $this->debug("Save user identities: " . print_r($save, true));
            $this->set_user_props($save);
        }

        return $p;
    }

    // Return array - user props (username must be user@domain.ltd)
    function get_user_props($username = null, $with_alias = true) {
        if (!$username) {
            $username = ($this->rc->get_user_name() ? $this->rc->get_user_name() : rcube_utils::get_input_value("_username", rcube_utils::INPUT_GPC));
        }

        $ret = [];
        $user = trim(urldecode($username));
        if ($user) {
            // get user row
            $query = "SELECT u.user_id, u.username, i.email" .
                    " FROM users u" .
                    " INNER JOIN identities i ON i.user_id = u.user_id" .
                    " WHERE username=?";

            $result = $this->rc->db->query($query, $user);

            if ($result && ($arr = $this->rc->db->fetch_assoc($result))) {
                $fields = [];
                foreach ($this->fields as $field => $field_name) {
                    $fields[] = $field_name . " as " . $field;
                }
                // NOTE: cast to int (not a bare boolean) because PDO_PGSQL returns
                // booleans as the strings 't'/'f', and PHP treats 'f' as truthy.
                $query = "SELECT " . implode(",",$fields) . ", token, token_validity,"
                        . " (CASE WHEN token='' OR token_validity < NOW() THEN 1 ELSE 0 END) as token_expired" .
                        " FROM " . $this->rc->config->get('pr_users_table') .
                        " WHERE username=?";

                $result = $this->db->query($query, $arr['email']);
                $ret = array_merge($arr, $this->db->fetch_assoc($result) ?: []);
            } else {
                // for alias (with users_alias plugin), if installed
                if ($with_alias && $this->rc->plugins->load_plugin('users_alias', true, false)) {
                    $users_alias = new users_alias($this->api);
                    $result = $users_alias->alias2user(['user' => $user]);
                    if ($result['user']) {
                        $ret = $this->get_user_props($result['user'], false);
                    }
                }
            }

            $_SESSION['username'] = $ret['username'] ?? null; //for password plugin

            $ret['have_altemail'] = !empty($ret['altemail']);
            $ret['have_code'] = $ret['have_altemail'];
        }

        $this->user = $ret;
        return $ret;
    }

    // Save user props to DB (upsert: the user may not have a row yet)
    // Upsert, portable across Postgres and MySQL (the two drivers this
    // plugin is known to run against). $this->db->db_provider tells us
    // which dialect to use for the "insert or update" clause and for the
    // token_validity interval arithmetic.
    function set_user_props($props) {
        $is_mysql = ($this->db->db_provider === 'mysql');

        $columns = ['username'];
        $placeholders = ['?'];
        $updates = [];
        $params = [$this->user['username']];

        foreach ($this->fields as $field => $field_name) {
            if (isset($props[$field])) {
                $columns[] = $field_name;
                $placeholders[] = '?';
                $params[] = $props[$field];
                $updates[] = $is_mysql ? "$field_name = VALUES($field_name)" : "$field_name = EXCLUDED.$field_name";
            }
        }

        if (isset($props['token'])) {
            $code_validity_time = empty($props['token']) ? 0 : (int) $this->rc->config->get('pr_confirm_code_validity_time', 30);

            $columns[] = 'token';
            $placeholders[] = '?';
            $params[] = $props['token'];
            $updates[] = $is_mysql ? "token = VALUES(token)" : "token = EXCLUDED.token";

            $columns[] = 'token_validity';
            $placeholders[] = $is_mysql ? "DATE_ADD(NOW(), INTERVAL ? MINUTE)" : "NOW() + (? || ' minutes')::interval";
            $params[] = $code_validity_time;
            $updates[] = $is_mysql ? "token_validity = VALUES(token_validity)" : "token_validity = EXCLUDED.token_validity";
        }

        if (count($updates)) {
            $table = $this->rc->config->get('pr_users_table');
            $query = "INSERT INTO " . $table . " (" . implode(",", $columns) . ") VALUES (" . implode(",", $placeholders) . ")"
                   . ($is_mysql
                        ? " ON DUPLICATE KEY UPDATE " . implode(",", $updates)
                        : " ON CONFLICT (username) DO UPDATE SET " . implode(",", $updates));
            $this->db->query($query, $params);
            $this->get_user_props(); //update user props
            $this->debug("Update user '" . $this->user['username'] . "' props: " . print_r($props, true));
            // NOTE: not using affected_rows() here — MySQL's ON DUPLICATE KEY
            // UPDATE reports 0 affected rows when the new values are
            // identical to the existing ones, which would look like failure.
            return !$this->db->is_error();
        }
        return false;
    }

    function update_confirm_code_count($plus = 0) {
        $count = $this->get_confirm_code_count() + $plus;
        $_SESSION['pr_confirm_code_count'] = $count;
    }

    function get_confirm_code_count() {
        if (!isset($_SESSION['pr_confirm_code_count'])) {
            $_SESSION['pr_confirm_code_count'] = 0;
        }
        return $_SESSION['pr_confirm_code_count'];
    }

    /*******************
    * service functions
    *******************/

    function get_dbh() {
        if (!$this->db) {
            if ($dsn = $this->rc->config->get('pr_db_dsn')) {
                $this->db = rcube_db::factory($dsn);
                $this->db->set_debug((bool)$this->rc->config->get('sql_debug'));
            }
            else {
                $this->db = $this->rc->get_dbh();
            }
        }
        return $this->db;
    }

    /*******************
    * multi-domain config (pr_domains)
    *******************/

    // The vpopmaild admin account for a given account's domain is never
    // configured directly: only postmaster@<domain> has the privilege to
    // reset another mailbox's password via vpopmaild, so the username is
    // always derived. Only the password is per-domain data.
    // Falls back to the flat pr_vpopmaild_admin_pass for installs that
    // haven't migrated to pr_domains yet (single-domain, unchanged).
    function resolve_vpopmaild_admin($username) {
        $domain = strtolower(substr(strrchr($username, '@'), 1));
        $entry  = $this->rc->config->get('pr_domains', [])[$domain] ?? [];

        return [
            'user' => 'postmaster@' . $domain,
            'pass' => $entry['vpopmaild_admin_pass'] ?? $this->rc->config->get('pr_vpopmaild_admin_pass'),
        ];
    }

    // Resolves which SMTP account/server/from-address to use to send the
    // confirmation code for a given account's domain, in this order:
    //   1. an explicit alternate account for that domain (pr_domains[domain]['smtp_user'])
    //   2. that domain explicitly marked as accepting relay without auth
    //      (pr_domains[domain]['smtp_auth'] === false)
    //   3. the legacy global flat account (pr_default_smtp_user/pass), if set
    //      - keeps existing single-account installs working unchanged
    //   4. default: authenticate as that domain's own postmaster@<domain>,
    //      reusing its vpopmaild admin password (pr_smtp_auth_default)
    // An empty 'user' in the result means: connect without authenticating.
    // 'from' precedence: pr_domains[domain]['from'] (explicit, full address)
    // > that domain's own smtp_user, if set (already a real address in that
    // domain) > pr_replyto_email + '@' + domain being recovered. A cross-
    // domain fixed reply-to (or one hardcoded to a single domain) commonly
    // gets rejected by an unauthenticated relay and hurts SPF/DKIM anyway,
    // so pr_replyto_email is deliberately just a LOCAL PART (e.g. 'noreply'),
    // not a full address - if it does contain '@' it's used as-is instead,
    // for installs that genuinely want one fixed address everywhere.
    function resolve_smtp_account($username) {
        $domain = strtolower(substr(strrchr($username, '@'), 1));
        $entry  = $this->rc->config->get('pr_domains', [])[$domain] ?? [];
        $server = $entry['smtp_server'] ?? $this->rc->config->get('pr_default_smtp_server');

        $replyto = $this->rc->config->get('pr_replyto_email', 'noreply');
        $default_from = (strpos($replyto, '@') !== false) ? $replyto : ($replyto . '@' . $domain);
        $from = $entry['from'] ?? null;

        if (!empty($entry['smtp_user'])) {
            return ['server' => $server, 'user' => $entry['smtp_user'], 'pass' => $entry['smtp_pass'] ?? '', 'from' => $from ?: $entry['smtp_user']];
        }

        if (isset($entry['smtp_auth']) && $entry['smtp_auth'] === false) {
            return ['server' => $server, 'user' => '', 'pass' => '', 'from' => $from ?: $default_from];
        }

        if ($global_user = $this->rc->config->get('pr_default_smtp_user')) {
            return ['server' => $server, 'user' => $global_user, 'pass' => $this->rc->config->get('pr_default_smtp_pass'), 'from' => $from ?: $default_from];
        }

        if ($this->rc->config->get('pr_smtp_auth_default', true)) {
            $admin = $this->resolve_vpopmaild_admin($username);
            return ['server' => $server, 'user' => $admin['user'], 'pass' => $admin['pass'], 'from' => $from ?: $default_from];
        }

        return ['server' => $server, 'user' => '', 'pass' => '', 'from' => $from ?: $default_from];
    }

    function get_action() {
        $action = rcube_utils::get_input_value('_a', rcube_utils::INPUT_GPC);
        if (!$action || empty($action)) {
            $action = 'init';
        }
        return $action;
    }

    function logging($text) {
        if ($this->rc->config->get('pr_password_log') == true) {
            rcube::write_log('password', $text);
        }
    }

    function debug($text) {
        if ($this->rc->config->get('pr_debug') == true) {
            $msg = (is_array($text) ? print_r($text, true) : $text);
            rcube::write_log('console', $msg);
        }
    }
}

?>
