<?php

namespace App\Services\RpSistemas;

use App\Models\Articulo;
use App\Services\VinculacionProveedores;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Espeja el catálogo "vendible" del ERP en la tabla local `articulos`.
 *
 * ⚠️ **Hasta el 30/9/2026 esto entraba por HTTP** (`articulos.php` vía
 * `RpSistemasClient`). Se pasó a SQL porque el endpoint exponía ~15 campos y la
 * tabla `ARTICULOS` tiene 142: de ahí salen el registro de ANMAT
 * (`NRO_REGISTRO`) y el tipo de producto (`ID_ARTI_TIPO`), que antes se cargaban
 * a mano desde el Excel de Calidad y estaban vacíos en los 748 artículos.
 *
 * ⚠️ `ARTICULOS` es una TABLA BASE del ERP, no una vista habilitada para
 * nosotros. Las vistas `powerbi_*` son un contrato: RP las expuso a propósito.
 * Ésta la leemos porque tenemos SELECT, sin garantía de que no le cambien una
 * columna — mismo riesgo que `COMPRO_PARTIDAS`. Si el sync se rompe de golpe,
 * empezar por ahí. RP tiene una "VISTA ARTICULOS" especificada pero **todavía no
 * publicada** (verificado el 30/9/2026); el día que exista, esto se muda a ella
 * cambiando la constante `TABLA` y poco más.
 *
 * ⚠️ **El filtro es lo que define el catálogo, y no es negociable.** `ARTICULOS`
 * tiene 5.236 filas y esta tabla tiene que quedarse con las ~748 del catálogo
 * vendible: el buscador del portal público lee de acá. La regla es
 * `ACTIVO = 'S'` **y** estar en la lista de precios CAT-A. Medido el 30/9/2026:
 * ACTIVO solo da 941, la lista sola da 4.025, y la intersección da exactamente
 * los 738 que devolvía el endpoint HTTP. Sacar cualquiera de las dos mitades
 * multiplica por 5 lo que ve el cliente en el portal.
 */
class ArticuloSyncService
{
    private const TABLA = 'ARTICULOS';

    /** La vista de la lista de precios A, que es la que define qué se vende. */
    private const VISTA_LISTA = 'powerbi_lista_precios_A_vista';

    /** Contrato de RP, a diferencia de `ARTICULOS`. Trae el stock por depósito. */
    private const VISTA_STOCK = 'powerbi_stock_vista';

    /** Traduce AGRU_1/2/3 a su descripción. `NUM_AGRU` dice de qué eje es. */
    private const TABLA_AGRUPACIONES = 'AGRUPACIONES';

    private const CHUNK = 500;

