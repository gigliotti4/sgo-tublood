<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda archivos adjuntos agrupados en una carpeta propia del registro
 * (`observaciones/0001-26/`, `clientes/1234/`) y con el nombre original
 * saneado, en vez de dejarlos todos planos con el hash que genera `store()`.
 *
 * La carpeta existe para que alguien pueda entrar por SSH o FTP a buscar un
 * documento; una lista de hashes no sirve para eso. El nombre real se guarda
 * igual en `original_name`, que es lo que se muestra y con lo que se descarga.
 *
 * Lo usan Observacion y Cliente: sus tablas de adjuntos tienen exactamente las
 * mismas columnas y ambas exponen la relación `attachments()`.
 */
trait GuardaAdjuntos
{
    /** Carpeta del registro, relativa al disco `local`. Ej: `clientes/1234`. */
    abstract protected function carpetaDeAdjuntos(): string;

    public function guardarAdjunto(UploadedFile $file): Model
    {
        $carpeta = $this->carpetaDeAdjuntos();

        return $this->attachments()->create([
            'path' => $file->storeAs($carpeta, $this->nombreDisponible($carpeta, $file), 'local'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);
    }

    /**
     * Deja el valor apto para nombre de carpeta. Hoy tanto el número de
     * observación (`0001-26`) como el de cliente son inofensivos, pero se
     * sanea igual para que un dato raro del ERP no pueda escaparse del
     * directorio.
     */
    protected function segmentoSeguro(?string $valor, string $fallback): string
    {
        $limpio = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) $valor);

        return $limpio !== '' ? $limpio : $fallback;
    }

    /**
     * Nombre saneado y libre dentro de la carpeta. `storeAs` pisa sin avisar,
     * así que dos archivos que se llaman igual necesitan sufijo.
     */
    private function nombreDisponible(string $carpeta, UploadedFile $file): string
    {
        $extension = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $file->getClientOriginalExtension()));
        $base = Str::limit(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 80, '');

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
}
