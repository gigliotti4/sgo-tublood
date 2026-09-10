<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            'busy_timeout' => null,
            'journal_mode' => null,
            'synchronous' => null,
            'transaction_mode' => 'DEFERRED',
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

        /*
         * RP Sistemas (ERP) — SQL Server, SOLO LECTURA.
         *
         * El ERP no expone API para proveedores ni ventas: solo habilita vistas
         * SQL (`powerbi_proveedores_vista`, `powerbi_ventas_vista`), las mismas
         * que consumen desde Power BI.
         *
         * ⚠️ Variables propias `ERP_DB_*` y no las `DB_*`: el bloque `sqlsrv` de
         * arriba es scaffold de Laravel y comparte las variables de la base
         * principal, así que apuntaría al lugar equivocado.
         *
         * ⚠️ Nunca correr migraciones ni seeders contra esta conexión, ni
         * incluirla en los tests: es de un sistema de terceros y solo tenemos
         * permiso de lectura.
         *
         * El host lleva el puerto pegado con coma (`149.78.140.72,55333`), que
         * es la sintaxis de SQL Server; con el puerto explícito el nombre de
         * instancia se ignora. `trust_server_certificate` hace falta porque el
         * certificado del servidor no es de una CA conocida.
         *
         * Requiere la extensión `pdo_sqlsrv` + Microsoft ODBC Driver 18, que
         * NO vienen instalados por defecto (ni en Laragon ni en hosting
         * compartido).
         */
        'erp' => [
            'driver' => 'sqlsrv',
            'host' => env('ERP_DB_HOST'),
            'port' => env('ERP_DB_PORT'),
            'database' => env('ERP_DB_DATABASE'),
            'username' => env('ERP_DB_USERNAME'),
            'password' => env('ERP_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('ERP_DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('ERP_DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ],

        /*
         * RP Sistemas (ERP) — segundo login, para el módulo Compras.
         *
         * ⚠️ Es el MISMO servidor y la MISMA base que `erp` de arriba: lo único
         * distinto son las credenciales. Hay dos conexiones porque los dos
         * usuarios que nos dio RP tienen permisos COMPLEMENTARIOS, no
         * solapados — medido el 8/9/2026:
         *
         *   objeto                            api_lucas   powerbi_tublood
         *   ARTICULOS                            ✗              ✓
         *   COMPRO_PARTIDAS                      ✓              ✗
         *   powerbi_ordenescompra_pend_vista     ✗              ✓
         *   powerbi_pedidos_vista                ✗              ✓
         *   powerbi_ventas_vista                 ✓              ✓
         *   powerbi_proveedores_vista            ✓              ✓
         *
         * Por eso NO se puede unificar moviendo las credenciales: pisar
         * `ERP_DB_*` con este usuario rompe `partidas:sync` y
         * `venta-partidas:sync`, que leen el kardex `COMPRO_PARTIDAS`.
         *
         * Pendiente con RP: pedir un único usuario con los dos conjuntos de
         * grants para poder colapsar las dos conexiones en una.
         */
        'erp_compras' => [
            'driver' => 'sqlsrv',
            'host' => env('ERP_COMPRAS_DB_HOST'),
            'port' => env('ERP_COMPRAS_DB_PORT'),
            'database' => env('ERP_COMPRAS_DB_DATABASE'),
            'username' => env('ERP_COMPRAS_DB_USERNAME'),
            'password' => env('ERP_COMPRAS_DB_PASSWORD'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'encrypt' => env('ERP_COMPRAS_DB_ENCRYPT', 'yes'),
            'trust_server_certificate' => env('ERP_COMPRAS_DB_TRUST_SERVER_CERTIFICATE', 'true'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
