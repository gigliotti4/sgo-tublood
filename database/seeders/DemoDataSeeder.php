<?php

namespace Database\Seeders;

use App\Models\Cliente;
use App\Models\Observacion;
use App\Models\ObservationProduct;
use App\Models\User;
use Illuminate\Database\Seeder;

class DemoDataSeeder extends Seeder
{
    private const PROVINCIAS = [
        'Buenos Aires', 'Catamarca', 'Chaco', 'Chubut',
        'Ciudad Autónoma de Buenos Aires', 'Córdoba', 'Corrientes', 'Entre Ríos',
        'Formosa', 'Jujuy', 'La Pampa', 'La Rioja', 'Mendoza', 'Misiones',
        'Neuquén', 'Río Negro', 'Salta', 'San Juan', 'San Luis', 'Santa Cruz',
        'Santa Fe', 'Santiago del Estero', 'Tierra del Fuego', 'Tucumán',
    ];

    public function run(): void
    {
        $clientes = Cliente::inRandomOrder()->get();
        abort_if($clientes->isEmpty(), 500, 'No hay clientes registrados para vincular. Corré la sincronización primero.');

        $usuarios = collect(range(1, 10))->map(fn ($i) => tap(
            User::factory()->create(['name' => "user-{$i}", 'email' => "user{$i}@test.com"]),
            fn ($u) => $u->assignRole('usuario_interno')
        ));

        $distribucion = [
            ...array_fill(0, 3, ['tipo' => 'falla_producto', 'productos' => 2]),
            ...array_fill(0, 2, ['tipo' => 'falla_producto', 'productos' => 3]),
            ...array_fill(0, 2, ['tipo' => 'falla_producto', 'productos' => 4]),
            ...array_fill(0, 3, ['tipo' => 'disconformidad_servicio', 'productos' => 0]),
        ];

        foreach ($distribucion as $spec) {
            $anio = (int) now()->format('Y');
            $cliente = $clientes->random();

            $observacion = Observacion::create([
                'numero' => Observacion::generarNumero($anio),
                'anio' => $anio,
                'tipo' => $spec['tipo'],
                'estado' => fake()->randomElement(array_keys(Observacion::ESTADOS)),
                'origen' => fake()->randomElement(array_keys(Observacion::ORIGENES)),
                'contacto_nombre' => fake()->name(),
                'contacto_email' => fake()->unique()->safeEmail(),
                'contacto_telefono' => fake()->phoneNumber(),
                'contacto_numero_cliente' => $cliente->numero,
                'cliente_id' => $cliente->id,
                'titulo' => fake()->sentence(4),
                'descripcion' => fake()->paragraph(),
                'responsable_id' => $usuarios->random()->id,
                ...($spec['tipo'] === 'falla_producto' ? [
                    'institucion' => fake()->company(),
                    'provincia' => fake()->randomElement(self::PROVINCIAS),
                    'equipamiento' => fake()->boolean() ? fake()->word() : null,
                    'ejecutivo_cuenta' => fake()->name(),
                ] : []),
            ]);

            for ($p = 0; $p < $spec['productos']; $p++) {
                $observacion->productos()->create([
                    'producto' => fake()->words(2, true),
                    'cantidad_afectada' => fake()->numberBetween(1, 20),
                    'tipo_presentacion' => fake()->randomElement(array_keys(ObservationProduct::PRESENTACIONES)),
                    'lote' => strtoupper(fake()->bothify('L-####-??')),
                    'fecha_vencimiento' => fake()->dateTimeBetween('now', '+2 years'),
                    'numero_remito' => strtoupper(fake()->bothify('R-####')),
                    'tipo_comprobante' => fake()->randomElement(['factura', 'remito']),
                ]);
            }
        }
    }
}
