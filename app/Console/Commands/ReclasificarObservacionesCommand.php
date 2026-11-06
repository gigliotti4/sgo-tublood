<?php

namespace App\Console\Commands;

use App\Models\Observacion;
use App\Models\ObservationHistory;
use App\Models\Sector;
use App\Services\CambioDeTipo;
use App\Support\TaxonomiaIncidencias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pasa los casos ya cargados al tipo que les corresponde tras crear los fallos
 * externos (E.1, E.2, E.3) y retirar 3.5 y 6.2. La lista sale de
 * `config/reclasificacion.php`.
 *
 * ⚠️ **Simula por defecto.** Escribe sobre producción, así que solo cambia algo
 * con `--aplicar`, y conviene correrlo antes sin él para revisar la lista.
 *
 * Usa `CambioDeTipo`, el mismo servicio que el modal de edición, así que las
 * reglas (no cambiar a un tipo retirado, pedir productos donde hacen falta) son
 * las mismas y cada cambio deja su entrada `tipo` en la bitácora con los datos
 * que se reemplazan.
 *
 * Es idempotente: un caso que ya está en su tipo destino se saltea.
 */
class ReclasificarObservacionesCommand extends Command
{
    protected $signature = 'observaciones:reclasificar
        {--aplicar : Escribe los cambios. Sin esto solo muestra qué haría}
        {--solo=* : Limita a estos números de observación (ej. --solo=0011-26)}';

    protected $description = 'Reclasifica los casos existentes a los fallos externos (simula salvo --aplicar)';

    public function handle(CambioDeTipo $cambio): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $solo = array_filter((array) $this->option('solo'));

        $plan = [
            ...$this->casosPuntuales($solo),
            ...$this->falla11PorProducto($solo),
        ];

        if ($plan === []) {
            $this->info('No hay nada para reclasificar.');

            return self::SUCCESS;
        }

        $filas = [];
        $hechos = 0;

        foreach ($plan as $p) {
            $resultado = $this->procesar($cambio, $p, $aplicar);
            $hechos += $resultado === 'cambiado' ? 1 : 0;
            $filas[] = [$p['numero'], $p['de'] ?? '—', $p['a'] ?? '—', $resultado];
        }

        $this->table(['N.º', 'Tipo actual', 'Tipo destino', 'Resultado'], $filas);

        $this->newLine();
        $this->info($aplicar
            ? "Reclasificadas: {$hechos}."
            : "Simulación: {$hechos} se reclasificarían. Revisá la lista y corré con --aplicar para escribir.");

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $solo
     * @return list<array<string, mixed>>
     */
    private function casosPuntuales(array $solo): array
    {
        $plan = [];

        foreach (config('reclasificacion.casos', []) as $numero => $caso) {
            if ($solo !== [] && ! in_array($numero, $solo, true)) {
                continue;
            }

            $plan[] = ['numero' => $numero, 'tipo' => $caso['tipo'], 'datos' => $caso['datos'] ?? [], 'motivo' => $caso['motivo'] ?? null];
        }

        return $plan;
    }

    /**
     * Las 1.1 "Falla de producto": según el código de sus productos, las de
     * reventa pasan a E.3. Lo propio no se toca y lo dudoso se avisa.
     *
     * @param  array<int, string>  $solo
     * @return list<array<string, mixed>>
     */
    private function falla11PorProducto(array $solo): array
    {
        $regla = config('reclasificacion.producto_1_1');
        $plan = [];

        $casos = Observacion::query()->where('tipo', 'falla_producto')->with('productos:id,observation_id,codigo')->orderBy('numero')->get();

        foreach ($casos as $o) {
            if ($solo !== [] && ! in_array($o->numero, $solo, true)) {
                continue;
            }

            // Ya se reclasificó a mano (está en la lista de arriba): no se pisa.
            if (array_key_exists($o->numero, config('reclasificacion.casos', []))) {
                continue;
            }

            $codigos = $o->productos->pluck('codigo')->filter()->all();
            $reventa = $codigos !== [] && collect($codigos)->every(fn ($c) => preg_match($regla['reventa'], (string) $c));
            $propio = $codigos !== [] && collect($codigos)->every(fn ($c) => preg_match($regla['propio'], (string) $c));

            if ($reventa) {
                $plan[] = [
                    'numero' => $o->numero,
                    'tipo' => $regla['destino_reventa'],
                    'datos' => [],
                    'motivo' => 'Producto de reventa ('.implode(', ', array_unique($codigos)).'): pasa a falla externa de distribución.',
                ];
            } elseif (! $propio) {
                // Sin productos, o de un origen que la regla no reconoce, o mezclado.
                $plan[] = ['numero' => $o->numero, 'tipo' => null, 'datos' => [], 'motivo' => null, 'dudoso' => true];
            }
        }

        return $plan;
    }

    /**
     * @param  array<string, mixed>  $p
     */
    private function procesar(CambioDeTipo $cambio, array &$p, bool $aplicar): string
    {
        $o = Observacion::where('numero', $p['numero'])->first();

        if (! $o) {
            return 'no existe';
        }

        $p['de'] = $o->tipo;

        if (! empty($p['dudoso'])) {
            return 'a confirmar (origen del producto)';
        }

        $p['a'] = $p['tipo'];

        if ($o->tipo === $p['tipo']) {
            return 'ya estaba';
        }

        $sectorSlug = TaxonomiaIncidencias::sectorDeTipo($p['tipo']);
        $sector = $sectorSlug ? Sector::where('slug', $sectorSlug)->first() : null;

        if (! $sector) {
            return "falta el sector '{$sectorSlug}' (corré el SectorSeeder)";
        }

        try {
            $esEspecial = $cambio->comprobar($o, $sector, $p['tipo']);
        } catch (ValidationException $e) {
            return 'no se puede: '.collect($e->errors())->flatten()->first();
        }

        if (! $aplicar) {
            return 'se cambiaría';
        }

        DB::transaction(function () use ($cambio, $o, $sector, $p, $esEspecial) {
            // Los especiales no llevan "Datos específicos"; el resto recibe lo que se sabe del caso.
            $cambio->aplicar($o, $sector, $p['tipo'], $esEspecial ? null : $p['datos']);

            if ($p['motivo']) {
                $o->historial()->create([
                    'user_id' => null,
                    'accion' => ObservationHistory::ACCION_SISTEMA,
                    'nota' => 'Reclasificación: '.$p['motivo'],
                ]);
            }
        });

        return 'cambiado';
    }
}
