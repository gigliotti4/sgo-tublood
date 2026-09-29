<?php

namespace App\Notifications;

use App\Models\NoConformidad;
use Illuminate\Support\Str;

/**
 * Te asignaron una acción dentro de un desvío — del plan (sección 5) o de la
 * contención inmediata (sección 3).
 *
 * ⚠️ Es la **misma clase para las dos secciones** y recibe la descripción como
 * texto, no el modelo. Dos clases casi idénticas que solo cambian de qué tabla
 * sale el renglón se habrían desincronizado, y para quien lo recibe la pregunta
 * es la misma: "¿qué tengo que hacer y en qué caso?".
 */
class AccionAsignadaNotification extends NoConformidadNotification
{
    public function __construct(NoConformidad $noConformidad, public string $descripcion)
    {
        parent::__construct($noConformidad);
    }

    protected function tipo(): string
    {
        return 'nc_accion_asignada';
    }

    protected function asunto(): string
    {
        $numero = $this->noConformidad->numero;

        return $numero
            ? "Tenés una acción a cargo en la No Conformidad {$numero}"
            : 'Tenés una acción a cargo en una No Conformidad';
    }

    protected function mensaje(): string
    {
        // Se recorta porque el texto es también la primera línea del mail y el
        // cuerpo de la campana: una acción de 2.000 caracteres los rompe. El
        // texto completo está en la ficha, a un clic del botón.
        return 'Quedaste a cargo de: '.Str::limit(trim($this->descripcion), 180);
    }

    /** @return array<int, string> */
    protected function detalles(object $notifiable): array
    {
        return ['Podés registrar el avance y adjuntar las evidencias vos mismo, desde la ficha del desvío.'];
    }
}
