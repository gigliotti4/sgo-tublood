<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private function usuario(string $email = 'juan@tublood.com'): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('password'),
        ]);
    }

    private function intentarFallido(string $email): void
    {
        $this->post('/login', ['email' => $email, 'password' => 'incorrecta']);
    }

    public function test_login_correcto_funciona(): void
    {
        $user = $this->usuario();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_bloquea_la_cuenta_tras_cinco_intentos_fallidos(): void
    {
        $user = $this->usuario();

        for ($i = 0; $i < 5; $i++) {
            $this->intentarFallido($user->email);
        }

        // El 6.º ya no evalúa credenciales: corta antes con el aviso de espera.
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'Demasiados intentos',
            session('errors')->first('email')
        );
        $this->assertGuest();
    }

    /**
     * La propiedad que importa acá: los 30 usuarios internos comparten la IP de
     * la oficina, así que el bloqueo de una cuenta no puede arrastrar al resto.
     */
    public function test_bloquear_una_cuenta_no_afecta_a_otro_usuario_de_la_misma_ip(): void
    {
        $victima = $this->usuario('victima@tublood.com');
        $companiera = $this->usuario('companiera@tublood.com');

        for ($i = 0; $i < 6; $i++) {
            $this->intentarFallido($victima->email);
        }

        $this->post('/login', ['email' => $companiera->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($companiera);
    }

    /**
     * Password spraying: una contraseña común probada contra muchos mails. La
     * cubeta por cuenta no lo ve (cada mail estrena contador), por eso existe
     * la cubeta por IP.
     */
    public function test_bloquea_por_ip_al_probar_muchas_cuentas_distintas(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->intentarFallido("desconocido{$i}@ejemplo.com");
        }

        $legitimo = $this->usuario('legitimo@tublood.com');

        $this->post('/login', ['email' => $legitimo->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_un_login_exitoso_limpia_el_contador_de_la_cuenta(): void
    {
        $user = $this->usuario();

        for ($i = 0; $i < 4; $i++) {
            $this->intentarFallido($user->email);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->post('/logout');

        // Si el contador no se hubiera limpiado, estos 4 sumarían 8 y bloquearían.
        for ($i = 0; $i < 4; $i++) {
            $this->intentarFallido($user->email);
        }

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }
}
