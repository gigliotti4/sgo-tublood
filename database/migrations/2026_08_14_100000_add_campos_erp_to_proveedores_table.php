<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Los proveedores pasan a venir del ERP.
 *
 * Hasta ahora el padrón entraba solo por Excel, porque la API de RP Sistemas no
 * expone proveedores. Ahora habilitaron la vista `powerbi_proveedores_vista`,
 * que trae bastante más que la planilla, así que se suman esas columnas.
 *
 * ⚠️ Cambia el dueño de `cuit`, `telefono`, `mail` y `localidad`: eran campos
 * del panel (se cargaban a mano) y pasan a sincronizarse desde el ERP. Se
 * verificó que no había ni una fila con esos campos cargados antes de decidirlo.
 * `observaciones` sigue siendo del panel y queda fuera del upsert del sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->string('nombre_fantasia')->nullable()->after('razon_social');
            $table->string('provincia')->nullable()->after('localidad');
            $table->string('codigo_postal')->nullable()->after('provincia');
            $table->string('contacto')->nullable()->after('codigo_postal');
            $table->string('celular')->nullable()->after('telefono');

            // Estado del proveedor en el ERP. Los valores observados son A, S e
            // I; falta que RP Sistemas confirme cuál significa "dado de baja".
            $table->string('estado', 1)->nullable()->after('observaciones');

            // FECHA_MODI del ERP: por ahora es informativo, pero permite pasar a
            // una sincronización incremental si el padrón crece.
            $table->dateTime('modificado_en')->nullable()->after('estado');
            $table->timestamp('synced_at')->nullable()->after('modificado_en');
        });
    }

    public function down(): void
    {
        Schema::table('proveedores', function (Blueprint $table) {
            $table->dropColumn([
                'nombre_fantasia', 'provincia', 'codigo_postal', 'contacto',
                'celular', 'estado', 'modificado_en', 'synced_at',
            ]);
        });
    }
};
