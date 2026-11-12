<?php

namespace App\Services\RpSistemas;

use App\Exceptions\RpSistemasException;
use App\Models\Cliente;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja los clientes del ERP en `clientes`, con `numero` como clave (idempotente).
 *
 * ⚠️ **Desde el 12/11/2026 se lee por SQL, no por `clientes.php`.** El listado
 * paginado de esa API quedó roto alrededor del 23/10/2026: anuncia 38 páginas
 * pero la 2 viene vacía, y recorriéndolas todas devuelve filas irregulares (de 0 a
 * 100 por página) con **1.968 clientes distintos de 3.784**; `tamanoPagina` no se
 * respeta (con 4000 siguen siendo 100). El sync anterior cortaba en la primera
 * página vacía y, sin fallar nunca, **solo actualizaba los 100 clientes de la
 * página 1** en un orden arbitrario: un cliente nuevo entraba solo si caía ahí
 * (el 3827 no cayó). Pedirlos por número sí funciona, pero son 3.800 pedidos.
 *
 * Por eso sale de la tabla base `CLIENTES` de la conexión `erp`, igual que ya se
 * hizo con artículos (30/9/2026), proveedores, ventas y proformas. Medido contra
 * la API: la fila coincide valor por valor, y las descripciones de provincia y
 * vendedor salen de `PROVINCIAS` y `VENDEDORES` con los mismos nombres.
 *
 * ⚠️ `CLIENTES` es una tabla base del ERP, no una vista `powerbi_*`: sin contrato
 * (mismo riesgo que `ARTICULOS` y `COMPRO_PARTIDAS`). Si el sync se rompe de golpe,
 * empezar por ahí.
 *
 * `RpSistemasClient::getClientes()` queda sin uso a propósito (como `getArticulos()`):
 * si RP arregla la paginación, volver atrás es cambiar este servicio.
 */
class ClienteSyncService
{
    /** Filas por upsert: ~3.800 clientes × 21 columnas pasan el tope de 65.535 parámetros de MySQL. */
    private const CHUNK = 500;

    /** Dónde queda la hora de la última corrida que terminó bien (ver `escribir()`). */
    public const CLAVE_ULTIMA_SYNC = 'clientes.ultima_sync';

    /**
     * Las columnas que manda el ERP: lo que se compara y lo que el `upsert` pisa.
     * Es la lista que hay que **no** engordar con campos propios del panel.
     */
    private const COLUMNAS_DEL_ERP = [
        'razon_social', 'nombre_fantasia', 'cuit', 'codigo_iva', 'descripcion_iva',
        'telefono', 'mail', 'contacto', 'domicilio', 'localidad', 'codigo_provincia',
        'descripcion_provincia', 'porcen_descuen', 'usuario_web', 'codigo_vendedor',
        'nombre_vendedor', 'codigo_postal',
    ];

    /**
     * La población que devolvía la API: activos (`A`) y suspendidos (`S`). Los
     * inactivos (`I`, 11 al 12/11/2026) nunca estuvieron acá.
     */
    private const ESTADOS = ['A', 'S'];

    /**
     * Qué condición de IVA es cada código de `CLIENTES.IVA`. No hay tabla en el
     * ERP que lo diga (la API lo traía armado): se tomó de lo ya guardado en
     * producción, que usa exactamente estos cinco. Un código nuevo queda sin
     * descripción en vez de inventarla.
     */
    private const CONDICIONES_IVA = [
        1 => 'Responsable Inscripto',
        2 => 'Responsable No Inscripto',
        3 => 'Exento',
        4 => 'Consumidor Final',
        5 => 'Responsable Monotributo',
    ];

    /**
     * Si el ERP devuelve menos que esta fracción de lo que ya hay guardado, algo
     * anda mal: es el fallo silencioso que dejó el sync estancado casi tres semanas.
     */
    private const PISO_DE_FILAS = 0.9;

    /**
     * Sincroniza todos los clientes del ERP a la tabla local.
     *
     * @return int Total de registros sincronizados
     *
     * @throws RpSistemasException si la lectura vino vacía o sospechosamente corta
     */
    public function sync(): int
    {
        Log::info('RpSistemas: iniciando sincronización de clientes');

        $total = $this->escribir($this->leer(), Carbon::now());

        Log::info("RpSistemas: sincronización completada — {$total} clientes procesados");

        return $total;
    }

