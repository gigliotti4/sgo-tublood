<?php

namespace Tests\Feature;

use App\Models\ComprasArticulo;
use App\Models\User;
use App\Models\Venta;
use App\Notifications\ComprasCoberturaNotification;
use App\Services\Compras\AlertaCobertura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El mail mensual de Compras con lo que no cubre stock.
 *
 * ⚠️ La fecha va fija: la alerta excluye el mes en curso, así que el resultado
 * depende de qué día se corra el test.
 */
class AlertaCoberturaComprasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-01 08:00:00');
        Storage::fake('local');
        Notification::fake();
    }

    private function articulo(string $codigo, array $attrs = []): void
    {
        ComprasArticulo::create($attrs + [
            'codigo' => $codigo, 'descripcion' => "Producto {$codigo}", 'cant_stock' => 0,
            'agru_1' => 'DIS', 'sin_stock' => false, 'activo' => true, 'synced_at' => now(),
        ]);
    }

    private function venta(string $articulo, string $fecha, float $cantidad): void
    {
        Venta::create([
            'compro_nro' => 'FEA-00000001', 'cod_comprobante' => 'FEA', 'fecha' => $fecha,
            'articulo' => $articulo, 'cantidad' => $cantidad, 'sub_total' => $cantidad * 10, 'synced_at' => now(),
        ]);
    }

    /** Un producto que vende 30 por mes en ago-sep-oct y no tiene stock. */
    private function productoQueNoCubre(string $codigo = 'RE-1'): void
    {
        $this->articulo($codigo);
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $fecha) {
            $this->venta($codigo, $fecha, 30);
        }
    }

    /** El destinatario que es usuario del sistema (en la config de producción, Emanuel Durán). */
    private function eduran(): User
    {
        return User::factory()->create(['email' => 'eduran@tublood.com', 'name' => 'EMANUEL']);
    }

    public function test_el_periodo_son_los_ultimos_meses_cerrados(): void
    {
        $alerta = app(AlertaCobertura::class);

        // El 1/11 ya hay alguna venta de noviembre: ese mes queda afuera.
        $this->assertSame([1, 3], $alerta->periodo(['2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], 3));
        // Si el historial termina en un mes cerrado, se usa entero.
        $this->assertSame([2, 4], $alerta->periodo(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10'], 3));
    }

    /**
     * Una venta del 1/11 no puede bajar el promedio: con el mes en curso adentro
     * serían (30+30+5)/3 y el producto de abajo cubriría.
     */
    public function test_le_llega_a_las_direcciones_de_la_config_con_el_detalle_en_excel(): void
    {
        $this->productoQueNoCubre();
        $this->venta('RE-1', '2026-11-01', 5);
        $eduran = $this->eduran();

        $this->artisan('compras:alerta-cobertura')->assertSuccessful();

        // La dirección que es de un usuario le llega a él (el saludo lleva su nombre)…
        Notification::assertSentTo($eduran, ComprasCoberturaNotification::class, function ($n) {
            Storage::disk('local')->assertExists($n->excel);

            return $n->total === 1 && $n->periodo === 'Ago 26 – Oct 26' && $n->top[0]['comprar'] === 30.0;
        });
        // …y la casilla compartida, que no es usuario, va suelta.
        Notification::assertSentOnDemand(
            ComprasCoberturaNotification::class,
            fn ($n, $canales, AnonymousNotifiable $destino) => $destino->routes['mail'] === 'compras@tublood.com',
        );
        Notification::assertSentTimes(ComprasCoberturaNotification::class, 2);
    }

    /**
     * El permiso `compras.view` lo tenían 22 usuarios en producción (viene con
     * roles generales): no es la lista de a quién le llega el mail.
     */
    public function test_quien_ve_compras_pero_no_esta_en_la_lista_no_lo_recibe(): void
    {
        $this->productoQueNoCubre();
        Permission::firstOrCreate(['name' => 'compras.view', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'ventas', 'guard_name' => 'web'])->givePermissionTo('compras.view');
        $deVentas = tap(User::factory()->create())->assignRole('ventas');

        $this->artisan('compras:alerta-cobertura')->assertSuccessful();

        Notification::assertNotSentTo($deVentas, ComprasCoberturaNotification::class);
    }

    public function test_sin_destinatarios_configurados_no_manda_nada(): void
    {
        $this->productoQueNoCubre();
        config(['compras.alerta.destinatarios' => []]);

        $this->artisan('compras:alerta-cobertura')->assertSuccessful();

        Notification::assertNothingSent();
    }

    /** Un mail mensual que dice "0" se aprende a ignorar. */
    public function test_si_no_hay_nada_para_comprar_no_manda_nada(): void
    {
        $this->articulo('RE-1', ['cant_stock' => 1000]);
        foreach (['2026-08-10', '2026-09-10', '2026-10-10'] as $fecha) {
            $this->venta('RE-1', $fecha, 30);
        }
        $this->eduran();

        $this->artisan('compras:alerta-cobertura')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_solo_entran_los_productos_de_la_categoria_y_activos_de_la_config(): void
    {
        $this->productoQueNoCubre('RE-DIS');
        $this->productoQueNoCubre('RE-IMP');
        ComprasArticulo::where('codigo', 'RE-IMP')->update(['agru_1' => 'IMP']);
        $this->productoQueNoCubre('RE-INACTIVO');
        ComprasArticulo::where('codigo', 'RE-INACTIVO')->update(['activo' => false]);
        $eduran = $this->eduran();

        $this->artisan('compras:alerta-cobertura')->assertSuccessful();

        Notification::assertSentTo($eduran, ComprasCoberturaNotification::class, fn ($n) => $n->total === 1);
    }

    public function test_ver_no_manda_nada_y_a_manda_solo_a_esa_direccion(): void
    {
        $this->productoQueNoCubre();
        $this->eduran();

        $this->artisan('compras:alerta-cobertura --ver')->assertSuccessful();
        Notification::assertNothingSent();

        $this->artisan('compras:alerta-cobertura --a=prueba@tublood.com')->assertSuccessful();
        Notification::assertSentOnDemand(
            ComprasCoberturaNotification::class,
            fn ($n, $canales, AnonymousNotifiable $destino) => $destino->routes['mail'] === 'prueba@tublood.com',
        );
        Notification::assertSentTimes(ComprasCoberturaNotification::class, 1);
    }

    public function test_el_mail_lleva_el_excel_adjunto_y_el_criterio_desde_la_config(): void
    {
        $this->productoQueNoCubre();
        $eduran = $this->eduran();

        $this->artisan('compras:alerta-cobertura')->assertSuccessful();

        Notification::assertSentTo($eduran, ComprasCoberturaNotification::class, function ($n, $canales, $notifiable) {
            $mail = $n->toMail($notifiable);

            return count($mail->attachments) === 1
                && $mail->greeting === 'Hola EMANUEL,'
                && str_contains($n->criterio, 'DISTRIBUCIÓN')
                && str_contains($mail->subject, '1 producto para comprar');
        });
    }
}
