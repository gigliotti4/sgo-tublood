{{--
    Red de última instancia: lo que se cae antes de que ningún controller pueda
    reaccionar (la base caída, un error al bootear la app).

    ⚠️ Deliberadamente autocontenida: sin `@vite`, sin Inertia y sin tocar la
    base. Si la app está lo bastante rota como para llegar acá, el manifest de
    Vite o la tabla `configuraciones` pueden ser justamente lo que falla, y una
    pantalla de error que también revienta deja al usuario con la de Symfony,
    que es lo que esto viene a evitar. Por eso los estilos van embebidos y los
    colores escritos a mano en vez de tomados de las variables de Tailwind.

    Los errores de guardado *previstos* no llegan acá: los controllers de
    observaciones los agarran y los devuelven como mensaje dentro del propio
    formulario, sin perder lo cargado (ver ReglasObservacion::mensajeDeFalla()).
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Algo salió mal</title>
    <link rel="icon" href="/img/iso.png">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #f9fafb;
            color: #22231f;
            font-family: Poppins, ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            line-height: 1.6;
        }
        .caja {
            width: 100%;
            max-width: 30rem;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 1rem;
            padding: 2.5rem 2rem;
            text-align: center;
            box-shadow: 0 1px 3px rgba(16, 24, 40, .1);
        }
        .icono {
            width: 3rem;
            height: 3rem;
            margin: 0 auto 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: .75rem;
            background: #e1f3fe;
            color: #001489;
            font-size: 1.5rem;
            font-weight: 700;
        }
        h1 { margin: 0 0 .5rem; font-size: 1.25rem; font-weight: 600; }
        p { margin: 0 0 1.5rem; font-size: .875rem; color: #667085; }
        a {
            display: inline-block;
            padding: .625rem 1.25rem;
            border-radius: .5rem;
            background: #001489;
            color: #fff;
            font-size: .875rem;
            font-weight: 600;
            text-decoration: none;
        }
        a:hover { background: #001279; }
        .codigo { margin-top: 1.5rem; font-size: .75rem; color: #98a2b3; }
    </style>
</head>
<body>
    <div class="caja">
        <div class="icono">!</div>
        <h1>Algo salió mal</h1>
        <p>
            No pudimos completar la operación por un problema del sistema.
            Ya quedó registrado. Volvé a intentarlo en unos minutos; si seguís
            viendo esta pantalla, avisale al equipo de sistemas.
        </p>
        <a href="/">Volver al inicio</a>
        <p class="codigo">Error 500</p>
    </div>
</body>
</html>
