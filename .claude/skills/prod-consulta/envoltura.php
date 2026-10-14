<?php

/*
 * Envoltura de /prod-consulta. Corre EN EL DROPLET, como `deploy`, con la consulta en $argv[1].
 *
 * Arranca Laravel a mano (como el run.php local) en vez de pasar por `artisan tinker --execute`:
 * tinker por ssh rompía las comillas y había que reintentar.
 *
 * Dos redes para que una consulta no pueda tocar producción por error:
 *   - fakes de Mail/Notification/Queue/Bus: ningún aviso ni job sale del servidor;
 *   - una transacción que SIEMPRE se deshace, en el `finally`.
 * Lo que no cubren —y por eso prod-php.sh frena antes de mandar— son los archivos en disco, los
 * comandos de Artisan y el DDL de MySQL, que hace commit implícito.
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

require '/var/www/sgo/vendor/autoload.php';
$app = require '/var/www/sgo/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Mail::fake();
Notification::fake();
Queue::fake();
Bus::fake();

// La excepción se atrapa a mano: el handler de consola de Laravel la imprime y termina con exit 0,
// y quien corre la consulta tiene que poder distinguir "dio 0 filas" de "reventó".
$falla = null;
DB::beginTransaction();
try {
    require $argv[1];
} catch (Throwable $e) {
    $falla = $e;
} finally {
    DB::rollBack();
    fwrite(STDERR, PHP_EOL.'[prod-consulta] rollback hecho, nivel de transacción '.DB::transactionLevel()
        .' · hora del servidor (UTC) '.gmdate('Y-m-d H:i').' · la app guarda hora argentina (UTC−3)'.PHP_EOL);
}

if ($falla) {
    fwrite(STDERR, '[prod-consulta] FALLÓ: '.get_class($falla).': '.$falla->getMessage()
        .' en '.basename($falla->getFile()).':'.$falla->getLine().PHP_EOL);
    exit(1);
}
