<?php

namespace App\Notifications\Concerns;

use App\Models\Observacion;
use App\Models\User;

/**
 * Lo que convierte un aviso de vencimiento en algo accionable: de quién es el
 * caso, de qué sector, desde cuándo lo tiene y hace cuánto venció.
 *
 * Lo comparten `ObservacionVencidaNotification` y
 * `ObservacionEscaladaNotification`: el supervisor y el gerente necesitan
 * exactamente el mismo dato, y escribirlo dos veces garantizaba que uno de los
 * dos mails quedara viejo. Hasta el 21/9/2026 ninguno de los dos nombraba al
 * responsable — el supervisor leía "la observación 0012-26 venció" y tenía que
 * abrir el caso para saber a quién preguntarle.
 *
 * ⚠️ El texto **no acusa a nadie** ("está a cargo de", nunca "no dio
 * respuesta"). Dos motivos: en el nivel 1 este mismo mail le llega también al
 * propio responsable, y el atraso puede no ser culpa suya (un caso sin
 * clasificar, o que depende de un tercero). El mail dice a quién preguntarle;
 * juzgar es de quien lo recibe.
 */
trait DetallaElAtraso
{
    /**
     * "Juan Pérez (Logística)", o cadena vacía si no hay responsable.
     *
     * Lo usan el asunto del mail, el cuerpo (`detalles()`) y el payload de la
     * campana (`toArray()`), que es lo que faltaba: hasta ahora el supervisor
     * veía "la observación 0012-26 venció" y tenía que abrir el caso para
     * saber a quién preguntarle.
     */
    protected function aCargoDe(): string
    {
        $responsable = $this->responsable();

        if (! $responsable) {
            return '';
        }

        $sector = $responsable->sector?->nombre;

        // Un responsable sin sector existe: el import de usuarios deja sin
        // sector a los nombres que no matchean el catálogo. No puede imprimir
        // "Juan Pérez ()".
        return $responsable->nombreCompleto.($sector ? " ({$sector})" : '');
    }

    /**
     * El payload de `database` y `broadcast`, con el responsable agregado al
     * mensaje.
     *
     * Se inyecta acá y no en `mensaje()` porque ese texto **también** es la
     * primera línea del mail, y el aviso de vencimiento le llega al propio
     * responsable: leer el nombre de uno en tercera persona justo abajo de
     * "Hola <uno>," es raro. En el mail el nombre lo pone `detalles()`, solo
     * para quien no es el responsable, y el asunto lo lleva siempre.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $datos = parent::toArray($notifiable);
        $aCargoDe = $this->aCargoDe();

        if ($aCargoDe !== '') {
            $datos['mensaje'] .= " Está a cargo de {$aCargoDe}.";
        }

        return $datos;
    }

    /**
     * Las fechas y el encuadre, solo para el mail. En la campana este bloque
     * sería una pared de texto adentro de un `<li>`.
     *
     * @return array<int, string>
     */
    protected function detalles(object $notifiable): array
    {
        $responsable = $this->responsable();

        if (! $responsable) {
            // El comando nunca alerta casos sin responsable, pero la
            // notificación puede re-renderizarse después (un reenvío, un test).
            // Quedarse sin el bloque entero es mejor que imprimir "A cargo de .".
            return [];
        }

        return array_values(array_filter([
            // El aviso de nivel 1 le llega al responsable y a su supervisor en
            // el mismo envío: un texto único no puede ser correcto para los
            // dos. Mismo patrón que la bifurcación por `$critica` de la clase
            // base y la de `$reasignada` en el aviso de asignación.
            $notifiable instanceof User && $notifiable->is($responsable)
                ? 'Esta observación está **a tu cargo**.'
                : sprintf(
                    'A cargo de **%s**%s.',
                    $this->aCargoDe(),
                    $responsable->email ? " — {$responsable->email}" : '',
                ),
            $this->lineaDeAtraso(),
            'Estado actual: '.(Observacion::ESTADOS[$this->observacion->estado] ?? $this->observacion->estado).'.',
        ]));
    }

    /**
     * El responsable con su sector, garantizando la relación.
     *
     * `loadMissing()` y no confiar en el eager load del comando: solo el canal
     * `mail` va encolado (ver `ObservacionNotification::viaConnections()`), así
     * que `toMail()` corre en el worker sobre una notificación deserializada,
     * donde no hay garantía de qué relaciones sobrevivieron.
     */
    private function responsable(): ?User
    {
        $this->observacion->loadMissing('responsable.sector');

        return $this->observacion->responsable;
    }

    private function lineaDeAtraso(): ?string
    {
        $vence = $this->observacion->vence_at;

        if (! $vence) {
            return null;
        }

        // Días **hábiles**, que es la unidad en la que está escrito el plazo
        // (`sector.dias_gestion` + `addWeekdays()`). Contarlo en días corridos
        // diría "hace 4 días" de un vencimiento del viernes leído el martes.
        //
        // El `(int)` trunca el float con signo que devuelve Carbon. Si todavía
        // no venció da negativo y la guarda de abajo lo omite: `abs()`
        // escondería un error de fechas en vez de dejarlo a la vista.
        $dias = (int) $vence->diffInWeekdays(now());
        $asignada = $this->observacion->responsable_asignado_at;

        return sprintf(
            'Venció el %s%s%s.',
            $vence->format('d/m/Y'),
            $dias > 0
                ? sprintf(' — hace %d día%s hábil%s', $dias, $dias === 1 ? '' : 's', $dias === 1 ? '' : 'es')
                : '',
            $asignada ? ', asignada desde el '.$asignada->format('d/m/Y') : '',
        );
    }
}
