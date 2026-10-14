---
name: prod-consulta
description: Consultar datos de PRODUCCIÓN (sgo.tublood.com) en modo solo lectura — contar, medir, verificar el estado real después de un deploy o antes de proponer un cambio. Corre un archivo PHP con Laravel entero en el droplet, con mails/avisos/colas falseados y rollback garantizado. No sirve para escribir en producción.
argument-hint: "[qué querés saber de producción]"
allowed-tools: Bash, Write, Read
---

# Consulta de solo lectura contra producción

Producción está **en uso real**. Este skill existe para poder *mirar* sin riesgo; para cualquier
escritura (un update, un borrado, un sync, un mail de prueba) **no es esto**: se explica qué se va a
hacer, se pide aprobación explícita y se ofrece backup antes.

## Cómo

1. Escribí la consulta con **Write** en el scratchpad (nunca con heredoc: las comillas se rompen).
   Tiene Laravel entero: modelos, `DB::table(...)`, `DB::connection('erp')`, `config(...)`.
   Imprimí con `echo json_encode(..., JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;`.

   ```php
   <?php
   use App\Models\User;
   echo json_encode([
       'usuarios' => User::count(),
       'con_rol_compras' => User::whereHas('roles', fn ($q) => $q->where('name', 'compras'))->pluck('email'),
   ], JSON_PRETTY_PRINT), PHP_EOL;
   ```

2. Correla:

   ```bash
   bash .claude/skills/prod-consulta/prod-php.sh "<scratchpad>/consulta.php"
   ```

   El acceso (`SGO_SSH`, `SGO_SSH_KEY`) sale de `.claude/settings.local.json`, que **no** está en el
   repo porque el repo es público. Si falta, pedíselo a Lucas; no lo inventes.

3. Mostrá el resultado y decí de qué consulta salió.

## Qué protege y qué no

- `envoltura.php` falsea `Mail`, `Notification`, `Queue` y `Bus`, y abre una transacción que se
  **deshace siempre** (también si la consulta revienta). Probado: un `update` adentro se ve dentro de
  la transacción y no queda después.
- `prod-php.sh` **aborta antes de conectarse** si el archivo tiene algo que escribe (`->update(`,
  `->save(`, `->sync(`, `Storage::`, `Artisan::`, `DB::statement`…). No lo saltees con
  `PROD_CONSULTA_FORZAR=1` salvo que Lucas lo pida: el rollback no cubre archivos en disco, comandos de
  Artisan (un `compras:sync` reescribe tablas enteras desde el ERP) ni DDL de MySQL (commit implícito).
- Una consulta que falla sale con **exit 1** y el mensaje `[prod-consulta] FALLÓ: ...`.

## Trampas de lectura

- ⚠️ **El servidor está en UTC y la app guarda hora argentina (UTC−3).** Un `synced_at` de las 13:58
  es una corrida de las 16:58 del reloj del servidor. Leerlo mal hace parecer que un sync no corrió.
- `count()` antes de traer filas: algunas tablas son grandes (`venta_partidas` ~166.000,
  `ventas` ~60.000). Traé `TOP`/`limit` y agregados, no la tabla.
- `timeout 540`: la sesión ssh se corta a los ~600 s. Algo que tarde más no es una consulta.
- El usuario de la conexión `erp` es de solo lectura en RP Sistemas, pero igual: agregar en SQL
  Server, no traer el kardex a PHP (390.000 filas, >7 minutos). Para medir el ERP en detalle está el
  agente `explorador-erp`, que lo hace desde local.
- Artisan y el deploy van **siempre** como `deploy` (`sudo -u deploy`), nunca como root: git aborta por
  ownership y los archivos de caché quedan con el dueño equivocado. La envoltura ya lo hace así.
