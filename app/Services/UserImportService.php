<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Carga masiva de usuarios desde un Excel de tres columnas por posición
 * (A=Nombre, B=Apellido, C=Mail), con una fila de encabezado que se saltea.
 *
 * Upsert por email: las filas nuevas crean el usuario con una contraseña
 * aleatoria y el rol elegido al importar; las filas que matchean un email
 * existente solo actualizan nombre y apellido, sin tocar contraseña ni roles.
 *
 * Las contraseñas generadas se devuelven en texto plano porque es la única vez
 * que se pueden ver: se guardan hasheadas y en dev no hay envío de mails.
 */
class UserImportService
{
    public function import(UploadedFile $file, string $rol): array
    {
        $filas = IOFactory::load($file->getRealPath())
            ->getActiveSheet()
            ->toArray();

        $creados = [];
        $actualizados = 0;
        $advertencias = [];
        $emailsDelArchivo = [];

        foreach (array_slice($filas, 1) as $index => $fila) {
            $numeroFila = $index + 2;

            $nombre = $this->limpiar($fila[0] ?? null);
            $apellido = $this->limpiar($fila[1] ?? null);
            $email = mb_strtolower((string) $this->limpiar($fila[2] ?? null));

            if (! $nombre && ! $apellido && ! $email) {
                continue;
            }

            if (! $nombre || ! $email) {
                $advertencias[] = "Fila {$numeroFila}: falta nombre o mail, se omitió.";

                continue;
            }

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $advertencias[] = "Fila {$numeroFila}: '{$email}' no es un mail válido, se omitió.";

                continue;
            }

            // Un mismo mail repetido en el archivo crearía y pisaría al mismo
            // usuario dos veces; mejor avisar que dejarlo pasar en silencio.
            if (isset($emailsDelArchivo[$email])) {
                $advertencias[] = "Fila {$numeroFila}: el mail '{$email}' ya aparece en la fila {$emailsDelArchivo[$email]}, se omitió.";

                continue;
            }
            $emailsDelArchivo[$email] = $numeroFila;

            $existente = User::where('email', $email)->first();

            if ($existente) {
                $existente->update(['name' => $nombre, 'apellido' => $apellido]);
                $actualizados++;

                continue;
            }

            $password = Str::password(12, symbols: false);

            $user = User::create([
                'name' => $nombre,
                'apellido' => $apellido,
                'email' => $email,
                'password' => $password,
            ]);
            $user->assignRole($rol);

            $creados[] = [
                'nombre' => trim("{$nombre} {$apellido}"),
                'email' => $email,
                'password' => $password,
            ];
        }

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'advertencias' => $advertencias,
        ];
    }

    private function limpiar(mixed $valor): ?string
    {
        $valor = trim((string) $valor);

        return ($valor === '' || $valor === '-') ? null : $valor;
    }
}
