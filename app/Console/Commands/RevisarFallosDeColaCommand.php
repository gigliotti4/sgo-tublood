<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Notifications\JobsFallandoNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Avisa cuando una tarea de fondo está fallando más de lo normal.
 *
 * Existe por un agujero medido: entre el 24/8 y el 29/9/2026 se acumularon 311
 * jobs fallidos —310 de `SyncClientesJob`— y **nadie se enteró en 36 días**. No
 * rompió nada, pero un montón de fallos conocidos es lo que esconde el fallo
 * que sí importa.
 *
 * Los umbrales viven en `config/colas.php`: ver ahí por qué son distintos según
 * la clase.
 */
class RevisarFallosDeColaCommand extends Command
{
    protected $signature = 'colas:revisar-fallos';

    protected $description = 'Avisa a los super-admin si alguna tarea de fondo está fallando más de lo normal';

    public function handle(): int
    {
        $horas = (int) config('colas.ventana_horas', 24);
        $porDefecto = (int) config('colas.umbral_fallos.default', 3);
        $porClase = config('colas.umbral_fallos.por_clase', []);

        $fallos = DB::table('failed_jobs')
            ->where('failed_at', '>=', now()->subHours($horas))
            ->pluck('payload')
            ->countBy(fn (string $payload) => $this->claseDe($payload));

        // Solo las que pasaron SU umbral. Una clase ruidosa conocida no dispara
        // nada hasta que se sale de su rango habitual.
        $excedidas = $fallos
            ->filter(fn (int $veces, string $clase) => $veces >= (int) ($porClase[$clase] ?? $porDefecto))
            ->all();

        if ($excedidas === []) {
            $this->info("Sin fallos por encima del umbral en las últimas {$horas} h (total: {$fallos->sum()}).");

            return self::SUCCESS;
        }

        $destinatarios = User::query()
            // ⚠️ `whereHas` y nunca el scope `User::role()` de Spatie, que tira
            // `RoleDoesNotExist` si el rol no está creado: una tarea agendada no
            // puede caerse por eso. Mismo criterio que en el alta del portal.
            ->whereHas('roles', fn ($q) => $q->where('name', User::ROL_SUPER_ADMIN))
            ->get();

        if ($destinatarios->isEmpty()) {
            $this->warn('Hay fallos por encima del umbral pero no hay super-admin a quién avisarle.');

            return self::SUCCESS;
        }

        try {
            Notification::send($destinatarios, new JobsFallandoNotification($excedidas, $horas));
        } catch (\Throwable $e) {
            // Que el aviso falle no puede tumbar la tarea: lo que importa ya
            // quedó en el log, y encima estaríamos avisando de que algo falla.
            Log::error('No se pudo avisar de los jobs fallando: '.$e->getMessage());
        }

        $this->warn('Avisado: '.json_encode($excedidas));

        return self::SUCCESS;
    }

    /**
     * La clase del job, sacada del payload serializado.
     *
     * ⚠️ Se lee `displayName`, que es lo que Laravel guarda con el nombre
     * legible. No se deserializa el payload: un job cuya clase ya no existe
     * —porque se renombró o se borró— haría estallar el `unserialize()`, y
     * justo esos son los que más interesa contar.
     */
    private function claseDe(string $payload): string
    {
        $datos = json_decode($payload, true);

        return $datos['displayName'] ?? ($datos['job'] ?? 'desconocido');
    }
}