    /**
     * Sincroniza el catálogo de artículos de RP Sistemas a la tabla local.
     * Upsert por `codigo` (idempotente).
     *
     * El estado `activo` **no** sale de la columna `ACTIVO` del ERP aunque
     * exista: sale de estar o no en el resultado de esta consulta, que ya la
     * incluye junto con el filtro de lista de precios. Lo que llega queda
     * `activo`, y lo que dejó de venir se marca `activo = false` comparando
     * `synced_at` contra el timestamp de esta corrida — sin necesidad de un
     * `whereNotIn` con miles de códigos. Se conservó el mecanismo tal cual
     * estaba en la época del feed HTTP: es el que hace que un artículo que sale
     * de la lista de precios desaparezca del portal sin borrarse.
     *
     * Al final intenta vincular el proveedor por `codigo_proveedor`. Ese campo
     * viene casi vacío (147 de 5.236 artículos en el ERP) y de lo cargado la
     * mayoría no son códigos de proveedor sino costos tipeados en el lugar
     * equivocado — pero cuando trae un entero limpio, matchea el padrón el 100%
     * de las veces. Qué puede pisar y qué no lo decide `VinculacionProveedores`.
     *
     * @return array{procesados: int, activos: int, desactivados: int, proveedores_vinculados: int}
     */
    public function sync(): array
    {
        $total = 0;
        $syncedAt = Carbon::now();
        // Con microsegundos: la comparación de más abajo distingue dos
        // corridas seguidas (dos clics en "Sincronizar") aunque caigan dentro
        // del mismo segundo — con precisión de segundo compartirían el mismo
        // valor y la desactivación no detectaría nada.
        $syncedAtValor = $syncedAt->format('Y-m-d H:i:s.u');

        Log::info('RpSistemas: iniciando sincronización de artículos por SQL');

        $filas = DB::connection('erp')->select($this->sql());

        foreach (collect($filas)->chunk(self::CHUNK) as $bloque) {
            $lote = $bloque->map(fn ($fila) => $this->mapear((array) $fila, $syncedAt, $syncedAtValor))->all();

            // No incluir 'fecha_vencimiento', 'legajo', 'observaciones',
            // 'link_registro', 'proveedor_id' ni 'proveedor_origen' acá: son
            // campos propios (no gestionados por el ERP) que se cargan a mano o
            // por Excel desde el panel, y la sync nunca debe pisarlos. Mismo
            // contrato que ClienteSyncService con 'fecha_vencimiento'.
            //
            // ⚠️ 'pm' NO va en esta lista: se escribe aparte, más abajo, porque
            // el ERP lo completa pero nunca lo borra. Ver `escribirElPm()`.
            //
            // 'codigo_proveedor' sí va: es el string del ERP, distinto de la FK
            // 'proveedor_id' que resuelve contra el padrón local.
            //
            // 'activo' va porque lo determina el ERP, no el panel.
            //
            // El resto de los campos propios ('fecha_vencimiento', 'legajo',
            // 'observaciones', 'link_registro', 'proveedor_id',
            // 'proveedor_origen') queda afuera: se cargan a mano o por Excel
            // desde el panel y la sync nunca debe pisarlos. Mismo contrato que
            // ClienteSyncService con 'fecha_vencimiento'.
            Articulo::upsert(
                $lote,
                ['codigo'],
                [
                    'descripcion', 'descripcion_adicional', 'codigo_barras', 'unidad_medida',
                    'codigo_agrupacion_1', 'descripcion_agrupacion_1',
                    'codigo_agrupacion_2', 'descripcion_agrupacion_2',
                    'codigo_agrupacion_3', 'descripcion_agrupacion_3',
                    'stock', 'stock_disponible', 'codigo_proveedor',
                    'tipo_anmat',
                    'modificado_en', 'synced_at', 'activo', 'updated_at',
                ]
            );

            $this->escribirElPm($lote);

            $total += count($lote);
        }

        $desactivados = $this->desactivarLosQueNoVinieron($syncedAtValor, $total);

        // La regla de quién puede pisar el proveedor de un artículo vive en
        // VinculacionProveedores, no acá: la comparten esta sync, el import de
        // Excel y la edición manual del panel, y si se duplicara las tres
        // podrían discrepar. Ver la tabla de precedencia ahí.
        $vinculados = VinculacionProveedores::desdeCodigoProveedor();

        Log::info("RpSistemas: sincronización completada — {$total} artículos procesados, {$desactivados} desactivados, {$vinculados} proveedores vinculados");

        return ['procesados' => $total, 'activos' => $total, 'desactivados' => $desactivados, 'proveedores_vinculados' => $vinculados];
    }

    /**
     * El registro de ANMAT: **el ERP completa, pero nunca borra**.
     *
     * ⚠️ Ésta es la regla que evita una pérdida de datos medida. Al 30/9/2026
     * producción tenía `pm` cargado a mano en 324 artículos, y `NRO_REGISTRO`
     * viene vacío para 35 de ellos: un upsert que incluyera `pm` en su lista de
     * columnas los habría borrado en la primera corrida, en silencio. Por eso
     * la columna se escribe en un upsert aparte, restringido a las filas que
     * traen un registro de verdad.
     *
     * La contrapartida, asumida a propósito: si alguien **borra** un registro en
     * el ERP para corregir un error, acá no se borra solo y hay que limpiarlo a
     * mano. Se prefiere eso a destruir 35 registros regulatorios.
     *
     * Por eso también `ArticuloImportService` y `ArticuloController::update()`
     * dejaron de escribir `pm`: con tres fuentes escribiendo la misma columna,
     * la última en correr gana y nadie entiende por qué.
     *
     * @param  array<int, array<string, mixed>>  $lote
     */
    private function escribirElPm(array $lote): void
    {
        $conRegistro = array_values(array_filter($lote, fn (array $f) => $f['pm'] !== null));

        if ($conRegistro === []) {
            return;
        }

        Articulo::upsert($conRegistro, ['codigo'], ['pm', 'updated_at']);
    }

