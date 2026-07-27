<?php

namespace App\Models;

use App\Observers\ObservacionObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

#[ObservedBy(ObservacionObserver::class)]
class Observacion extends Model
{
    public const ESTADOS = [
        'pendiente_clasificacion' => 'Pendiente de clasificación',
        'clasificada' => 'Clasificada',
        'en_proceso' => 'En proceso',
        'derivada' => 'Derivada',
        'resuelta' => 'Resuelta',
        'cerrada' => 'Cerrada',
        'cancelada' => 'Cancelada',
    ];

    public const ORIGENES = [
        'interna' => 'Interna',
        'externa' => 'Externa',
    ];

    /**
     * Estados en los que la observación sigue en gestión. Es la definición de
     * "abierta" que comparten el Dashboard y el filtro Abierta/Cerrada del
     * listado (cerrada = cualquier otro estado: resuelta, cerrada, cancelada).
     */
    public const ESTADOS_ABIERTOS = ['pendiente_clasificacion', 'clasificada', 'en_proceso', 'derivada'];

    protected $table = 'observations';

    protected $fillable = [
        'numero',
        'anio',
        'tipo',
        'estado',
        'origen',
        'contacto_nombre',
        'contacto_email',
        'contacto_numero_cliente',
        'cliente_id',
        'responsable_id',
        'created_by',
        'responsable_asignado_at',
        'vence_at',
        'alerta_nivel',
        'sector_id',
        'contacto_telefono',
        'titulo',
        'descripcion',
        'institucion',
        'provincia',
        'equipamiento',
        'ejecutivo_cuenta',
        'prioridad',
        'tipo_caso',
        'tecnovigilancia',
        'datos_especificos',
    ];

    protected $casts = [
        'tecnovigilancia' => 'boolean',
        'datos_especificos' => 'array',
        'responsable_asignado_at' => 'datetime',
        'vence_at' => 'datetime',
        'alerta_nivel' => 'integer',
    ];

    public function estaFinalizada(): bool
    {
        return in_array($this->estado, config('incidencias.estados_finales', []), true);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ObservationAttachment::class, 'observation_id');
    }

    public function productos(): HasMany
    {
        return $this->hasMany(ObservationProduct::class, 'observation_id');
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(Cliente::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Quién la cargó desde el panel; null si entró por el portal público. */
    public function creador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Sector de gestión: define los tipos de incidencia disponibles. */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * Guarda un archivo en `observaciones/{numero}/` y registra la fila.
     *
     * Vive acá porque los adjuntos entran desde tres lugares (portal, alta
     * externa y carga desde el detalle) y antes cada uno repetía el bloque.
     *
     * Se conserva el nombre original (saneado) en vez del hash que genera
     * `store()`: la carpeta por número existe para que alguien pueda entrar a
     * buscar un documento, y una lista de hashes no sirve para eso. El nombre
     * real se guarda igual en `original_name`, que es lo que se muestra y con
     * lo que se descarga.
     */
    public function guardarAdjunto(UploadedFile $file): ObservationAttachment
    {
        $carpeta = 'observaciones/'.$this->carpetaDeArchivos();

        return $this->attachments()->create([
            'path' => $file->storeAs($carpeta, $this->nombreDisponible($carpeta, $file), 'local'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    /**
     * El número ya es seguro como nombre de carpeta (`0001-26`), pero se sanea
     * igual para que un formato futuro no pueda escaparse del directorio.
     */
    private function carpetaDeArchivos(): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $this->numero);

        return $limpio !== '' ? $limpio : 'sin-numero-'.$this->id;
    }

    /**
     * Nombre saneado y libre dentro de la carpeta. `storeAs` pisa sin avisar,
     * así que dos archivos que se llaman igual necesitan sufijo.
     */
    private function nombreDisponible(string $carpeta, UploadedFile $file): string
    {
        $original = $file->getClientOriginalName();
        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $file->getClientOriginalExtension()));
        $base = Str::limit(Str::slug(pathinfo($original, PATHINFO_FILENAME)), 80, '');

        if ($base === '') {
            $base = 'archivo';
        }

        $sufijo = $extension !== '' ? '.'.$extension : '';
        $candidato = $base.$sufijo;

        for ($i = 2; Storage::disk('local')->exists($carpeta.'/'.$candidato); $i++) {
            $candidato = "{$base}-{$i}{$sufijo}";
        }

        return $candidato;
    }

    public static function generarNumero(int $anio): string
    {
        $correlativo = static::where('anio', $anio)->count() + 1;

        return sprintf('%04d-%02d', $correlativo, $anio % 100);
    }
}
