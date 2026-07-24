# Runbook de deploy — SGO Tublood en Hostinger (entorno de prueba)

> Ejecutable, no borrador. Fecha: 2026-07-23
> Decisiones de este entorno: dominio raíz `sgo.tublood.com`, SSH + `git clone`, colas en `sync`, **mail en `log`** (no se manda nada real todavía).
> El plan conceptual está en [PLAN-deploy-hostinger.md](PLAN-deploy-hostinger.md); esto son los pasos concretos.

**URL final: `https://sgo.tublood.com`** — el dominio ya existe en la cuenta y su raíz está libre.
**No hay que crear ningún subdominio.**

Reemplazá en los comandos:

| Marcador | Qué es |
|---|---|
| `<USER>` | usuario SSH de Hostinger, ej. `u123456789` |
| `<SSH_HOST>` / `<SSH_PORT>` | de hPanel → Avanzado → Acceso SSH (el puerto suele ser `65002`) |

---

## Ya hecho en local ✅

- Assets de producción compilados → **`public/build/`** (empaquetados en `sgo-build.zip`, ver §4)
- `APP_KEY` generada y `APP_URL` fijada en **`.env.production`** (raíz del proyecto, no se versiona)
- Rama `feature/codigo-producto-y-espanol` pusheada a GitHub

**Falta completar a mano en `.env.production`** (marcados con `<<< COMPLETAR >>>`):
`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, `RPSISTEMAS_API_TOKEN`.

---

## 1. hPanel — lo único que se hace por panel

1. **PHP 8.3 o 8.4**: Avanzado → Configuración de PHP → seleccionar la versión para `sgo.tublood.com`.
   `composer.json` exige `^8.3`; con 8.2 o menos ni siquiera instala.

2. **SSL**: Seguridad → SSL → emitir el certificado gratis y activar **"Forzar HTTPS"**.

3. **Base de datos MySQL**: Bases de datos → MySQL → crear base y usuario.
   Anotá nombre, usuario y contraseña → van a `.env.production`.

4. **SSH**: Avanzado → Acceso SSH → habilitarlo y anotar host, puerto y usuario.

> ❌ **No usar la pantalla de Subdominios.** Su campo de carpeta está encerrado en `/public_html/`, y Laravel necesita que el document root sea `public/` con el resto del proyecto **afuera** de lo servido. Eso se resuelve en §3 con un symlink.

---

## 2. Clonar el proyecto

```bash
ssh -p <SSH_PORT> <USER>@<SSH_HOST>

# Confirmar el layout real de la cuenta antes de tocar nada
ls -la ~
ls -la ~/domains/
```

Deberías ver `~/domains/sgo.tublood.com/public_html`. Si el layout es distinto (p. ej. solo `~/public_html`), ajustá las rutas de acá en adelante.

```bash
cd ~/domains/sgo.tublood.com
git clone -b feature/codigo-producto-y-espanol https://github.com/gigliotti4/sgo-tublood.git sgo-app
cd sgo-app
```

> Si el repo es privado, GitHub pide usuario y contraseña: como contraseña usá un **Personal Access Token** (github.com → Settings → Developer settings → Tokens, scope `repo`).

**Confirmar la versión de PHP del CLI** — no siempre coincide con la del sitio:

```bash
php -v
```

Si no es 8.3+, buscá el binario correcto y usalo en todos los comandos siguientes:

```bash
ls /opt/alt/php83/usr/bin/php /usr/bin/php8.3 2>/dev/null
alias php=/opt/alt/php83/usr/bin/php     # cómodo para el resto de la sesión
```

**Instalar dependencias**:

```bash
composer install --no-dev --optimize-autoloader
```

Si se queda sin memoria:

```bash
php -d memory_limit=-1 $(which composer) install --no-dev --optimize-autoloader
```

---

## 3. Apuntar el dominio a `public/` (el symlink)

La idea: el proyecto entero vive en `sgo-app/`, y lo único que Apache sirve es su carpeta `public/`.

```
~/domains/sgo.tublood.com/sgo-app/          ← proyecto (código, vendor, storage, .env)
~/domains/sgo.tublood.com/public_html  →    ← symlink a sgo-app/public
```

```bash
cd ~/domains/sgo.tublood.com

mv public_html public_html.bak              # backup de lo que haya (bienvenida de Hostinger)
ln -s sgo-app/public public_html

ls -la public_html                          # tiene que mostrar "public_html -> sgo-app/public"
ls public_html/index.php                    # y resolver al index.php de Laravel
```

> Si al entrar a `https://sgo.tublood.com` da **403**, es que Apache no está siguiendo el symlink.
> Se arregla agregando esta línea al principio de `sgo-app/public/.htaccess`:
> ```apache
> Options +FollowSymLinks
> ```
>
> Si el hosting bloquea symlinks del todo, el plan B es crear un subdominio con carpeta `/public_html/sgo-app/public` y proteger la raíz del proyecto con un `.htaccess` de `Require all denied`. Es más frágil — intentá el symlink primero.

---

## 4. Subir el `.env` (lo único que no viene del repo)

Los assets compilados **sí vienen con el clone**: `public/build/` está versionado a propósito, porque en hosting compartido no hay Node para compilar. Ver §9.