    /**
     * Marca inactivo lo que no vino en esta corrida.
     *
     * Público para poder testearlo sin un SQL Server: es la única parte de
     * `sync()` con lógica propia, y la que más veces se rompió. Los tests
     * siembran artículos con un `synced_at` viejo y llaman acá directo.
     */
    public function desactivarLosQueNoVinieron(string $syncedAtValor, int $procesados): int
    {
        // Nunca se desactiva sobre un resultado vacío: un mal día del ERP no
        // puede dejar el selector de productos del portal público sin nada.
        if ($procesados === 0) {
            return 0;
        }

        // `whereNotNull('synced_at')` deja afuera a los artículos que creó el
        // Excel de Calidad con "crear faltantes" porque RP no los tenía: no
        // vinieron de una sincronización de la que puedan "dejar de venir".
        //
        // `where('activo', true)` no es solo optimización: sin esto, un
        // artículo ya desactivado vuelve a tocar `updated_at` en cada corrida y
        // MySQL lo cuenta como fila afectada, así que el contador nunca bajaría
        // a 0 aunque nada cambie de verdad.
        return Articulo::whereNotNull('synced_at')
            ->where('synced_at', '<', $syncedAtValor)
            ->where('activo', true)
            ->update(['activo' => false, 'updated_at' => Carbon::now()]);
    }

