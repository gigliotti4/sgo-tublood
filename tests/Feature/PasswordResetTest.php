<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\RestablecerPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_la_pantalla_de_pedido_de_reset_es_accesible(): void
    {
        $this->get(route('password.request'))->assertOk();
    }

    public function test_pedir_el_link_a_un_mail_existente_notifica_al_usuario(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'existe@tublood.com']);

        $response = $this->post(route('password.email'), ['email' => 'existe@tublood.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        Notification::assertSentTo($user, RestablecerPasswordNotification::class);
    }

    public function test_pedir_el_link_a_un_mail_inexistente_da_el_mismo_mensaje_y_no_notifica_a_nadie(): void
    {
        Notification::fake();

        $response = $this->post(route('password.email'), ['email' => 'no-existe@tublood.com']);

        $response->assertRedirect();
        $response->assertSessionHas('success', __('passwords.sent'));
        Notification::assertNothingSent();
    }

    public function test_el_reset_con_un_token_valido_cambia_la_contrasena_y_vuelve_al_login(): void
    {
        $user = User::factory()->create(['password' => 'vieja-password']);
        $token = Password::createToken($user);

        $response = $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'password-nueva-123',
            'password_confirmation' => 'password-nueva-123',
        ]);

        $response->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('password-nueva-123', $user->fresh()->password));
    }

    public function test_el_reset_con_un_token_invalido_no_cambia_la_contrasena(): void
    {
        $user = User::factory()->create(['password' => 'vieja-password']);

        $response = $this->post(route('password.update'), [
            'token' => 'token-invalido',
            'email' => $user->email,
            'password' => 'password-nueva-123',
            'password_confirmation' => 'password-nueva-123',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertTrue(Hash::check('vieja-password', $user->fresh()->password));
    }

    public function test_un_usuario_logueado_no_puede_entrar_a_pedir_el_reset(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('password.request'))->assertRedirect(route('dashboard'));
    }
}