Subir `.env.production` (ya completado) a `~/domains/sgo.tublood.com/sgo-app/` por SFTP (FileZilla / WinSCP) o por el Administrador de Archivos de hPanel, **renombrado a `.env`**.

Verificar en el server:

```bash
cd ~/domains/sgo.tublood.com/sgo-app
ls -la .env
ls public/build/manifest.json              # tiene que existir; sin él, error en todas las páginas
ls public/hot 2>/dev/null && echo "❌ BORRAR public/hot"
```

> ⚠️ **`public/hot` nunca debe existir en el server.** Es un residuo de `npm run dev` que hay en la carpeta local; si llega, toda la app intenta cargar los assets desde `localhost:5173` y no se ve nada. Sigue en `.gitignore`, así que no viaja con git — solo es riesgo si copiás `public/` a mano.

---

## 5. Inicializar la app

```bash
cd ~/domains/sgo.tublood.com/sgo-app

chmod -R 775 storage bootstrap/cache
mkdir -p storage/app/private/observaciones storage/app/private/clientes

php artisan migrate --force
php artisan db:seed --force          # roles, permisos, sectores y usuario admin

php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### ⚠️ Cambiar la contraseña del admin — antes de pasarle la URL a nadie

El seeder deja `admin@admin.com` / `password`, y esto queda accesible desde internet.

```bash
php artisan tinker
```

```php
$u = App\Models\User::where('email','admin@admin.com')->first();
$u->password = 'PONER-UNA-CONTRASEÑA-LARGA-ACA';
$u->save();
exit
```

---

## 6. Cron del scheduler

hPanel → Avanzado → Trabajos Cron → **cada minuto**:

```
cd ~/domains/sgo.tublood.com/sgo-app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

(usá la misma ruta de PHP 8.3 que verificaste en §2)

Dispara lo agendado en [routes/console.php](routes/console.php): `clientes:sync` cada 5 min y `observaciones:alertas` cada hora.

> Si el plan no permite intervalo de 1 minuto: con 15 min las alertas horarias siguen saliendo bien (corren en el minuto :00) y la sync del ERP pasa de 5 a 15 min. Aceptable para pruebas.

---

## 7. Smoke test

1. `https://sgo.tublood.com/login` → entrar con el admin (contraseña nueva)
2. Dashboard: que cargue con estadísticas
3. Clientes → botón **Sincronizar** → deberían aparecer los clientes del ERP
   *(si queda vacío: falta `RPSISTEMAS_API_TOKEN` en el `.env` — y acordate de re-cachear la config)*
4. Portal público → cargar una "Falla de Producto" con **dos productos** y un adjunto
5. Verificar que el aviso quedó registrado: `tail -50 storage/logs/laravel-*.log`
   (con `MAIL_MAILER=log` no se manda nada, se escribe en el log)
6. Panel → Observaciones → abrir el modal, asignar responsable y clasificar
7. Clientes → subir un adjunto y **descargarlo** (es el único download real del sistema)
8. `php artisan observaciones:alertas` a mano → que la campana muestre la alerta

---

## 8. Deploys siguientes

**En local**, si tocaste frontend:

```bash
npm run build
git add -A && git commit -m "..." && git push
```

**En el server**, un solo comando:

```bash
cd ~/domains/sgo.tublood.com/sgo-app
./deploy.sh
```

[deploy.sh](deploy.sh) hace: modo mantenimiento → `git pull` → `composer install` **solo si cambió `composer.lock`** → `migrate --force` → regenerar `config`/`route`/`view` cache → salir de mantenimiento. Si algo falla a mitad de camino, un `trap` saca la app de mantenimiento igual, para que no quede caída.

Si el `php` del CLI no es 8.3+:

```bash
PHP=/opt/alt/php83/usr/bin/php ./deploy.sh
```

---

## 9. Por qué `public/build` está versionado

En hosting compartido no hay Node, así que los assets de Vite **no se pueden compilar en el server**. La alternativa era subirlos por SFTP en cada cambio de frontend; versionarlos hace que `git pull` los traiga solo. Son ~500 KB y 30 archivos, y Vite limpia la carpeta en cada build, así que el diff no se acumula.

La contra: **`npm run build` es obligatorio antes de commitear** si tocaste algo de `resources/js`. Si te lo olvidás, el server queda con el JS viejo — sin error visible, simplemente no aparecen los cambios.

Si más adelante el ruido en el historial molesta, el reemplazo natural es un workflow de GitHub Actions que buildee y commitee a una rama `deploy` que el server trackee.

---

## Cosas a tener presentes

- **El portal público queda abierto a internet.** Mientras `MAIL_MAILER=log` no hay riesgo de mandar mails de prueba a nadie. Cuando el cliente vaya a mirar: `MAIL_MAILER=resend` + `RESEND_API_KEY` y **`php artisan config:cache`** (sin re-cachear, el cambio no hace nada).
- **Resend rechaza el envío** si `MAIL_FROM_ADDRESS` no es de un dominio verificado en su panel. Lo tiene que resolver el cliente.
- **`APP_DEBUG=false`**: si algo falla vas a ver una pantalla genérica. El detalle está en `storage/logs/laravel-*.log`.
- **Esto no es producción**: no hay backups automáticos. Si el cliente carga datos reales durante las pruebas, exportá la base desde hPanel antes de cada deploy.