    /**
     * ⚠️ La clave se normaliza con `UPPER(LTRIM(RTRIM(...)))` en el SELECT y en
     * **cada** JOIN. SQL Server ignora los espacios a la derecha pero no los de
     * la izquierda, y su collation acá distingue mayúsculas: sin normalizar,
     * `' RE-1573'` y `'RE-1573'` son dos filas distintas que después colisionan
     * contra el índice único de MySQL, que sí las considera la misma. Es el
     * mismo problema que ya documenta PartidaSyncService.
     *
     * Verificado el 30/9/2026: ni `AGRUPACIONES` ni el catálogo filtrado tienen
     * claves duplicadas, así que ningún JOIN multiplica filas.
     */
    private function sql(): string
    {
        $tabla = self::TABLA;
        $lista = self::VISTA_LISTA;
        $stock = self::VISTA_STOCK;
        $agrup = self::TABLA_AGRUPACIONES;
        // La misma lista de precios que usaba el endpoint HTTP para decidir qué
        // artículos devolver. Hoy la vista trae solo `CAT-A`, pero se filtra
        // explícito: si RP le suma otra lista, el catálogo no se duplica solo.
        // Se limpia a mano porque va interpolado y no como binding: `select()`
        // con bindings no acepta que el valor entre en una subconsulta de un
        // heredoc sin reordenar toda la query.
        $codigoLista = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) config('services.rpsistemas.lista_precios', 'CAT-A'));

        return <<<SQL
            SELECT UPPER(LTRIM(RTRIM(a.COD_ARTICULO))) COD_ARTICULO,
                   a.DESCRIP_ARTI,
                   a.DESC_ADICIONAL,
                   a.COD_BARRAS,
                   a.UM,
                   a.AGRU_1,
                   a.AGRU_2,
                   a.AGRU_3,
                   g1.DESCRIP_AGRU DESCRIP_AGRU_1,
                   g2.DESCRIP_AGRU DESCRIP_AGRU_2,
                   g3.DESCRIP_AGRU DESCRIP_AGRU_3,
                   a.COD_PROVEEDOR,
                   a.NRO_REGISTRO,
                   a.ID_ARTI_TIPO,
                   a.FECHA_MODI,
                   ISNULL(s.CANT_STOCK, 0) CANT_STOCK
            FROM {$tabla} a
            INNER JOIN (
                SELECT DISTINCT UPPER(LTRIM(RTRIM(ARTICULO))) ARTICULO
                FROM {$lista}
                WHERE LTRIM(RTRIM(LISTA_CODI)) = '{$codigoLista}'
            ) lp ON lp.ARTICULO = UPPER(LTRIM(RTRIM(a.COD_ARTICULO)))
            LEFT JOIN {$agrup} g1
                ON g1.NUM_AGRU = 1 AND LTRIM(RTRIM(g1.CODI_AGRU)) = LTRIM(RTRIM(a.AGRU_1))
            LEFT JOIN {$agrup} g2
                ON g2.NUM_AGRU = 2 AND LTRIM(RTRIM(g2.CODI_AGRU)) = LTRIM(RTRIM(a.AGRU_2))
            LEFT JOIN {$agrup} g3
                ON g3.NUM_AGRU = 3 AND LTRIM(RTRIM(g3.CODI_AGRU)) = LTRIM(RTRIM(a.AGRU_3))
            LEFT JOIN (
                SELECT UPPER(LTRIM(RTRIM(COD_ARTICULO))) COD_ARTICULO, SUM(cant_stock) CANT_STOCK
                FROM {$stock}
                GROUP BY UPPER(LTRIM(RTRIM(COD_ARTICULO)))
            ) s ON s.COD_ARTICULO = UPPER(LTRIM(RTRIM(a.COD_ARTICULO)))
            WHERE LTRIM(RTRIM(a.ACTIVO)) = 'S'
              AND NULLIF(LTRIM(RTRIM(a.COD_ARTICULO)), '') IS NOT NULL
            SQL;
    }

    /**
     * Fila de `ARTICULOS` => columnas locales.
     *
     * Público para poder testearlo sin un SQL Server: es donde se rompen los
     * nombres de columna cuando el ERP cambia la tabla.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    public function mapear(array $fila, Carbon $syncedAt, string $syncedAtValor): array
    {
        $now = $syncedAt->toDateTimeString();

        return [
            'codigo' => mb_strtoupper(trim((string) ($fila['COD_ARTICULO'] ?? ''))),
            'descripcion' => $this->texto($fila['DESCRIP_ARTI'] ?? null) ?? '',
            'descripcion_adicional' => $this->texto($fila['DESC_ADICIONAL'] ?? null),
            'codigo_barras' => $this->texto($fila['COD_BARRAS'] ?? null),
            'unidad_medida' => $this->texto($fila['UM'] ?? null),
            'codigo_agrupacion_1' => $this->texto($fila['AGRU_1'] ?? null),
            'descripcion_agrupacion_1' => $this->texto($fila['DESCRIP_AGRU_1'] ?? null),
            'codigo_agrupacion_2' => $this->texto($fila['AGRU_2'] ?? null),
            'descripcion_agrupacion_2' => $this->texto($fila['DESCRIP_AGRU_2'] ?? null),
            'codigo_agrupacion_3' => $this->texto($fila['AGRU_3'] ?? null),
            'descripcion_agrupacion_3' => $this->texto($fila['DESCRIP_AGRU_3'] ?? null),
            'stock' => $this->numero($fila['CANT_STOCK'] ?? null),
            // ⚠️ No hay equivalente en SQL. Medido el 30/9/2026 contra los diez
            // depósitos de `powerbi_stock_vista`: ninguno lo reproduce (el mejor
            // llega a 487 de 748), así que es un cálculo propio del endpoint
            // HTTP. Se escribe `null` a propósito en vez de dejar el último
            // valor que trajo la API: un número viejo congelado en el Excel
            // miente, un vacío no. Pendiente preguntarle a RP qué resta.
            'stock_disponible' => null,
            'codigo_proveedor' => $this->texto($fila['COD_PROVEEDOR'] ?? null),
            'pm' => $this->texto($fila['NRO_REGISTRO'] ?? null),
            'tipo_anmat' => $this->texto($fila['ID_ARTI_TIPO'] ?? null),
            'modificado_en' => $this->fecha($fila['FECHA_MODI'] ?? null),
            'synced_at' => $syncedAtValor,
            'activo' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * El ERP rellena con espacios los `char`, y usa cadenas vacías en vez de null.
     *
     * ⚠️ Acá **no** hace falta deshacer ningún doble encoding, a diferencia de
     * cuando esto entraba por `articulos.php`. Verificado el 30/9/2026: las
     * descripciones crudas del SQL coinciden con las que la API entregaba ya
     * reparadas en 746 de 748 artículos, y las 2 restantes difieren en el
     * contenido (una marca distinta), no en la codificación. El defecto era del
     * endpoint HTTP, no del dato.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    private function numero(mixed $valor): ?float
    {
        return ($valor === null || $valor === '') ? null : (float) $valor;
    }

    private function fecha(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        try {
            return Carbon::parse($valor)->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
