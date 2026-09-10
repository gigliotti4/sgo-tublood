<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renglones de pedido de cliente con saldo pendiente (powerbi_pedidos_vista).
 *
 * De acá sale el "Reservado" del tablero. Se guardan TODOS los renglones con
 * cant_pend > 0 (35.635 de 122.752), no solo los que hoy cuentan como
 * reservados: la regla de reserva está sin conciliar con el ERP y tiene que
 * poder ajustarse desde config/compras.php sin re-sincronizar.
 *
 * Refresh completo en cada sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compras_pedidos_pendientes', function (Blueprint $table) {
            $table->id();

            $table->string('comprobante', 30)->nullable();
            $table->integer('compro_nro')->nullable();
            $table->integer('renglon')->nullable();
            $table->date('fecha')->nullable();

            $table->integer('cliente')->nullable();
            $table->string('razon_social')->nullable();

            $table->string('articulo', 30)->index();

            // Envases pendientes de entrega.
            $table->decimal('cant_pend', 16, 4)->default(0);

            $table->char('reser', 1)->nullable();
            $table->string('deposito_reserva', 30)->nullable();
            $table->string('estado', 50)->nullable()->index();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // El filtro de reserva pega sobre estas dos.
            $table->index(['reser', 'deposito_reserva'], 'compras_pedidos_reserva_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compras_pedidos_pendientes');
    }
};
