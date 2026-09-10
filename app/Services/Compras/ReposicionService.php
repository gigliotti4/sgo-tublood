<?php

namespace App\Services\Compras;

use App\Models\CompraOrdenPendiente;
use App\Models\CompraPedidoPendiente;
use App\Models\ComprasArticulo;
use App\Models\Venta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Arma el dataset del tablero de reposición.
 *
 * ⚠️ Devuelve datos CRUDOS por artículo, no las columnas calculadas. Promedio
 * mensual, meses de cobertura, cantidad a comprar y clase Pareto dependen del
 * período y del objetivo que elija el usuario, y se recalculan en el navegador
 * en `resources/js/lib/compras.ts`. Duplicar esas fórmulas acá para poder
 * exportar del lado del servidor es exactamente donde se desincronizarían: por
 * eso el export también se genera en el cliente.
 *
 * Lo que sí resuelve este servicio, porque el cliente no podría:
 *
 *  - la unificación multimarca por GTIN (necesita el catálogo entero),
 *  - el cruce contra ventas, OC pendientes y reservas,
 *  - recortar el historial a los meses que se muestran.
 *
 * Las claves de salida son de una letra a propósito: son ~4.800 grupos con
 * ~5.200 artículos y 25 meses cada uno, y el payload viaja entero en cada carga
 * de la página. Con nombres largos pasaría de ~900 KB a más de 2 MB.
 */
class ReposicionService
{
    /**
     * Versión de la FORMA del dataset. Va en la clave de cache.
     *
     * ⚠️ Subirla cada vez que cambie qué campos se emiten. El resto de la clave
     * son los `synced_at` y un hash del config, así que un cambio de código no
     * la invalida: sin esto, agregar un campo deja la pantalla leyendo el
     * dataset viejo hasta la próxima sincronización — y el campo nuevo llega
     * como `undefined` sin que nada falle a la vista. Ya pasó una vez, al
     * agregar `id`.
     */
    private const VERSION_DATASET = 2;

    /**
     * Dataset listo para el tablero, cacheado.
     *
     * La clave incluye el `synced_at` de las cuatro fuentes y un hash del
     * config, así que se invalida sola: al sincronizar (agendado o con el botón)
     * y al tocar una regla de config/compras.php. No hace falta limpiarla a mano
     * en ningún lado.
     *
     * @return array{meses: list<string>, groups: list<array<string, mixed>>}
     */
    public function dataset(): array
    {
        return Cache::remember($this->claveDeCache(), now()->addDay(), fn () => $this->construir());
    }

