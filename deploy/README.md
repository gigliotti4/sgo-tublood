# Desplegar SGO Tublood en el droplet

Guía del armado inicial. Para los deploys del día a día, una vez que esto está
hecho, alcanza con `cd /var/www/sgo && ./deploy.sh`.

## Por qué se mudó

En Hostinger (hosting compartido) había tres cosas que no funcionaban, **por
límites del hosting y no del código**:

1. **No corría el scheduler** → nada se sincronizaba solo.
2. **No corría ningún queue worker** → el botón "Sincronizar" despachaba un job
   que quedaba en la tabla `jobs` para siempre.
3. **No se podía instalar `pdo_sqlsrv`** → proveedores y ventas se leen de vistas
   SQL Server del ERP, así que esas dos tablas nunca se actualizaban. Las dos
   tareas están apagadas a propósito con `->when($erpConfigurado)` en
   `routes/console.php`, y se encienden solas cuando `ERP_DB_HOST` tiene valor.

El droplet resuelve las tres.

---

## 0. Antes de tocar el servidor

Tres cosas que dependen de terceros y conviene pedir primero, porque son las que
demoran:

- [ ] **RP Sistemas tiene que autorizar la IP del droplet** en su SQL Server.
      Sin eso, proveedores y ventas siguen sin sincronizar aunque el driver esté
      instalado. **Es el mayor riesgo de demora del proyecto.**
- [ ] **DNS**: registro `A` de `sgo.tublood.com` → IP del droplet. Sin esto
      Certbot no puede emitir el certificado.
- [ ] **Resend**: el dominio de `MAIL_FROM_ADDRESS` verificado, con sus
      registros DNS cargados. Si no, Resend rechaza cada envío.

## 1. Crear el droplet

- `s-2vcpu-4gb` (2 vCPU, 4 GB, 80 GB) en `nyc3` — DigitalOcean no tiene región
  en Sudamérica; NYC es la de menor latencia hacia Argentina.
- Ubuntu 24.04 LTS, autenticación por **clave SSH** (no contraseña).
- Backups del droplet activados.

## 2. Armar el servidor

```bash
ssh root@<IP>
apt-get update && apt-get install -y git
git clone <repo> /var/www/sgo
cd /var/www/sgo
bash deploy/provision.sh
```

Instala PHP 8.3, el driver de SQL Server, MySQL, nginx, Node, Certbot, el worker
(systemd), el scheduler (cron) y el backup diario. Es idempotente.

## 3. Base de datos

```bash
sudo mysql
```

```sql
CREATE DATABASE sgo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'sgo'@'localhost' IDENTIFIED BY '<contraseña larga y random>';
GRANT ALL PRIVILEGES ON sgo.* TO 'sgo'@'localhost';
FLUSH PRIVILEGES;
```

## 4. El `.env`

Copiar `.env.example` y completar. Lo que **no** puede quedar como está:

| Variable | Valor |
|---|---|
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` — con `true` se filtran credenciales en cualquier error |
| `APP_URL` | `https://sgo.tublood.com` |
| `APP_KEY` | `php artisan key:generate` |
| `DB_*` | la base y el usuario del paso 3 |
| `MAIL_MAILER` | `resend` (en desarrollo es `log`) + `RESEND_API_KEY` |
| `MAIL_FROM_ADDRESS` | del dominio verificado en Resend |
| `BROADCAST_CONNECTION` | `pusher`, con las credenciales que ya funcionan (cluster `sa1`) |
| `ERP_DB_*` | el SQL Server de RP Sistemas |

`QUEUE_CONNECTION`, `SESSION_DRIVER` y `CACHE_STORE` quedan en `database`, como
en desarrollo.

**Reverb no hace falta levantarlo**: está instalado pero no se usa. El front solo
configura Echo cuando el driver es `pusher` (ver `AppLayout.vue`).

## 5. Dependencias y primera migración

```bash
cd /var/www/sgo
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force          # roles, permisos y sectores
```

⚠️ **Cambiar la contraseña del usuario semilla `admin@admin.com`**, que viene
como `password`.

## 6. HTTPS

Con el DNS ya resolviendo:

```bash
certbot --nginx -d sgo.tublood.com
```

## 7. Arrancar el worker

```bash
systemctl start sgo-worker
systemctl status sgo-worker
```

## 8. Primera carga de datos

Las sincronizaciones se corren **a mano y con `--sync`** la primera vez: sin la
cola de por medio, los errores salen en pantalla en vez de en `failed_jobs`.

```bash
php artisan clientes:sync --sync
php artisan articulos:sync --sync
php artisan proveedores:sync --sync
php artisan ventas:sync --sync        # ~60.000 filas, es la más lenta
```

Después, desde el panel (`Artículos → Importar`):

⚠️ **Reimportar los dos Excel de artículos** — el de Calidad (PM / legajo /
vencimiento) y el de proveedores (`proveedor_principal`). Esos campos **no vienen
del ERP**, y sin ellos `articulos.proveedor_id` queda vacío y el ranking de
"Proveedores con más fallas" del Dashboard no muestra nada.

La clasificación documental de clientes y proveedores también se carga a mano y
no vuelve de ningún lado.

---

## Verificación

**En el servidor**

```bash
php -m | grep sqlsrv                 # el driver está en la CLI, no solo en fpm
php artisan about                    # env=production, debug=false
php artisan tinker --execute="DB::connection('erp')->select('SELECT 1');"
systemctl status sgo-worker
php artisan schedule:list            # las 5 tareas; las del ERP sin tachar
```

**Que las sincronizaciones corran solas** — la prueba real es esperar:

1. A los ~10 min, `SELECT MAX(synced_at) FROM clientes` tiene que haberse movido
   (corre cada 5 minutos).
2. Al día siguiente, `articulos`, `proveedores` y `ventas` con `synced_at` fresco.
3. `SELECT COUNT(*) FROM jobs` en 0 y `failed_jobs` vacío. Si se acumulan, el
   worker no está tomando trabajo.

**En el navegador** (`https://sgo.tublood.com`)

1. Candado verde y login.
2. Dashboard con números reales; el hover de "Abiertas" despliega la lista.
3. Clientes, Artículos, **Proveedores y Ventas** con filas — los dos últimos son
   los que hoy no andan.
4. Cargar una observación con un adjunto, bajar el PDF, borrarla.
5. **Mandar un mail de verdad**: asignarse una observación y ver si llega. Es lo
   único que no se puede probar sin un envío real, y recién ahora el driver deja
   de ser `log`.
6. Exportar el Excel de clientes y volver a subirlo (ejercita PhpSpreadsheet).

**Al terminar**: apagar el cron de Hostinger si quedó algo corriendo, para que no
haya dos instancias sincronizando contra el mismo ERP.

---

## Operación

| | |
|---|---|
| Deploy | `cd /var/www/sgo && ./deploy.sh` |
| Logs de la app | `storage/logs/laravel.log` |
| Logs del worker | `/var/log/sgo-worker.log` |
| Logs del scheduler | `/var/log/sgo-scheduler.log` |
| Backups | `/var/backups/sgo` (diario 02:30, 14 días) |
| Reiniciar el worker | `systemctl restart sgo-worker` |
| Sincronizar a mano | `php artisan <clientes\|articulos\|proveedores\|ventas>:sync --sync` |

⚠️ Los backups quedan **en el mismo droplet**: sirven para un borrado
accidental, no para la pérdida del servidor. Para eso están los snapshots
semanales de DigitalOcean.
