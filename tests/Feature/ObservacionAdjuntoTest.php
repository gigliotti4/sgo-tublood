<?php

namespace Tests\Feature;

use App\Models\Observacion;
use App\Models\ObservationAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ObservacionAdjuntoTest extends TestCase
{
    use RefreshDatabase;

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function observacion(string $numero = '0001-26'): Observacion
    {
        return Observacion::create([
            'numero' => $numero,
            'anio' => 2026,
            'tipo' => 'falla_producto',
            'estado' => 'pendiente_clasificacion',
            'contacto_nombre' => 'Cliente Test',
            'contacto_email' => 'cliente@example.com',
            'titulo' => 'Título de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);
    }

    private function adjuntoDe(Observacion $observacion, string $nombre = 'informe.pdf'): ObservationAttachment
    {
        Storage::disk('local')->put("observaciones/{$nombre}", 'contenido de prueba');

        return $observacion->attachments()->create([
            'path' => "observaciones/{$nombre}",
            'original_name' => $nombre,
            'mime_type' => 'application/pdf',
            'size' => 19,
        ]);
    }

    public function test_descarga_el_adjunto_con_permiso_de_ver(): void
    {
        $observacion = $this->observacion();
        $adjunto = $this->adjuntoDe($observacion);

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}/archivos/{$adjunto->id}")
            ->assertOk()
            ->assertDownload('informe.pdf');
    }

    public function test_sin_permiso_no_descarga(): void
    {
        $observacion = $this->observacion();
        $adjunto = $this->adjuntoDe($observacion);

        $this->actingAs(User::factory()->create())
            ->get("/observaciones/{$observacion->id}/archivos/{$adjunto->id}")
            ->assertStatus(403);
    }

    public function test_requiere_autenticacion(): void
    {
        $observacion = $this->observacion();
        $adjunto = $this->adjuntoDe($observacion);

        $this->get("/observaciones/{$observacion->id}/archivos/{$adjunto->id}")
            ->assertRedirect('/login');
    }

    /**
     * Renderiza el PDF de verdad: la plantilla es CSS 2.1 aparte de Show.vue,
     * así que un error de Blade ahí no lo agarra ningún otro test. Se chequea
     * la firma %PDF- para confirmar que salió un archivo válido y no un HTML
     * de error con status 200.
     */
    public function test_genera_el_pdf_del_detalle(): void
    {
        $observacion = $this->observacion();
        $this->adjuntoDe($observacion);

        $observacion->productos()->create([
            'producto' => 'Bolsa de sangre 500ml',
            'codigo' => 'BS-500',
            'cantidad_afectada' => 3,
            'tipo_presentacion' => 'unidades',
            'lote' => 'L-2026-04',
            'fecha_vencimiento' => '2027-01-31',
            'numero_remito' => 'R-0001',
            'tipo_comprobante' => 'remito',
        ]);

        $response = $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}/pdf")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_el_pdf_requiere_permiso_de_ver(): void
    {
        $observacion = $this->observacion();

        $this->actingAs(User::factory()->create())
            ->get("/observaciones/{$observacion->id}/pdf")
            ->assertStatus(403);
    }

    public function test_el_detalle_se_ve_solo_con_permiso_de_ver(): void
    {
        $observacion = $this->observacion();

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$observacion->id}")
            ->assertOk();

        $this->actingAs(User::factory()->create())
            ->get("/observaciones/{$observacion->id}")
            ->assertStatus(403);
    }

    /**
     * /observaciones/nuevo y /observaciones/crear se registran después de
     * /observaciones/{observacion}; el whereNumber es lo que evita que la ruta
     * de detalle se las coma tratándolas como un id.
     */
    public function test_las_rutas_nuevo_y_crear_no_las_captura_el_detalle(): void
    {
        $user = $this->userWith('observaciones.view', 'observaciones.edit');

        $this->actingAs($user)->get('/observaciones/nuevo')->assertOk();
        $this->actingAs($user)->get('/observaciones/crear')->assertOk();
    }

    /**
     * scopeBindings(): el adjunto tiene que pertenecer a la observación de la
     * URL. Sin eso, cualquiera con el permiso de ver podría bajarse el adjunto
     * de otra observación cambiando el id a mano.
     */
    public function test_no_descarga_un_adjunto_de_otra_observacion(): void
    {
        $propia = $this->observacion('0001-26');
        $ajena = $this->observacion('0002-26');
        $adjuntoAjeno = $this->adjuntoDe($ajena, 'confidencial.pdf');

        $this->actingAs($this->userWith('observaciones.view'))
            ->get("/observaciones/{$propia->id}/archivos/{$adjuntoAjeno->id}")
            ->assertStatus(404);
    }
}