    /**
     * Igual que `dataset()` pero sin cache. Es el punto de entrada de los tests.
     *
     * @return array{meses: list<string>, groups: list<array<string, mixed>>}
     */
    public function construir(): array
    {
        $meses = $this->meses();
        $indiceMes = array_flip($meses);

        $ventas = $this->ventasPorArticuloYMes($meses, $indiceMes);
        $ocPendiente = $this->totalPorArticulo(CompraOrdenPendiente::query());
        $reservado = $this->totalPorArticulo(CompraPedidoPendiente::query()->reservadas());

        $grupos = [];

        foreach (ComprasArticulo::query()->orderBy('codigo')->cursor() as $articulo) {
            $codigo = $articulo->codigo;
            $gtin = $this->gtinDeAgrupacion($articulo->gtin);

            // Sin GTIN utilizable, el artículo es su propio grupo. La clave lleva
            // el código para que dos artículos sin GTIN nunca se junten.
            $clave = $gtin !== null ? 'g:'.mb_strtoupper($gtin) : 'a:'.$codigo;

            $grupos[$clave] ??= [
                // ⚠️ Clave estable y única. El nombre NO sirve: 73 descripciones
                // se repiten entre 166 artículos distintos sin GTIN (dos
                // "EMBUDO VIDRIO 9CM" que son códigos diferentes). Usar el
                // nombre como key de fila hacía que desplegar uno desplegara el
                // otro, y que Vue reusara el DOM del que no era.
                'id' => $clave,
                'n' => $gtin ?? ($articulo->descripcion ?? $codigo),
                'c' => [],
                'u' => null,
                'i' => [],
            ];

            $categoria = $this->categoria($articulo);
            $envase = $articulo->envase();

            $item = [
                'c' => $codigo,
                'd' => $articulo->descripcion ?? '',
                'a' => $articulo->activo ? 1 : 0,
                'u' => $envase,
                'k' => $categoria,
                // Stock CRUDO, negativos incluidos: la pantalla los cuenta como 0
                // y marca el producto con una advertencia. Corregirlo acá
                // escondería el error de carga del ERP.
                's' => $this->limpiar((float) $articulo->cant_stock),
                'r' => $this->limpiar($reservado[$codigo] ?? 0.0),
                'o' => $this->limpiar($ocPendiente[$codigo] ?? 0.0),
            ];

            // `v` y `m` se omiten cuando el artículo nunca vendió: son ~4.400 de
            // ~5.200 artículos, y serían dos arrays de 25 ceros cada uno.
            if (isset($ventas[$codigo])) {
                $item['v'] = $ventas[$codigo]['v'];
                $item['m'] = $ventas[$codigo]['m'];
            }

            $grupos[$clave]['i'][] = $item;

            if (! in_array($categoria, $grupos[$clave]['c'], true)) {
                $grupos[$clave]['c'][] = $categoria;
            }

            // `u` del grupo es SOLO para mostrar: 0 significa "varios". El
            // cálculo usa el envase propio de cada artículo (`i[].u`), así un
            // grupo que mezcla x500 y x150 se unifica bien al pasar a unidades.
            $grupos[$clave]['u'] = $grupos[$clave]['u'] === null
                ? $envase
                : ($grupos[$clave]['u'] === $envase ? $envase : 0);
        }

        foreach ($grupos as &$grupo) {
            sort($grupo['c']);
        }

        return [
            'meses' => $meses,
            'groups' => array_values($grupos),
        ];
    }

    /**
     * Los últimos meses con ventas, en orden cronológico.
     *
     * Se leen de los datos y no se generan desde una fecha fija: `ventas` cubre
     * desde 2024-08 y crece sola con cada sincronización.
     *
     * @return list<string>
     */
    public function meses(): array
    {
        $ultima = Venta::query()->max('fecha');

        if ($ultima === null) {
            return [];
        }

        $fin = Carbon::parse($ultima)->startOfMonth();
        $cantidad = max(1, (int) config('compras.meses_historial', 25));

        $meses = [];

        for ($i = $cantidad - 1; $i >= 0; $i--) {
            $meses[] = $fin->copy()->subMonths($i)->format('Y-m');
        }

        return $meses;
    }

