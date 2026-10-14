---
name: probar-pantalla
description: Abrir una pantalla del panel LOCAL (sgo-tublood.test) logueado como un usuario o un rol, y ver qué props de Inertia le llegan, o si da 403/redirect. Para validar backend, permisos y filtros de una pantalla sin abrir el navegador.
argument-hint: "/ruta?query [--rol rol] [--como email] [--prop camino]"
allowed-tools: Bash, Read, Grep
---

# Probar una pantalla del panel sin navegador

Reemplaza el `curl` con cookie-jar + XSRF que se armaba a mano en cada sesión. **Primero esto; el
navegador solo para lo visual** (layout, modo oscuro, un modal) y cuando Lucas lo pida: en las sesiones
la automatización del navegador se cortó más de una vez.

```bash
python -I .claude/skills/probar-pantalla/probar.py /compras
python -I .claude/skills/probar-pantalla/probar.py "/observaciones?estado=abierta&sort=numero" --rol usuario_interno
python -I .claude/skills/probar-pantalla/probar.py /compras --rol usuario_interno,compras --prop depositos
python -I .claude/skills/probar-pantalla/probar.py /compras --out "<scratchpad>/compras.json"
```

Devuelve: status, componente de Inertia, URL final, las props propias de la pantalla con su tamaño
(oculta las compartidas: `auth`, `notificaciones`, `configuracion`…), `flash`/`errors` si traen algo, y
el JSON completo en `--out` para leerlo con Grep/Read. Un 403 sale como "sin permiso"; un redirect dice
a dónde.

## Con qué usuario

- Sin opciones: `admin@admin.com` (super-admin, **saltea todos los Gates** — no sirve para probar
  permisos ni Policies).
- `--rol a,b`: crea o reusa un usuario **descartable** `prueba.<roles>@sgo.test` con exactamente esos
  roles (`syncRoles`). Es la forma de probar un permiso.
- `--como email`: un usuario que ya exista con password `password`. ⚠️ **Nunca** le cambies la
  contraseña a un usuario de la base local para entrar como él: son copias de gente real de Tublood.
- Ojo: super-admin solo **no** cumple `User::esDeCalidad()`. Para la UI de Calidad hace falta
  `--rol garantia_calidad`.

## Límites

- Solo GET, solo local (el script se niega si `PROBAR_BASE` no es local). Para producción está
  `/prod-consulta`.
- Si cambiaste frontend, `npm run build` antes: el vhost lee `public/build/manifest.json`.
- Mostrar props no es lo mismo que haberlo visto en pantalla. Al reportar, decí "verificado por props",
  no "probado en el navegador".
- El login tiene rate limit por IP: muchos logins seguidos dan "Login fallido"; esperar un minuto.
