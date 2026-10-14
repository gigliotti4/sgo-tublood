---
name: deploy-prod
description: Desplegar a producción (sgo.tublood.com) — chequeo previo, resumen de lo que entra, backup, deploy.sh, comandos post-deploy y verificación. Solo lo dispara Lucas; cada paso que toca el servidor espera su aprobación.
disable-model-invocation: true
allowed-tools: Bash, Read, Grep, AskUserQuestion
---

# Deploy a producción

⚠️ **Producción está en uso real** (confirmado por Lucas el 29/9/2026: *"no quiero que pase nada cuando
pushee y deployee"*). `deploy.sh` pone el sitio en mantenimiento, migra y reseedea: no es de bajo riesgo
aunque sea idempotente. Por eso este skill **para y pregunta** antes de cada cosa que toca el servidor.
Que Lucas haya invocado `/deploy-prod` autoriza a *preparar* el deploy, no a correrlo sin el OK del paso 3.

Acceso: `ssh -i "$SGO_SSH_KEY" -o ConnectTimeout=15 "$SGO_SSH"`, con las variables de
`.claude/settings.local.json` (el repo es público: la IP y el usuario no van en archivos versionados).
App en `/var/www/sgo`. **Todo artisan y el deploy como `sudo -u deploy`**, nunca como root.

## 1. Chequeo previo (local)

- `git status --porcelain` vacío. Si hay cambios sin commitear: frenar y preguntar si se commitean
  (por partes, como prefiere Lucas) o se dejan afuera. Nunca deployar algo que no está commiteado.
- `git fetch` y `git status -sb`: la rama no puede estar *ahead* de `origin`. El servidor hace
  `git pull --ff-only` de **su rama actual**, que hoy es `feature/codigo-producto-y-espanol`, no `main`.
  Si hay que pushear, es un paso con su propia confirmación (`git push` siempre pregunta).
- Correr `/verificar` sobre el rango que se va a desplegar si no se corrió en esta sesión.

## 2. Qué se va a desplegar (solo lectura en el servidor)

```bash
ssh ... 'cd /var/www/sgo && sudo -u deploy git rev-parse --abbrev-ref HEAD && sudo -u deploy git rev-parse HEAD'
```

⚠️ **También git va como `deploy`**: como root aborta con `detected dubious ownership`. No "arreglarlo"
con `git config --global --add safe.directory`: es tocar la configuración del servidor para esquivar
una protección que está bien.

Con ese commit (`$DESPLEGADO`), en local:

- `git log --oneline $DESPLEGADO..origin/<rama>`: los commits que entran.
- `git diff --stat $DESPLEGADO..origin/<rama>`, y en particular:
  - `database/migrations/`: **migraciones nuevas**. Leerlas y decir si son aditivas o tocan datos.
  - `database/seeders/RolesAndPermissionsSeeder.php` / `SectorSeeder.php`: el deploy los reseedea. Un
    cambio de permisos de un rol **le cambia el acceso a gente real** en ese momento: decir a quién.
  - `routes/console.php`: tareas agendadas nuevas o movidas.
  - `.env.example` / `config/`: ¿hace falta una variable nueva en el `.env` del servidor? ⚠️ El 29/9 un
    cambio de código sin el swap del `.env` dejó `compras:sync` roto en silencio un día entero: el
    `.env` va **primero** y verificado.
  - `package.json` / `resources/`: el deploy recompila assets (tarda más).

## 3. Resumen y confirmación — con AskUserQuestion

Antes de tocar nada, un resumen corto:
- los commits;
- las migraciones, y qué hacen;
- los cambios de permisos y a quién afectan;
- el downtime esperado (el modo mantenimiento dura lo que tarda el script: menos de 2 minutos sin
  assets, algo más con build);
- los comandos post-deploy que van a hacer falta (ver la tabla del paso 6).

Opciones: deployar ahora / esperar a otro horario / cancelar. Sin un "sí" explícito, no se sigue.

## 4. Backup

```bash
ssh ... '/usr/local/bin/sgo-backup 2>&1 | tail -3; ls -lht /var/backups/sgo/ | head -3'
```

Confirmar que hay un archivo **de recién** (dump de MySQL + `storage/app/private`). Si el backup falla,
no se deploya. Ojo: el backup queda en el mismo droplet; los snapshots de DigitalOcean cubren la
pérdida del servidor.

## 5. Deploy

```bash
timeout 540 ssh ... 'cd /var/www/sgo && sudo -u deploy ./deploy.sh 2>&1 | tee /tmp/deploy-$(date +%Y%m%d-%H%M).log | tail -40'
```

Tiene que terminar en `✅ Deploy terminado — <commit>`, y ese commit tiene que ser el esperado. Si la
ssh se corta (a los ~600 s), el deploy puede seguir corriendo del otro lado: leer el log en `/tmp/` antes
de volver a lanzarlo. Si falla a mitad de camino, el `trap` del script saca la app de mantenimiento;
**no** reintentar a ciegas: mostrar el error y decidir con Lucas.

## 6. Post-deploy (cada uno con su confirmación)

Según qué cambió en el diff del paso 2:

| Si cambió… | Correr |
|---|---|
| `ComprasArticuloSyncService`, `OrdenCompraSyncService`, `PedidoPendienteSyncService`, `StockDepositoSyncService`, o una migración de `compras_*` | `compras:sync --sync` |
| `ArticuloSyncService` | `articulos:sync --sync` |
| `ClienteSyncService` / `Support/Mojibake` | `clientes:sync --sync` |
| `PartidaSyncService` | `partidas:sync --sync` |
| `VentaSyncService` | `ventas:sync` (pesado: con `nohup … &` y después consultar la tabla) |
| `VentaPartidaSyncService` | `venta-partidas:sync` (~166.000 filas: `nohup`) |
| `ProveedorSyncService` | `proveedores:sync --sync` |

Siempre `sudo -u deploy php artisan …` desde `/var/www/sgo`. Para lo largo:
`sudo -u deploy nohup php artisan ventas:sync --sync > /tmp/ventas-sync.log 2>&1 &`, y verificar
después con `/prod-consulta` (el `synced_at` de la tabla), no esperando la salida.

## 7. Verificación

```bash
curl -s -o /dev/null -w '%{http_code}\n' https://sgo.tublood.com/login            # 200
ssh ... 'cd /var/www/sgo && sudo -u deploy php artisan migrate:status | grep -i pending || echo "sin pendientes"'
ssh ... 'systemctl is-active sgo-worker; cd /var/www/sgo && sudo -u deploy php artisan about --only=environment | head -12'
ssh ... 'tail -5 /var/www/sgo/storage/logs/laravel.log'                                 # nada nuevo y feo
```

Más lo propio del cambio, con `/prod-consulta` (p. ej. "el rol `compras` existe y `usuario_interno` ya
no tiene `compras.view`").

## 8. Reporte

Qué commit quedó desplegado, cuánto duró, qué post-deploy se corrió y con qué resultado, y qué quedó
pendiente. Recordar que el servidor está en **UTC** y la app guarda **hora argentina** si se citan horas.
Si el cambio le saca o le da acceso a alguien, avisarlo explícito.