    /**
     * Unidades e importe vendidos por artículo y mes.
     *
     * ⚠️ Se agrupa en PHP y no con `DATE_FORMAT`/`strftime` para no ramificar por
     * driver: los tests corren en SQLite y la app en MySQL. Son ~60.000 renglones
     * leídos con `cursor()`, del orden de 150 ms, y el resultado va a cache.
     *
     * ⚠️ `cantidad` y `sub_total` se suman TAL CUAL. Las notas de crédito
     * (comprobantes CEA/CEB/CA1…) ya vienen en negativo desde el ERP y por eso
     * restan solas. Negarlas las pasaría a positivo y sumarían ventas en vez de
     * restarlas — ese error infló la facturación un 35% en el prototipo.
     *
     * @param  list<string>  $meses
     * @param  array<string, int>  $indiceMes
     * @return array<string, array{v: list<float>, m: list<float>}>
     */
    private function ventasPorArticuloYMes(array $meses, array $indiceMes): array
    {
        if ($meses === []) {
            return [];
        }

        $cantidad = count($meses);
        $desde = Carbon::createFromFormat('Y-m-d', $meses[0].'-01')->startOfMonth();

        $ventas = [];

        // `toBase()` para saltear la hidratación de Eloquent: son ~60.000 filas
        // y construir un modelo por cada una llevaba el armado del dataset de
        // ~1 s a ~6 s. Acá solo se suman cuatro columnas.
        $filas = Venta::query()
            ->whereNotNull('articulo')
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->select('articulo', 'fecha', 'cantidad', 'sub_total')
            ->toBase()
            ->cursor();

        foreach ($filas as $fila) {
            // Sin el cast de Eloquent, `fecha` es el string crudo del driver.
            // Los primeros 7 caracteres son 'YYYY-MM' tanto si viene como
            // 'YYYY-MM-DD' (MySQL) como con la hora pegada (SQLite).
            $indice = $indiceMes[substr((string) $fila->fecha, 0, 7)] ?? null;

            if ($indice === null) {
                continue;
            }

            $codigo = mb_strtoupper(trim((string) $fila->articulo));

            $ventas[$codigo] ??= [
                'v' => array_fill(0, $cantidad, 0.0),
                'm' => array_fill(0, $cantidad, 0.0),
            ];

            $ventas[$codigo]['v'][$indice] += (float) $fila->cantidad;
            $ventas[$codigo]['m'][$indice] += (float) $fila->sub_total;
        }

        foreach ($ventas as &$fila) {
            $fila['v'] = array_map($this->limpiar(...), $fila['v']);
            $fila['m'] = array_map($this->limpiar(...), $fila['m']);
        }

        return $ventas;
    }

    /**
     * `SUM(cant_pend)` por artículo para la query que se le pase.
     *
     * @param  Builder<*>  $query
     * @return array<string, float>
     */
    private function totalPorArticulo(Builder $query): array
    {
        return $query
            ->groupBy('articulo')
            ->select('articulo', DB::raw('SUM(cant_pend) as total'))
            ->pluck('total', 'articulo')
            ->map(fn ($total) => (float) $total)
            ->all();
    }

    /**
     * El GTIN con el que se unifica, o null si no sirve para agrupar.
     *
     * ⚠️ `GTIN` no es un código de barras: es una clasificación de texto cargada
     * a mano ("AGUJA 25/6 (23GX1)", "GUANTES DE LATEX M"), llena de comodines
     * —"NO APLICA", "N/A", "0", más typos—. Se descartan por patrón y no por
     * lista fija porque van a aparecer typos nuevos. Ver config/compras.php.
     */
    private function gtinDeAgrupacion(?string $gtin): ?string
    {
        $gtin = trim((string) $gtin);

        if ($gtin === '') {
            return null;
        }

        return preg_match(config('compras.gtin_comodin'), $gtin) === 1 ? null : $gtin;
    }

    /**
     * La categoría del artículo.
     *
     * Los que no mueven stock van a una categoría propia SERVICIOS y quedan
     * fuera del cálculo salvo que el usuario los pida: son servicios y mano de
     * obra, y su "stock" no significa nada.
     */
    private function categoria(ComprasArticulo $articulo): string
    {
        if ($articulo->sin_stock) {
            return 'SERVICIOS';
        }

        $agru = trim((string) $articulo->agru_1);

        return $agru === '' ? 'SIN_CAT' : $agru;
    }

    /**
     * Recorta decimales de ruido antes de serializar.
     *
     * Los `decimal(16,4)` del ERP llegan como 8742.0000 y en JSON ocuparían el
     * doble sin aportar nada: acá nada se cuenta en milésimas.
     */
    private function limpiar(float $valor): float
    {
        return round($valor, 2);
    }

    private function claveDeCache(): string
    {
        $sellos = [
            ComprasArticulo::max('synced_at'),
            CompraOrdenPendiente::max('synced_at'),
            CompraPedidoPendiente::max('synced_at'),
            Venta::max('synced_at'),
            md5(serialize(config('compras'))),
        ];

        return 'compras:reposicion:v'.self::VERSION_DATASET.':'.md5(implode('|', array_map(strval(...), $sellos)));
    }
}
