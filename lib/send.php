<?php

class password_vpop_recovery_send {

    private $rc;
    private $pr;
    private $user;

    function __construct($pr_plugin) {
        $this->pr = $pr_plugin;
        $this->rc = $pr_plugin->rc;
        $this->user = $pr_plugin->user;
    }

    // Send E-Mail
    function send_email($to, $from, $subject, $body, $smtp_account = null) {
        $ctb = md5(rand() . microtime());
        $subject = "=?UTF-8?B?".base64_encode($subject)."?=";

        $msgid_domain = substr(strrchr($from, "@"), 1) ?: 'localhost';

        $headers  = "Return-Path: $from\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: multipart/alternative; boundary=\"=_$ctb\"\r\n";
        $headers .= "Date: " . date('r', time()) . "\r\n";
        $headers .= "Message-ID: <$ctb@$msgid_domain>\r\n";
        $headers .= "From: $from\r\n";
        $headers .= "To: $to\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "Reply-To: $from\r\n";

        $txt_body  = "--=_$ctb\r\n";
        $txt_body .= "\r\n";
        $txt_body .= "Content-Transfer-Encoding: 7bit\r\n";
        $txt_body .= "Content-Type: text/plain; charset=" . $this->rc->config->get('default_charset', RCUBE_CHARSET) . "\r\n";

        $h2t = new rcube_html2text($body, false, true, 0);
        $txt = rcube_mime::wordwrap($h2t->get_text(), $this->rc->config->get('line_length', 75), "\r\n");
        $txt = wordwrap($txt, 998, "\r\n", true);
        $txt_body .= "$txt\r\n";
        $txt_body .= "--=_$ctb";
        $txt_body .= "\r\n";

        $msg_body = "Content-Type: multipart/alternative; boundary=\"=_$ctb\"\r\n\r\n";
        $msg_body .= $txt_body;
        $msg_body .= "Content-Transfer-Encoding: quoted-printable\r\n";
        $msg_body .= "Content-Type: text/html; charset=" . $this->rc->config->get('default_charset', RCUBE_CHARSET) . "\r\n\r\n";
        $msg_body .= str_replace("=","=3D",$body);
        $msg_body .= "\r\n\r\n";
        $msg_body .= "--=_$ctb--";
        $msg_body .= "\r\n\r\n";

        // send message
        if (!is_object($this->rc->smtp)) {
            $this->rc->smtp_init(true);
        }

        if($this->rc->config->get('smtp_pass') == "%p") {
            $account = $smtp_account ?: ['server' => $this->rc->config->get('pr_default_smtp_server'), 'user' => '', 'pass' => ''];
            $this->rc->config->set('smtp_server', $account['server']);
            $this->rc->config->set('smtp_user', $account['user']);
            $this->rc->config->set('smtp_pass', $account['pass']);
        }

        $this->rc->smtp->connect();
        if($this->rc->smtp->send_mail($from, $to, $headers, $msg_body)) {
            return true;
        } else {
            rcube::write_log('errors', 'response:' . print_r($this->rc->smtp->get_response(),true));
            rcube::write_log('errors', 'errors:' . print_r($this->rc->smtp->get_error(),true));
            return false;
        }
    }

    // Send code to user (by alternate email only)
    function send_confirm_code_to_user() {
        $send_email = false;
        $confirm_code = $this->generate_confirm_code();
        $crypted_code = password_hash($confirm_code, PASSWORD_DEFAULT);

        if ($confirm_code && $this->pr->set_user_props(['token'=>$crypted_code])) {
            if ($this->user['have_altemail']) {
                $file = $this->get_localization_dir($_SESSION['language'] ?? null) . "/reset_pw_body.html";
                $link = "http://{$_SERVER['SERVER_NAME']}/?_task=login&_action=plugin.password_vpop_recovery&_username=". $this->user['username'];
                $body = strtr(file_get_contents($file), ['[LINK]' => $link, '[CODE]' => $confirm_code]);
                $subject = $this->pr->gettext('email_subject');

                $from = $this->rc->config->get('pr_replyto_email');
                if(!$from){
                    $from = $this->get_email_from($this->rc->config->get('pr_admin_email'));
                }

                $send_email = $this->send_email(
                    $this->user['altemail'],
                    $from,
                    $subject,
                    $body,
                    $this->pr->resolve_smtp_account($this->user['username'])
                );
            }

            if ($send_email) {
                $this->pr->logging("Send password recovery code [". $confirm_code . "] for '" . $this->user['username'] . "' to alt email: '" . $this->user['altemail'] . "'");
                $message = $this->pr->gettext('check_account') . $this->pr->gettext('check_email');
            } else {
                $this->pr->set_user_props(['token'=>'', 'token_validity'=>'']);
                $message = $this->pr->gettext('send_failed');
            }
        } else {
            $message = $this->pr->gettext('write_failed');
        }

        return [
            'send' => $send_email,
            'message' => $message
        ];
    }

    // Generate and return a random code
    function generate_confirm_code() {
        $code_length = (int) $this->rc->config->get('pr_confirm_code_length', 6);
        $code = "";
        $possible = "0123456789";
        while (strlen($code) < $code_length) {
            $random = random_int(0, strlen($possible)-1);
            $char = substr($possible, $random, 1);
            $code .= $char;
            $possible = str_replace($char,"",$possible); //removing the used character from the possible
        }
        return $code;
    }

    function get_email_from($email) {
        $parts = explode('@',$email);
        return 'no-reply@'.$parts[1];
    }

    function get_localization_dir($language) {
        $base = dirname(__FILE__) . "/../localization/";
        $lang = $language ?: 'en_US';

        if (is_dir($base . $lang)) {
            return $base . $lang;
        }

        // fall back to any localization matching the language prefix (e.g. es_AR -> es_ES)
        $short = substr($lang, 0, 2);
        foreach ((glob($base . $short . '_*', GLOB_ONLYDIR) ?: []) as $dir) {
            return $dir;
        }

        return $base . 'en_US';
    }
}

?>
