<?php

namespace Tests\Feature;

use App\Jobs\SyncClientesJob;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClienteControllerTest extends TestCase
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

    public function test_index_requiere_permiso_clientes_view(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/clientes')->assertStatus(403);
    }

    public function test_index_accesible_con_permiso(): void
    {
        $user = $this->userWith('clientes.view');
        $this->actingAs($user)->get('/clientes')->assertStatus(200);
    }

    public function test_busqueda_filtra_por_razon_social(): void
    {
        Cliente::create([
            'numero' => '1', 'razon_social' => 'Empresa Alpha SA',
            'cuit' => '30-1234-0',
        ]);
        Cliente::create([
            'numero' => '2', 'razon_social' => 'Distribuidora Beta SRL',
            'cuit' => '30-9999-0',
        ]);

        $user = $this->userWith('clientes.view');
        $response = $this->actingAs($user)->get('/clientes?search=Alpha');

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Admin/Clientes/Index')
            ->has('clientes.data', 1)
            ->where('clientes.data.0.razon_social', 'Empresa Alpha SA')
        );
    }

    public function test_sync_requiere_permiso_clientes_sync(): void
    {
        $user = $this->userWith('clientes.view');
        $this->actingAs($user)->post('/clientes/sync')->assertStatus(403);
    }

    public function test_sync_despacha_job_y_redirige(): void
    {
        Queue::fake();

        $user = $this->userWith('clientes.view', 'clientes.sync');
        $this->actingAs($user)
            ->post('/clientes/sync')
            ->assertRedirect('/clientes');

        Queue::assertPushed(SyncClientesJob::class);
    }

    public function test_edit_requiere_permiso_clientes_edit(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $user = $this->userWith('clientes.view');

        $this->actingAs($user)->get("/clientes/{$cliente->id}/edit")->assertStatus(403);
    }

    public function test_update_setea_fecha_vencimiento(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $user = $this->userWith('clientes.view', 'clientes.edit');

        $this->actingAs($user)
            ->put("/clientes/{$cliente->id}", ['fecha_vencimiento' => '2027-01-15'])
            ->assertRedirect(route('clientes.edit', $cliente));

        $this->assertSame('2027-01-15', $cliente->fresh()->fecha_vencimiento->toDateString());
    }

    public function test_subir_archivo_crea_adjunto_y_lo_guarda_en_disco(): void
    {
        Storage::fake('local');

        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $user = $this->userWith('clientes.view', 'clientes.edit');

        $this->actingAs($user)
            ->post("/clientes/{$cliente->id}/archivos", [
                'archivos' => [UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf')],
            ])
            ->assertRedirect(route('clientes.edit', $cliente));

        $this->assertCount(1, $cliente->fresh()->attachments);
        Storage::disk('local')->assertExists($cliente->attachments->first()->path);
    }

    public function test_descargar_archivo_requiere_permiso_clientes_view(): void
    {
        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $archivo = $cliente->attachments()->create([
            'path' => 'clientes/no-existe.pdf',
            'original_name' => 'contrato.pdf',
            'mime_type' => 'application/pdf',
            'size' => 10,
        ]);

        $user = User::factory()->create();

        $this->actingAs($user)
            ->get("/clientes/{$cliente->id}/archivos/{$archivo->id}")
            ->assertStatus(403);
    }

    public function test_borrar_archivo_elimina_fila_y_archivo_fisico(): void
    {
        Storage::fake('local');

        $cliente = Cliente::create(['numero' => '1', 'razon_social' => 'Empresa Test SA']);
        $path = UploadedFile::fake()->create('contrato.pdf', 100)->store('clientes', 'local');
        $archivo = $cliente->attachments()->create([
            'path' => $path,
            'original_name' => 'contrato.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
        ]);

        $user = $this->userWith('clientes.view', 'clientes.edit');

        $this->actingAs($user)
            ->delete("/clientes/{$cliente->id}/archivos/{$archivo->id}")
            ->assertRedirect(route('clientes.edit', $cliente));

        $this->assertDatabaseMissing('cliente_attachments', ['id' => $archivo->id]);
        Storage::disk('local')->assertMissing($path);
    }
}
