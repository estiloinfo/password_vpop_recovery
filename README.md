# Password Recovery — Roundcube

*[Versión en español (README.es.md)](README.es.md)*

A Roundcube plugin that lets a user regain access to their mailbox after
forgetting their password, with no administrator involved.

This is an adaptation of the original plugin
([AlfnRU/roundcube-password_recovery](https://github.com/AlfnRU/roundcube-password_recovery))
for this specific installation: **vpopmail** backend (via `vpopmaild`)
instead of a Postfix/MySQL database, with two-factor verification, real rate
limiting, and a password policy with live visual feedback.

## How it works

1. On the login screen, the user clicks **"Forgot password?"**.
2. They're asked for **both** their institutional account
   (`user@your.domain.com`) **and** the recovery email address they set
   earlier under Settings → Identities.
3. If both match what's on file, a 6-digit code is sent to that recovery
   address (valid for 30 minutes).
4. The user enters the code along with a new password. The password field
   shows live feedback on whether it meets the required policy, and the
   "Save" button stays disabled until every condition is met.
5. On save, the plugin changes the account's real password by authenticating
   against `vpopmaild` with a domain-admin account (see below) — the user
   never needs their previous password.

**By design, the response the user sees is always the same** regardless of
whether the account doesn't exist, the recovery email doesn't match, or the
rate limit was exceeded. This is intentional: it prevents the form from
being used to brute-force which accounts exist or which personal email is
linked to an institutional account.

### Rate limiting

Every recovery attempt is logged in the `password_recovery_attempts` table
(account + IP + timestamp), **independent of browser cookies/session** — a
script that doesn't keep a session cookie can't bypass this limit. Defaults:

- max **5 attempts per account** per 24h,
- max **20 attempts per source IP** per 24h (protects against one source
  probing many different accounts).

### Password policy

The new password must be 8–16 characters long (configurable, see the
`password` plugin's `password_minimum_length`/`password_maximum_length`),
include at least one digit and at least one special character. It's
validated both in the browser (live visual feedback) and on the server (in
case someone bypasses JavaScript).

## Tested with

- Roundcube 1.6.17, PHP 8.4.24
- PostgreSQL 15.18 (this installation's actual database)
- MariaDB 11.8.6 (the MySQL-flavored code paths — `ON DUPLICATE KEY UPDATE`,
  `DATE_ADD`/`INTERVAL` arithmetic, and the rate-limit cleanup query — were
  verified directly against a real MariaDB instance, not just reasoned
  through)
- **SQL driver** (`pr_password_driver = 'sql'`): exercised end-to-end against
  a synthetic `vpopmail`-schema table on that same MariaDB instance — all
  three hash schemes (`crypt-md5`, `crypt-blowfish`, `system`) produce a hash
  that verifies correctly with PHP's own `crypt()`, `pr_sql_store_clear`
  writes the plaintext column correctly, and a non-matching (user, domain)
  correctly reports failure instead of a false success. This installation's
  real vpopmail is CDB-backed (via `vpopmaild`), **not** SQL-backed, so this
  could not be validated against a real vpopmail-on-MySQL install — anyone
  using the SQL driver in production **must** verify the resulting hash
  against their own vpopmail (e.g. compare with a password changed via
  `vpasswd`) before trusting it — see "Password driver" below.

## Installation

These steps assume a working Roundcube install with:
- its own database in **PostgreSQL or MySQL/MariaDB** (the same one
  Roundcube already uses — no separate database needed). The plugin
  auto-detects which one via `rcube_db::db_provider`, so no extra config is
  needed either way.
- mail authentication via **vpopmail**, with the `password` plugin
  configured with `password_driver = 'vpopmaild'`.

### 1. Copy the plugin and enable it

```bash
# already in place on this installation:
#   /usr/share/roundcube/plugins/password_vpop_recovery
```

Add `'password_vpop_recovery'` to `$config['plugins']` in
`/etc/roundcube/config.inc.php`.

**Debian/Ubuntu packaged Roundcube (Debian 12+ `roundcube-core`) needs one
extra step**: the actual plugin loader reads from `/var/lib/roundcube/plugins/`,
not directly from `/usr/share/roundcube/plugins/` — every plugin shipped by
the distro is a symlink there. Without this symlink the plugin fails to load
with `Failed to load plugin file ...` and, if that error happens on every
page, the whole webmail breaks, not just recovery:

```bash
ln -s /usr/share/roundcube/plugins/password_vpop_recovery /var/lib/roundcube/plugins/password_vpop_recovery
```

(On installs where `plugins/` isn't a symlink farm — e.g. Roundcube
installed from source rather than via `.deb` — this step doesn't apply.)

### 2. Create the tables in Roundcube's own database

The plugin **does not use a separate database**: it stores its data in the
same database Roundcube already uses (no extra credentials to manage, and
it never touches the vpopmail database at all). Use whichever block matches
your Roundcube's `db_dsnw`.

**PostgreSQL:**

```sql
CREATE TABLE IF NOT EXISTS password_recovery_data (
    username        VARCHAR(128) PRIMARY KEY,
    alt_email       VARCHAR(128) NOT NULL DEFAULT '',
    token           VARCHAR(255) NOT NULL DEFAULT '',
    token_validity  TIMESTAMP NOT NULL DEFAULT '2000-01-01 00:00:00'
);

CREATE TABLE IF NOT EXISTS password_recovery_attempts (
    id          SERIAL PRIMARY KEY,
    username    VARCHAR(128) NOT NULL,
    ip          VARCHAR(64) NOT NULL,
    created_at  TIMESTAMP NOT NULL DEFAULT NOW()
);
CREATE INDEX IF NOT EXISTS idx_pra_username_time ON password_recovery_attempts (username, created_at);
CREATE INDEX IF NOT EXISTS idx_pra_ip_time ON password_recovery_attempts (ip, created_at);
```

**MySQL / MariaDB:**

```sql
CREATE TABLE IF NOT EXISTS password_recovery_data (
    username        VARCHAR(128) PRIMARY KEY,
    alt_email       VARCHAR(128) NOT NULL DEFAULT '',
    token           VARCHAR(255) NOT NULL DEFAULT '',
    token_validity  DATETIME NOT NULL DEFAULT '2000-01-01 00:00:00'
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS password_recovery_attempts (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    username    VARCHAR(128) NOT NULL,
    ip          VARCHAR(64) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pra_username_time (username, created_at),
    INDEX idx_pra_ip_time (ip, created_at)
) ENGINE=InnoDB;
```

(`DATETIME` rather than `TIMESTAMP` on purpose — MySQL's `TIMESTAMP` columns
silently auto-update on every row change unless explicitly told not to,
which is not what we want for `token_validity`/`created_at`.)

### 3. vpopmail admin accounts (one per domain)

`vpopmaild` requires authenticating before it will modify an account — and a
self-`slogin` isn't enough, because during recovery the user by definition
doesn't have their old password. The fix is to authenticate with an account
that has **domain-admin privileges** in vpopmail, which *can* run `mod_user`
against *any* account in that domain — and only `postmaster@<domain>` has
that privilege.

Because the admin **username** is therefore always `postmaster@<domain>`, it
never needs to be configured explicitly — only that account's **password**,
once per domain (see `pr_domains` in step 5). If `postmaster@<domain>`
doesn't exist yet for a given domain, create/enable it on the mail server
first.

### 4. Sending (SMTP) account

The confirmation code is sent **before login**, at a point where Roundcube
has no active IMAP session yet — so `smtp_user`/`smtp_pass = '%u'/'%p'` from
the main config can't be reused.

By default, each domain sends its confirmation email authenticating via
SMTP-AUTH as its own `postmaster@<domain>`, reusing the **same password**
already configured for vpopmaild in step 3 — vpopmail shares one password
across IMAP/POP/SMTP-AUTH for a mailbox, so in the common case there's
nothing extra to set up here. Override this per domain in `pr_domains` only
when it doesn't apply:

- **a different, dedicated sending account** (`smtp_user`/`smtp_pass`) — the
  usual case when Roundcube and the mail server are on separate hosts and
  the relay you actually send through doesn't accept the postmaster's own
  credentials, or you'd simply rather not reuse that password for SMTP;
- **`smtp_auth => false`** — this domain's relay accepts mail unauthenticated
  (only if your MTA setup genuinely allows it and has proper SPF/DKIM —
  otherwise the mail ends up in spam or gets bounced). In this case the
  From/Reply-To address also defaults to `noreply@<domain>` instead of the
  global `pr_replyto_email` — an unauthenticated local relay commonly only
  accepts mail `FROM` its own domain, so a cross-domain reply-to would get
  rejected. Override it with `'from'` if you need something else;
- **`smtp_server`** — this domain's relay is a different host from the
  shared `pr_default_smtp_server`.

### 5. Configure the plugin's `config.inc.php`

The plugin ships its own built-in defaults in `config.inc.default.php`
(loaded automatically, first, before the real config — see its header
comment for details). **`config.inc.php` only needs to declare secrets and
whatever differs from those defaults** — there's no need to copy every
setting over, and nothing to keep in sync by hand across upgrades.

On Debian/Ubuntu packaged Roundcube, every plugin's `config.inc.php` under
`/usr/share/roundcube/plugins/<name>/` is actually a symlink to a real file
under `/etc/roundcube/plugins/<name>/` — check any core plugin (e.g.
`password`) and you'll see the same pattern. This keeps host-specific
config in `/etc` (survives package upgrades) instead of under `/usr/share`
(package-managed). Follow the same convention here:

```bash
mkdir -p /etc/roundcube/plugins/password_vpop_recovery
cp /usr/share/roundcube/plugins/password_vpop_recovery/config.inc.php.dist \
   /etc/roundcube/plugins/password_vpop_recovery/config.inc.php
ln -s /etc/roundcube/plugins/password_vpop_recovery/config.inc.php \
      /usr/share/roundcube/plugins/password_vpop_recovery/config.inc.php
```

(If you're not on a Debian-packaged Roundcube, it's fine to just keep
`config.inc.php` directly inside the plugin's own directory instead — skip
the symlink and edit it in place.)

Fill in at least:

| Variable | What it is |
|---|---|
| `pr_replyto_email` | sender address for the confirmation-code emails |
| `pr_default_smtp_server` | shared SMTP relay for domains that don't override it |
| `pr_domains` | one entry per mail domain, see below |

**Multi-domain configuration (`pr_domains`)** — one entry per domain this
install serves:

```php
$config['pr_domains'] = [
    'your.domain.com' => [
        'vpopmaild_admin_pass' => 'CHANGEME',   // postmaster@your.domain.com
    ],
    'other.domain.com' => [
        'vpopmaild_admin_pass' => 'CHANGEME',
        'smtp_auth' => false,                   // this domain's relay needs no auth
        // 'from' defaults to noreply@other.domain.com here; set it
        // explicitly to override (works for any domain, not just this case)
    ],
    'third.domain.com' => [
        'vpopmaild_admin_pass' => 'CHANGEME',
        'smtp_user'   => 'alerts@thirdparty.example', // dedicated sending account
        'smtp_pass'   => 'CHANGEME',
        'smtp_server' => 'smtp.thirdparty.example:587',
    ],
];
```

A single-domain install can instead use the flat legacy shorthand
(`pr_vpopmaild_admin_pass`, `pr_default_smtp_user`, `pr_default_smtp_pass`) —
see the comments in `config.inc.php.dist` for the exact fallback order.

**Password driver (`pr_password_driver`)** — how the plugin actually resets
the password on the mail server:

| Driver | When to use it |
|---|---|
| `'vpopmaild'` (default) | Works with any vpopmail install, CDB or SQL-backed. Needs one postmaster password per domain (`pr_domains` above). |
| `'sql'` | Only if vpopmail itself runs on a SQL backend (MySQL/MariaDB). Writes directly to vpopmail's own table with ONE set of DB credentials for every domain — scales much better past a handful of domains, at the cost of needing to validate the password hash against your specific vpopmail build (see below). |

To use the SQL driver:

```php
$config['pr_password_driver'] = 'sql';
// Same DSN format as Roundcube's own db_dsnw (this reuses rcube_db,
// just pointed at vpopmail's database instead of Roundcube's own).
$config['pr_sql_dsn'] = 'mysql://vpopmail_admin:CHANGEME@127.0.0.1/vpopmail';
```

`pr_sql_table`/`pr_sql_columns` default to vpopmail's standard MySQL schema
(`vpopmail` table, `pw_name`/`pw_domain`/`pw_passwd`/`pw_clear_passwd`
columns) and only need overriding if your vpopmail was compiled with a
different one. `pr_sql_hash_scheme` (`crypt-md5` default, or
`crypt-blowfish`/`system`) **must match how your vpopmail was compiled** —
a mismatched scheme won't error out, it will just silently produce a
password vchkpw can't verify. Validate it before relying on this in
production: change a real account's password through the plugin, then
confirm the mail server still accepts the new password for IMAP/POP login.

**Don't point `pr_sql_dsn` at vpopmail's own admin account.** Create a
dedicated MySQL/MariaDB user with the least privilege this driver actually
needs — `UPDATE` on just the two columns it writes, nothing else (no
`SELECT`, `INSERT`, `DELETE`, or access to any other table), scoped to the
exact host Roundcube connects from:

```sql
CREATE USER 'rc_pwreset'@'127.0.0.1' IDENTIFIED BY 'a-long-unique-password';
GRANT UPDATE (pw_passwd, pw_clear_passwd) ON vpopmail.vpopmail TO 'rc_pwreset'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Adjust the database/table/column names to match your schema. The host
(`'127.0.0.1'`) should be whatever address the connection actually
originates from as seen by MariaDB.

**Important — permissions**: this file contains plaintext passwords (SMTP
and vpopmaild/SQL credentials). Apply this on the REAL file (the one under
`/etc/roundcube/plugins/...` if you followed the symlink approach above —
the symlink itself doesn't need special permissions, it just points there):

```bash
chown root:www-data /etc/roundcube/plugins/password_vpop_recovery/config.inc.php
chmod 640 /etc/roundcube/plugins/password_vpop_recovery/config.inc.php
```

(`www-data` is the user PHP-FPM/nginx runs as on this installation — adjust
if your web server runs as a different user. Note this is stricter than
Debian's own default for other plugins' configs, which ship `644 root:root`
— world-readable. That's fine for configs with no secrets, but this file
has real passwords in it, so it's worth the deviation.)

⚠️ Every time this file is edited with a tool that rewrites the whole file,
double-check the owner/group afterwards — some editors recreate the file and
leave it `root:root`, which silently makes it unreadable by `www-data` and
causes Roundcube to fall back to defaults without any warning.

### 6. Setting each user's recovery email

Each user can set their own recovery email under **Settings → Identities →
(their identity) → "Recovery e-mail"**.

To load many accounts at once (e.g. when rolling this out), there's
`bin/import_recovery_emails.php`, which reads a two-column CSV (`cuenta`,
`recuperacion`) and runs the same validations as the form:

```bash
php bin/import_recovery_emails.php accounts.csv --dry-run   # preview
php bin/import_recovery_emails.php accounts.csv             # apply
```

It's safe to re-run against accounts you've already imported — existing
recovery emails are left alone unless you pass `--force`:

| Situation | Without `--force` | With `--force` |
|---|---|---|
| Account doesn't exist, no `--create-missing` | Error | Error |
| Account doesn't exist, with `--create-missing` | Creates it + sets the email | Creates it + sets the email |
| Account exists, no recovery email set yet | Sets it | Sets it |
| Account exists, already has a recovery email | Skipped | Overwritten |

**Bulk-provisioning new mailboxes**: Roundcube only creates its own
`users`/`identities` rows the first time someone actually logs in — it has
no idea an account exists on the mail server otherwise. If you're creating
many mailboxes on vpopmail at once and don't want to log into each one just
so recovery works, add `--create-missing`: for any account in the CSV that
Roundcube doesn't know about yet, it creates the user and a default identity
(exactly what a real first login would do) before setting the recovery
email — one pass instead of one login per account:

```bash
php bin/import_recovery_emails.php accounts.csv --create-missing
```

## Screenshots

**Recovery link on the login screen**
!["Forgot password?" link](docs/login-link.png)

**Form asking for account + recovery email**
![Recovery form](docs/recovery-form.png)

**Code + new password form (with the live policy checklist)**
![New password](docs/new-password-form.png)

**Where to set the recovery email (Settings → Identities)**
![Recovery email in Identities](docs/identity-settings.png)

## Languages

Includes Spanish localization (`localization/es_ES.inc` +
`localization/es_ES/*.html`), which Roundcube picks up automatically for
browsers set to `es_ES`/`es_AR`/`es_*` (built-in Roundcube alias). Only
en_US and es_ES are maintained — the other languages shipped with the
original plugin (de_DE, fr_FR, it_IT, ru_RU, sv_SE) were removed, since they
still had strings for features this adaptation doesn't have (secret
question, SMS, admin alerts) and nobody here can keep them in sync. A
browser requesting any of those falls back to en_US automatically.

## What this adaptation deliberately does NOT have

Removed from the original flow to keep things simple and avoid maintaining
mechanisms nobody uses:

- **Secret question**: less secure, not used.
- **SMS**: requires a gateway that isn't available here.
- **Automatic admin notification** when a user is missing their recovery
  data: instead, they simply can't self-recover (this avoids that
  notification itself becoming another channel that leaks which accounts
  exist).