    /**
     * Los clientes del ERP, una fila por cliente.
     *
     * Es la única parte que necesita SQL Server: el resto se prueba sin él.
     *
     * @return list<array<string, mixed>>
     */
    public function leer(): array
    {
        $estados = "'".implode("','", self::ESTADOS)."'";

        $filas = DB::connection('erp')->select(<<<SQL
            SELECT c.NUM_CLIENTE AS numero,
                   c.RAZON AS razon,
                   c.NOM_FANTASIA AS nombre_fantasia,
                   c.CUIT AS cuit,
                   c.IVA AS iva,
                   c.TELEFONO AS telefono,
                   c.MAIL AS mail,
                   c.CONTACTO AS contacto,
                   c.DOMICILIO AS domicilio,
                   c.LOCALIDAD AS localidad,
                   c.PROVINCIA AS codigo_provincia,
                   p.NOMBRE AS descripcion_provincia,
                   c.PORCEN_DESCUEN AS porcen_descuen,
                   c.USUARIO_WEB AS usuario_web,
                   c.CODI_VENDE AS codigo_vendedor,
                   v.NOMBRE AS nombre_vendedor,
                   c.CP AS codigo_postal
            FROM CLIENTES c
            LEFT JOIN PROVINCIAS p ON p.CODI = c.PROVINCIA
            LEFT JOIN VENDEDORES v ON v.CODI_VENDE = c.CODI_VENDE
            WHERE c.ESTADO IN ({$estados})
            SQL);

        return array_map(fn ($fila) => (array) $fila, $filas);
    }

    /**
     * Guarda las filas leídas, **escribiendo solo las que cambiaron**. Público
     * para probarlo sin SQL Server.
     *
     * ⚠️ Antes se reescribían las ~3.800 en cada corrida (el `upsert` siempre
     * cambia `synced_at`), y con el log binario de MySQL en formato ROW eso eran
     * ~2 MB por corrida × 288 corridas por día = ~560 MB diarios para refrescar un
     * campo de fecha (medido el 12/11/2026: 0,05 MB por corrida antes del cambio a
     * SQL, 1,95 MB después). Ahora se lee todo del ERP igual —así un cambio de
     * provincia o de vendedor, que no mueve `FECHA_MODI`, igual se ve— pero se
     * compara contra lo guardado y una corrida sin novedades no escribe nada.
     *
     * Consecuencia: **`synced_at` es "cuándo cambió por última vez", no "cuándo se
     * vio"**. El latido de la sync vive aparte (`CLAVE_ULTIMA_SYNC`).
     *
     * @param  list<array<string, mixed>>  $filas
     * @return int Cantidad de clientes procesados (leídos del ERP)
     *
     * @throws RpSistemasException
     */
    public function escribir(array $filas, Carbon $syncedAt): int
    {
        $this->comprobarQueNoVengaIncompleto(count($filas));

        $mapeadas = [];

        foreach ($filas as $fila) {
            $cliente = $this->mapear($fila, $syncedAt);
            $mapeadas[$cliente['numero']] = $cliente;
        }

        $locales = DB::table('clientes')->get(['numero', ...self::COLUMNAS_DEL_ERP])->keyBy('numero');

        $cambiados = array_values(array_filter(
            $mapeadas,
            fn ($cliente) => $this->cambio($cliente, $locales->get($cliente['numero'])),
        ));

        foreach (array_chunk($cambiados, self::CHUNK) as $bloque) {
            // No incluir 'fecha_vencimiento' ni 'mail_nuevo' aquí: son campos
            // propios (no gestionados por el ERP) que se editan a mano o los
            // carga el propio cliente desde el portal, y la sync nunca debe
            // pisar. Tampoco `tipo_cliente`, `tiene_legajo`, `habilitado`, `notas`
            // ni `documentacion_completa`. Ojo que 'mail' sí está: ese es el del
            // ERP y se sobrescribe.
            Cliente::upsert($bloque, ['numero'], [...self::COLUMNAS_DEL_ERP, 'synced_at', 'updated_at']);
        }

        // Los que no vinieron esta vez: inactivos o dados de baja en el ERP.
        // Quedan como están (la sync nunca borra), pero conviene que se note.
        $sinVer = $locales->keys()->diff(array_keys($mapeadas))->count();

        if ($sinVer > 0) {
            Log::info("RpSistemas: {$sinVer} clientes locales no figuran entre los activos del ERP (inactivos o dados de baja)");
        }

        // El latido: la pantalla de Clientes muestra "Última sincronización", y con
        // escritura selectiva `max(synced_at)` ya no lo dice (una corrida sin
        // novedades no toca ninguna fila). Una sync trabada sigue notándose.
        Cache::forever(self::CLAVE_ULTIMA_SYNC, $syncedAt->toDateTimeString());

        Log::info('RpSistemas: clientes escritos en esta corrida — '.count($cambiados).' de '.count($mapeadas));

        return count($mapeadas);
    }

