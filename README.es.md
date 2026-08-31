# Password Recovery — Roundcube

*[English version (README.md)](README.md)*

Plugin de Roundcube que permite a un usuario recuperar el acceso a su casilla
cuando olvidó la contraseña, sin intervención de un administrador.

Esta es una adaptación del plugin original
([AlfnRU/roundcube-password_recovery](https://github.com/AlfnRU/roundcube-password_recovery))
para esta instalación puntual: backend **vpopmail** (vía `vpopmaild`) en vez de
una base Postfix/MySQL, con verificación de dos datos, límite de intentos real
y política de contraseña con feedback visual.

## Cómo funciona

1. En la pantalla de login, el usuario hace clic en **"¿Olvidó su
   contraseña?"**.
2. Se le pide **la cuenta institucional** (`usuario@tu-dominio.com`) **y**
   el **correo electrónico de recuperación** que haya cargado antes en
   Configuración → Identidades.
3. Si ambos datos coinciden con lo que hay cargado, se envía un código de 6
   dígitos a ese correo de recuperación (válido por 30 minutos).
4. El usuario ingresa el código junto con la nueva contraseña. El campo de
   contraseña muestra en vivo si cumple la política exigida, y el botón
   "Guardar" queda deshabilitado hasta que se cumplan todas las condiciones.
5. Al guardar, el plugin cambia la contraseña real de la cuenta autenticándose
   contra `vpopmaild` con una cuenta de administrador de dominio (ver más
   abajo) — el usuario nunca necesita su contraseña anterior.

**Por diseño, la respuesta que ve el usuario es siempre la misma** sin
importar si la cuenta no existe, si el correo de recuperación no coincide, o
si se superó el límite de intentos. Esto es intencional: evita que el
formulario pueda usarse para adivinar por fuerza bruta qué cuentas existen o
cuál es el correo personal asociado a una cuenta institucional.

### Límite de intentos (rate limiting)

Cada intento de recuperación se registra en la tabla
`password_recovery_attempts` (cuenta + IP + fecha/hora), **independiente de
cookies o sesión del navegador** — un script que no mantenga sesión no puede
saltarse este límite. Por defecto:

- máximo **5 intentos por cuenta** cada 24hs,
- máximo **20 intentos por IP de origen** cada 24hs (protege contra un mismo
  origen probando muchas cuentas distintas).

### Política de contraseña

La nueva contraseña debe tener entre 8 y 16 caracteres (configurable, ver
`password_minimum_length`/`password_maximum_length` del plugin `password`),
incluir al menos un número y al menos un carácter especial. Se valida tanto
en el navegador (feedback visual en vivo) como en el servidor (por si alguien
evita el JavaScript).

## Probado con

- Roundcube 1.6.17, PHP 8.4.24
- PostgreSQL 15.18 (la base real de esta instalación)
- MariaDB 11.8.6 (las partes del código específicas de MySQL —
  `ON DUPLICATE KEY UPDATE`, la aritmética con `DATE_ADD`/`INTERVAL`, y la
  limpieza del rate limiting— se verificaron contra una instancia real de
  MariaDB, no solo por análisis de sintaxis)
- **Driver SQL** (`pr_password_driver = 'sql'`): probado de punta a punta
  contra una tabla sintética con el esquema de `vpopmail` en esa misma
  MariaDB — los tres esquemas de hash (`crypt-md5`, `crypt-blowfish`,
  `system`) producen un hash que verifica correctamente con el `crypt()` de
  PHP, `pr_sql_store_clear` escribe bien la columna en texto plano, y un
  (usuario, dominio) que no matchea reporta error en vez de un falso éxito.
  El vpopmail real de esta instalación es CDB-backed (vía `vpopmaild`), **no**
  SQL-backed, así que esto no se pudo validar contra una instalación real de
  vpopmail-sobre-MySQL — quien use el driver SQL en producción **debe**
  verificar el hash resultante contra su propio vpopmail (por ejemplo,
  comparar con una contraseña cambiada vía `vpasswd`) antes de confiar en él
  — ver "Driver de contraseña" más abajo.

## Instalación

Estos pasos asumen un Roundcube ya funcionando con:
- base propia en **PostgreSQL o MySQL/MariaDB** (la misma que ya usa
  Roundcube, no hace falta una base aparte). El plugin detecta
  automáticamente cuál es vía `rcube_db::db_provider`, no hace falta
  configurar nada distinto según el motor.
- autenticación de correo vía **vpopmail**, con el plugin `password`
  configurado con `password_driver = 'vpopmaild'`.

### 1. Copiar el plugin y habilitarlo

```bash
# ya está en su lugar en esta instalación:
#   /usr/share/roundcube/plugins/password_vpop_recovery
```

Agregar `'password_vpop_recovery'` a `$config['plugins']` en
`/etc/roundcube/config.inc.php`.

**En Roundcube instalado por paquete Debian/Ubuntu (Debian 12+
`roundcube-core`) hace falta un paso extra**: el cargador de plugins en
realidad lee de `/var/lib/roundcube/plugins/`, no directamente de
`/usr/share/roundcube/plugins/` — cada plugin que trae el paquete es un
symlink ahí. Sin esto el plugin falla al cargar con
`Failed to load plugin file ...` y, si ese error pasa en cada página, se
rompe todo el webmail, no solo la recuperación:

```bash
ln -s /usr/share/roundcube/plugins/password_vpop_recovery /var/lib/roundcube/plugins/password_vpop_recovery
```

(En instalaciones donde `plugins/` no es un directorio de symlinks — por
ejemplo Roundcube instalado desde el código fuente en vez de con `.deb` —
este paso no aplica.)

### 2. Crear las tablas en la base de Roundcube

El plugin **no usa una base aparte**: guarda sus datos en la misma base que
ya usa Roundcube (evita tener que administrar credenciales de otra base, y
no toca la base de vpopmail para nada). Usá el bloque que corresponda según
el `db_dsnw` de tu Roundcube.

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

(Se usa `DATETIME` en vez de `TIMESTAMP` a propósito — en MySQL las columnas
`TIMESTAMP` se auto-actualizan solas en cada cambio de fila salvo que se les
diga explícitamente lo contrario, y no es lo que queremos para
`token_validity`/`created_at`.)

### 3. Cuentas admin de vpopmail (una por dominio)

`vpopmaild` exige autenticarse antes de modificar una cuenta — y con
`slogin` propio no alcanza, porque en recuperación el usuario justamente no
tiene la contraseña anterior. La solución es autenticarse con una cuenta que
tenga **privilegios de administrador de dominio** en vpopmail, que sí puede
ejecutar `mod_user` sobre *cualquier* cuenta de ese dominio — y solo
`postmaster@<dominio>` tiene ese privilegio.

Como el **usuario** admin es siempre `postmaster@<dominio>`, nunca hace falta
configurarlo explícitamente — solo la **contraseña** de esa cuenta, una por
dominio (ver `pr_domains` en el paso 5). Si `postmaster@<dominio>` no existe
todavía para algún dominio, hay que crearla/habilitarla en el servidor de
correo antes de seguir.

### 4. Cuenta de envío de correo (SMTP)

El código de confirmación se envía **antes del login**, momento en el que
Roundcube no tiene ninguna sesión IMAP activa — por eso no se puede
reutilizar el `smtp_user`/`smtp_pass = '%u'/'%p'` de la config general.

Por defecto, cada dominio envía su correo de confirmación autenticándose por
SMTP-AUTH como su propio `postmaster@<dominio>`, reutilizando la **misma
contraseña** ya configurada para vpopmaild en el paso 3 — vpopmail comparte
una sola contraseña entre IMAP/POP/SMTP-AUTH para una casilla, así que en el
caso común no hay nada extra que configurar acá. Esto se puede sobreescribir
por dominio en `pr_domains` solo cuando no aplica:

- **una cuenta de envío dedicada, distinta** (`smtp_user`/`smtp_pass`) — el
  caso habitual cuando Roundcube y el servidor de correo están en hosts
  separados y el relay por el que realmente se envía no acepta las
  credenciales del postmaster, o simplemente no se quiere reutilizar esa
  contraseña para SMTP;
- **`smtp_auth => false`** — el relay de este dominio acepta correo sin
  autenticar (solo si tu configuración de MTA realmente lo permite y tiene
  SPF/DKIM correctos — si no, el correo termina en spam o rebota). En este
  caso el remitente (From/Reply-To) también pasa a usar por defecto
  `noreply@<dominio>` en vez del `pr_replyto_email` global — un relay local
  sin autenticación suele aceptar correo solo `FROM` su propio dominio, así
  que un reply-to de otro dominio quedaría rechazado. Sobreescribilo con
  `'from'` si necesitás otra cosa;
- **`smtp_server`** — el relay de este dominio es un host distinto al
  `pr_default_smtp_server` compartido.

### 5. Configurar `config.inc.php` del plugin

El plugin trae sus propios valores por defecto en `config.inc.default.php`
(se carga automáticamente, primero, antes de la config real — ver el
comentario al principio de ese archivo para más detalle). **`config.inc.php`
solo necesita declarar secretos y lo que difiera de esos defaults** — no
hace falta copiar cada configuración, y no hay nada que mantener sincronizado
a mano entre actualizaciones.

En Roundcube instalado por paquete Debian/Ubuntu, el `config.inc.php` de
cada plugin bajo `/usr/share/roundcube/plugins/<nombre>/` es en realidad un
symlink a un archivo real bajo `/etc/roundcube/plugins/<nombre>/` — fijate
en cualquier plugin del core (por ejemplo `password`) y vas a ver el mismo
patrón. Así el config específico de este servidor queda en `/etc` (sobrevive
a actualizaciones del paquete) en vez de bajo `/usr/share` (que administra el
gestor de paquetes). Seguimos la misma convención acá:

```bash
mkdir -p /etc/roundcube/plugins/password_vpop_recovery
cp /usr/share/roundcube/plugins/password_vpop_recovery/config.inc.php.dist \
   /etc/roundcube/plugins/password_vpop_recovery/config.inc.php
ln -s /etc/roundcube/plugins/password_vpop_recovery/config.inc.php \
      /usr/share/roundcube/plugins/password_vpop_recovery/config.inc.php
```

(Si no es una instalación por paquete Debian, no hay problema en dejar
`config.inc.php` directo dentro de la carpeta del plugin — se salta el
symlink y se edita ahí nomás.)

Completar como mínimo:

| Variable | Qué es |
|---|---|
| `pr_replyto_email` | remitente de los correos con el código |
| `pr_default_smtp_server` | relay SMTP compartido para dominios que no lo sobreescriban |
| `pr_domains` | una entrada por dominio de correo, ver abajo |

**Configuración multi-dominio (`pr_domains`)** — una entrada por cada dominio
de correo que atienda esta instalación:

```php
$config['pr_domains'] = [
    'tu-dominio.com' => [
        'vpopmaild_admin_pass' => 'CAMBIAR',   // postmaster@tu-dominio.com
    ],
    'otro-dominio.com' => [
        'vpopmaild_admin_pass' => 'CAMBIAR',
        'smtp_auth' => false,                  // el relay de este dominio no exige auth
        // 'from' por defecto queda en noreply@otro-dominio.com; se puede
        // fijar explícitamente para sobreescribirlo (sirve para cualquier
        // dominio, no solo este caso)
    ],
    'tercer-dominio.com' => [
        'vpopmaild_admin_pass' => 'CAMBIAR',
        'smtp_user'   => 'alertas@terceros.example', // cuenta de envío dedicada
        'smtp_pass'   => 'CAMBIAR',
        'smtp_server' => 'smtp.terceros.example:587',
    ],
];
```

Una instalación de un solo dominio puede usar en cambio el formato plano
"legacy" (`pr_vpopmaild_admin_pass`, `pr_default_smtp_user`,
`pr_default_smtp_pass`) — ver los comentarios en `config.inc.php.dist` para
el orden exacto de fallback.

**Driver de contraseña (`pr_password_driver`)** — cómo el plugin resetea
efectivamente la contraseña en el servidor de correo:

| Driver | Cuándo usarlo |
|---|---|
| `'vpopmaild'` (default) | Funciona con cualquier instalación de vpopmail, CDB o SQL. Necesita una contraseña de postmaster por dominio (`pr_domains` arriba). |
| `'sql'` | Solo si vpopmail en sí corre sobre backend SQL (MySQL/MariaDB). Escribe directo a la tabla propia de vpopmail con UN solo set de credenciales de base para todos los dominios — escala mucho mejor pasados unos pocos dominios, a costa de tener que validar el esquema de hash contra tu build específico de vpopmail (ver abajo). |

Para usar el driver SQL:

```php
$config['pr_password_driver'] = 'sql';
// Mismo formato de DSN que el db_dsnw propio de Roundcube (esto reutiliza
// rcube_db, solo que apuntado a la base de vpopmail en vez de la de Roundcube).
$config['pr_sql_dsn'] = 'mysql://vpopmail_admin:CAMBIAR@127.0.0.1/vpopmail';
```

`pr_sql_table`/`pr_sql_columns` por defecto asumen el esquema MySQL estándar
de vpopmail (tabla `vpopmail`, columnas
`pw_name`/`pw_domain`/`pw_passwd`/`pw_clear_passwd`) y solo hace falta
sobreescribirlos si tu vpopmail se compiló con uno distinto.
`pr_sql_hash_scheme` (`crypt-md5` por default, o `crypt-blowfish`/`system`)
**tiene que coincidir con cómo se compiló tu vpopmail** — un esquema que no
coincide no da error, simplemente produce en silencio una contraseña que
vchkpw no puede verificar. Validalo antes de confiar en esto en producción:
cambiá la contraseña de una cuenta real a través del plugin, y confirmá que
el servidor de correo sigue aceptando la nueva contraseña para login
IMAP/POP.

**No apuntes `pr_sql_dsn` a la cuenta admin de vpopmail.** Creá un usuario
de MySQL/MariaDB dedicado, con el mínimo privilegio que este driver
realmente necesita — `UPDATE` sobre las dos columnas que escribe, nada más
(sin `SELECT`, `INSERT`, `DELETE`, ni acceso a ninguna otra tabla), acotado
al host exacto desde donde se conecta Roundcube:

```sql
CREATE USER 'rc_pwreset'@'127.0.0.1' IDENTIFIED BY 'una-clave-larga-y-unica';
GRANT UPDATE (pw_passwd, pw_clear_passwd) ON vpopmail.vpopmail TO 'rc_pwreset'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Ajustá base/tabla/columnas a tu esquema real. El host (`'127.0.0.1'`) debe
ser la dirección desde la que MariaDB realmente ve llegar la conexión.

**Importante — permisos**: este archivo contiene contraseñas en texto plano
(la del SMTP y las credenciales de vpopmaild/SQL). Aplicá esto sobre el
archivo REAL (el de `/etc/roundcube/plugins/...` si seguiste el enfoque del
symlink de arriba — el symlink en sí no necesita permisos especiales, solo
apunta ahí):

```bash
chown root:www-data /etc/roundcube/plugins/password_vpop_recovery/config.inc.php
chmod 640 /etc/roundcube/plugins/password_vpop_recovery/config.inc.php
```

(`www-data` es el usuario con el que corre PHP-FPM/nginx en esta
instalación — ajustar si el servidor web corre con otro usuario. Ojo que
esto es más estricto que el default de Debian para el resto de los plugins,
que vienen `644 root:root` — legible por cualquiera. Está bien para configs
sin secretos, pero este archivo tiene contraseñas reales, así que vale la
pena la excepción.)

⚠️ Cada vez que se edite este archivo con un editor que reescriba el fichero
entero, conviene volver a chequear el dueño/grupo — algunas herramientas de
edición recrean el archivo y lo dejan `root:root`, con lo cual `www-data` deja
de poder leerlo y Roundcube cae a valores por defecto sin avisar.

### 6. Cargar el correo de recuperación de cada usuario

Cada usuario puede cargar su propio correo de recuperación desde
**Configuración → Identidades → (su identidad) → "Correo electrónico de
recuperación"**.

Para cargar muchas cuentas de una sola vez (por ejemplo, al dar de alta este
sistema), existe `bin/import_recovery_emails.php`, que lee un CSV de dos
columnas (`cuenta`, `recuperacion`) y hace las mismas validaciones que el
formulario:

```bash
php bin/import_recovery_emails.php cuentas.csv --dry-run   # previsualizar
php bin/import_recovery_emails.php cuentas.csv             # aplicar
```

Es seguro volver a correrlo sobre cuentas que ya importaste antes — el
correo de recuperación que ya tengan cargado se respeta, salvo que uses
`--force`:

| Situación | Sin `--force` | Con `--force` |
|---|---|---|
| La cuenta no existe, sin `--create-missing` | Error | Error |
| La cuenta no existe, con `--create-missing` | La crea + carga el correo | La crea + carga el correo |
| La cuenta existe, sin recovery cargado todavía | Lo carga | Lo carga |
| La cuenta existe, ya tiene un recovery cargado | Se omite | Se sobrescribe |

**Alta masiva de casillas nuevas**: Roundcube recién crea sus propias filas
en `users`/`identities` la primera vez que alguien inicia sesión de verdad —
no tiene idea de que una cuenta existe en el servidor de correo hasta
entonces. Si estás dando de alta muchas casillas en vpopmail de una y no
querés tener que loguearte en cada una solo para que funcione la
recuperación, agregá `--create-missing`: para cada cuenta del CSV que
Roundcube todavía no conoce, crea el usuario y una identidad por defecto
(exactamente lo que haría un primer login real) antes de cargarle el correo
de recuperación — una sola pasada en vez de un login por cuenta:

```bash
php bin/import_recovery_emails.php cuentas.csv --create-missing
```

## Capturas de pantalla

**Link de recuperación en el login**
![Link "¿Olvidó su contraseña?"](docs/login-link.png)

**Formulario pidiendo cuenta + correo de recuperación**
![Formulario de recuperación](docs/recovery-form.png)

**Formulario de código + nueva contraseña (con el checklist de política en vivo)**
![Nueva contraseña](docs/new-password-form.png)

**Dónde cargar el correo de recuperación (Configuración → Identidades)**
![Correo de recuperación en Identidades](docs/identity-settings.png)

## Idiomas

Incluye localización en español (`localization/es_ES.inc` +
`localization/es_ES/*.html`), que Roundcube usa automáticamente para
navegadores en `es_ES`/`es_AR`/`es_*` (alias core de Roundcube). Solo se
mantienen en_US y es_ES — el resto de los idiomas que traía el plugin
original (de_DE, fr_FR, it_IT, ru_RU, sv_SE) se eliminaron, porque tenían
textos de funcionalidades que esta adaptación no tiene (pregunta secreta,
SMS, aviso al admin) y acá no hay quien los mantenga actualizados. Si un
navegador pide alguno de esos, Roundcube cae automáticamente a en_US.

## Qué NO tiene esta adaptación (a propósito)

Se sacaron del flujo original para simplificar y no dejar mecanismos sin
mantener:

- **Pregunta secreta**: menos seguro, no se usa.
- **SMS**: requiere un gateway que no está disponible acá.
- **Aviso automático al administrador** cuando a un usuario le falta el dato
  de recuperación: en su lugar, simplemente no puede recuperar por sí mismo
  (evita el problema de que ese aviso también sea un canal de fuga de
  información sobre qué cuentas existen).
