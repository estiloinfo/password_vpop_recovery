
if (window.rcmail) {
    rcmail.addEventListener('init', function(evt) {
        var loginform = $('#login-form');
        if (loginform) {
            loginform.append('<a class="home" id="password_forgot" href="javascript:forgot_password();">' + rcmail.gettext('forgot_password','password_vpop_recovery') + '</a>');
        }

        var newpasswordform = $('#new-password-form');
        if (newpasswordform.length && rcmail.env.pr_use_confirm_code) {
            newpasswordform.append('<a class="home" id="renew_confirm_code" href="javascript:renew_confirm_code();">' + rcmail.gettext('renew_code','password_vpop_recovery') + '</a>');
        }

        rcmail.register_command('plugin.password_vpop_recovery.cancel', function() {
            rcmail.http_request('plugin.password_vpop_recovery', { '_a':'cancel' });
        }, true);

        rcmail.register_command('plugin.password_vpop_recovery.reset', function() {
            var input_username = rcube_find_object('_username'),
                input_altemail = rcube_find_object('_altemail');

            if (input_username && input_username.value == '') {
                rcmail.alert_dialog(rcmail.get_label('no_username', 'password_vpop_recovery'), function() {
                    input_username.focus();
                    return true;
                });
            }
            else if (input_altemail && input_altemail.value == '') {
                rcmail.alert_dialog(rcmail.get_label('no_altemail', 'password_vpop_recovery'), function() {
                    input_altemail.focus();
                    return true;
                });
            }
            else {
                rcmail.gui_objects.recoverypasswordform.submit();
            }
        }, true);

        rcmail.register_command('plugin.password_vpop_recovery.save', function() {
            var input_code = rcube_find_object('_code'),
                input_answer = rcube_find_object('_answer'),
                input_newpassword = rcube_find_object('_newpassword'),
                input_newpassword_confirm = rcube_find_object('_newpassword_confirm');

            if (rcmail.env.pr_use_confirm_code && input_code && input_code.value == '') {
                rcmail.alert_dialog(rcmail.get_label('no_code', 'password_vpop_recovery'), function() {
                    input_code.focus();
                    return true;
                });
            }
            else if (rcmail.env.pr_use_question && input_answer && input_answer.value == '') {
                rcmail.alert_dialog(rcmail.get_label('no_answer', 'password_vpop_recovery'), function() {
                    input_answer.focus();
                    return true;
                });
            }
            else if (input_newpassword && input_newpassword.value == '') {
                rcmail.alert_dialog(rcmail.get_label('no_password', 'password_vpop_recovery'), function() {
                    input_newpassword.focus();
                    return true;
                });
            }
            else if (input_newpassword_confirm && input_newpassword_confirm.value == '') {
                rcmail.alert_dialog(rcmail.get_label('no_password_confirm', 'password_vpop_recovery'), function() {
                    input_newpassword_confirm.focus();
                    return true;
                });
            }
            else if (input_newpassword && input_newpassword_confirm && input_newpassword.value != input_newpassword_confirm.value) {
                rcmail.alert_dialog(rcmail.get_label('password_inconsistency', 'password_vpop_recovery'), function() {
                    input_newpassword.focus();
                    return true;
                });
            }
            else if (input_newpassword && !pr_check_password_policy(input_newpassword.value).every(function(r) { return r.ok; })) {
                rcmail.alert_dialog(rcmail.get_label('password_too_short', 'password_vpop_recovery').replace('%d', rcmail.env.pr_password_minimum_length), function() {
                    input_newpassword.focus();
                    return true;
                });
            }
            else {
                rcmail.gui_objects.newpasswordform.submit();
            }
        }, false);

        if (newpasswordform.length) {
            pr_init_password_policy();
        }

        $('input:not(:hidden)').first().focus();
    });
}

function forgot_password() {
    var url = "./?_task=login&_action=plugin.password_vpop_recovery";
/*    var input_user = rcube_find_object('_user');
    if (input_user && input_user.value != '') {
        url = url + "&_u=" + input_user.value;
    }*/
    document.location.href = url;
}

function renew_confirm_code() {
    rcmail.http_request('plugin.password_vpop_recovery', { '_a':'renew', '_username':rcmail.env.pr_username });
}

// Returns the password policy rules with their current pass/fail state,
// mirroring exactly what password_vpop_recovery_pwd::_check_strength() enforces
// server-side (see lib/password.php).
function pr_check_password_policy(pwd) {
    var minlen = rcmail.env.pr_password_minimum_length || 0;
    var maxlen = rcmail.env.pr_password_maximum_length || 0;
    var confirm_input = rcube_find_object('_newpassword_confirm');

    var length_label = maxlen
        ? rcmail.get_label('policy_length', 'password_vpop_recovery').replace('%d', minlen).replace('%d', maxlen)
        : rcmail.get_label('password_too_short', 'password_vpop_recovery').replace('%d', minlen);

    return [
        {
            rule: 'length',
            label: length_label,
            ok: pwd.length >= minlen && (!maxlen || pwd.length <= maxlen)
        },
        {
            rule: 'digit',
            label: rcmail.get_label('policy_digit', 'password_vpop_recovery'),
            ok: /[0-9]/.test(pwd)
        },
        {
            rule: 'special',
            label: rcmail.get_label('policy_special', 'password_vpop_recovery'),
            ok: /[^A-Za-z0-9]/.test(pwd)
        },
        {
            rule: 'match',
            label: rcmail.get_label('policy_match', 'password_vpop_recovery'),
            ok: pwd.length > 0 && confirm_input && pwd === confirm_input.value
        }
    ];
}

function pr_init_password_policy() {
    var input_newpassword = rcube_find_object('_newpassword'),
        input_newpassword_confirm = rcube_find_object('_newpassword_confirm');

    if (!input_newpassword || !input_newpassword_confirm) {
        return;
    }

    if (!$('#pr-policy-style').length) {
        $('head').append(
            '<style id="pr-policy-style">' +
            '.pr-password-policy { list-style:none; margin:2px 0 10px; padding:0; font-size:12px; text-align:left; display:inline-block; }' +
            '.pr-password-policy li { color:#999; line-height:1.6em; }' +
            '.pr-password-policy li:before { content:"\\25CB"; display:inline-block; width:1.3em; }' +
            '.pr-password-policy li.pr-ok { color:#2e7d32; }' +
            '.pr-password-policy li.pr-ok:before { content:"\\2713"; }' +
            '</style>'
        );
    }

    var rules = pr_check_password_policy('');
    var $list = $('<ul class="pr-password-policy"></ul>');
    rules.forEach(function(r) {
        $list.append($('<li></li>').attr('data-rule', r.rule).text(r.label));
    });

    $(input_newpassword_confirm).closest('tr').after(
        $('<tr></tr>').append('<td class="title">&nbsp;</td>').append($('<td class="input"></td>').append($list))
    );

    function update() {
        var pwd = input_newpassword.value;
        var results = pr_check_password_policy(pwd);
        var all_ok = true;

        results.forEach(function(r) {
            $list.find('li[data-rule="' + r.rule + '"]').toggleClass('pr-ok', r.ok).text(r.label);
            if (!r.ok) {
                all_ok = false;
            }
        });

        rcmail.enable_command('plugin.password_vpop_recovery.save', all_ok && pwd.length > 0);
    }

    $(input_newpassword).on('input', update);
    $(input_newpassword_confirm).on('input', update);

    update();
}