    /** ¿Hay que escribir este cliente? Es nuevo, o alguna columna del ERP es distinta de la guardada. */
    private function cambio(array $nuevo, ?object $actual): bool
    {
        if ($actual === null) {
            return true;
        }

        foreach (self::COLUMNAS_DEL_ERP as $columna) {
            if (! $this->igual($columna, $nuevo[$columna] ?? null, $actual->{$columna} ?? null)) {
                return true;
            }
        }

        return false;
    }

    private function igual(string $columna, mixed $nuevo, mixed $guardado): bool
    {
        if ($nuevo === null || $guardado === null) {
            return $nuevo === $guardado;
        }

        // El ERP manda ".0000" y la columna es decimal(8,2): se compara ya redondeado,
        // o el cliente con 10,5 % se reescribiría en cada corrida.
        if ($columna === 'porcen_descuen') {
            return round((float) $nuevo, 2) === round((float) $guardado, 2);
        }

        return (string) $nuevo === (string) $guardado;
    }

    /**
     * Una lectura vacía o muy corta no es "terminé bien": es el síntoma del
     * estancamiento que escondió este sync durante casi tres semanas. Tira una
     * excepción para que sea un job fallido y no un éxito con datos parciales.
     */
    private function comprobarQueNoVengaIncompleto(int $leidos): void
    {
        if ($leidos === 0) {
            throw new RpSistemasException('El ERP no devolvió ningún cliente.', 'CLIENTES');
        }

        $guardados = Cliente::count();

        if ($leidos < (int) floor($guardados * self::PISO_DE_FILAS)) {
            throw new RpSistemasException(
                "El ERP devolvió {$leidos} clientes y ya hay {$guardados} guardados: la lectura vino incompleta, no se sincroniza.",
                'CLIENTES',
            );
        }
    }

    /**
     * Fila del ERP => columnas locales. Público para probarlo sin SQL Server.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt): array
    {
        $now = $syncedAt->toDateTimeString();

        // Vacío o solo espacios = sin dato. El texto no se recorta: el ERP guarda
        // " KEES CRISTINA EVELIN" con el espacio y así lo mostraba la API.
        $texto = function (string $clave) use ($fila): ?string {
            $valor = $fila[$clave] ?? null;

            return ($valor === null || trim((string) $valor) === '') ? null : (string) $valor;
        };

        $iva = $texto('iva');

        return [
            'numero' => (string) ($fila['numero'] ?? ''),
            'razon_social' => (string) ($fila['razon'] ?? ''),
            'nombre_fantasia' => $texto('nombre_fantasia'),
            'cuit' => $texto('cuit'),
            'codigo_iva' => $iva,
            'descripcion_iva' => $iva === null ? null : (self::CONDICIONES_IVA[(int) $iva] ?? null),
            'telefono' => $texto('telefono'),
            'mail' => $texto('mail'),
            'contacto' => $texto('contacto'),
            'domicilio' => $texto('domicilio'),
            'localidad' => $texto('localidad'),
            'codigo_provincia' => $texto('codigo_provincia'),
            'descripcion_provincia' => $texto('descripcion_provincia'),
            'porcen_descuen' => isset($fila['porcen_descuen']) && $fila['porcen_descuen'] !== '' ? (float) $fila['porcen_descuen'] : null,
            'usuario_web' => $texto('usuario_web'),
            'codigo_vendedor' => $texto('codigo_vendedor'),
            'nombre_vendedor' => $texto('nombre_vendedor'),
            'codigo_postal' => $texto('codigo_postal'),
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
}
