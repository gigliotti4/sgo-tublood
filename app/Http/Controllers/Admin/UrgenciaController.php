<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProformasJob;
use App\Models\Proforma;
use App\Support\Urgencia;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Tablero de urgencias en facturas proforma (pedido de Tublood del 22/10/2026).
 *
 * Reemplaza al HTML suelto que leía un Excel exportado a mano
 * (dashboard_urgencias_proformas_1.html): lee las proformas sincronizadas del
 * ERP. Una proforma es urgente si su observación general lo dice, aunque esté
 * mal tipeado — ver `App\Support\Urgencia`.
 *
 * Mismo esquema que Compras: el dataset viaja **entero** y compacto, y los
 * filtros (mes, vendedor, cliente) se resuelven en el navegador
 * (`resources/js/lib/urgencias.ts`). Son ~14.000 proformas de pocos bytes cada
 * una, y así cambiar el mes o pararse sobre un vendedor no es un request.
 *
 * Cada proforma va como `[mes, vendedor, cliente, urgente]` (índices a las
 * listas `meses`, `vendedores` y `clientes`). Las urgentes van además en
 * `urgentes` con número, fecha y observación, para el listado de control: son
 * ~150, y mandar la observación de las 14.000 triplicaría el payload sin que la
 * pantalla la use.
 */
class UrgenciaController extends Controller
{
    public function index(): Response
    {
        $this->authorize('urgencias.view');

        $meses = $vendedores = $clientes = [];
        $filas = $urgentes = [];

        $indice = static function (array &$lista, string $valor): int {
            return $lista[$valor] ??= count($lista);
        };

        $proformas = Proforma::query()
            ->orderBy('fecha')
            ->orderBy('compro_nro')
            ->get(['compro_nro', 'fecha', 'cliente_numero', 'razon_social', 'vendedor', 'observacion']);

        foreach ($proformas as $p) {
            $m = $indice($meses, $p->fecha->format('Y-m'));
            $v = $indice($vendedores, $p->vendedor ?? '(sin vendedor)');
            $c = $indice($clientes, trim(($p->razon_social ?? 'Sin razón social').($p->cliente_numero ? " ({$p->cliente_numero})" : '')));
            $u = Urgencia::detectar($p->observacion);

            $filas[] = [$m, $v, $c, $u ? 1 : 0];

            if ($u) {
                $urgentes[] = [
                    'n' => $p->compro_nro,
                    'f' => $p->fecha->toDateString(),
                    'm' => $m,
                    'v' => $v,
                    'c' => $c,
                    'o' => $p->observacion,
                ];
            }
        }

        // ⚠️ `strval`: PHP convierte en int las claves que parecen números (la
        // misma trampa que `desdeKardex()`), y un nombre "123" llegaría al front
        // como número — el filtro de cliente compara strings y no lo encontraría.
        return inertia('Admin/Urgencias/Index', [
            'meses' => array_map('strval', array_keys($meses)),
            'vendedores' => array_map('strval', array_keys($vendedores)),
            'clientes' => array_map('strval', array_keys($clientes)),
            'filas' => $filas,
            'urgentes' => $urgentes,
            'lastSync' => Proforma::max('synced_at'),
        ]);
    }

    public function sync(): RedirectResponse
    {
        $this->authorize('urgencias.sync');

        SyncProformasJob::dispatch();

        return back(fallback: route('urgencias.index'))
            ->with('success', 'Sincronización iniciada. Los datos se actualizarán en breve.');
    }
}
