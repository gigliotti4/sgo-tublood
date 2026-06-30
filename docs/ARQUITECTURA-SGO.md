# SGO Tublood — Documento de arquitectura y planificación técnica

> Fuentes: `LEEME-PRIMERO.md`, `Notas-Finales-Prototipo.md`, `Especificacion-Tecnica-SGO-v3.docx` (capítulos referenciados), análisis del código base existente.  
> Fecha de relevamiento: 2026-06-26

---

## 1. Resumen del proyecto

**Sistema de Gestión de Observaciones, No Conformidades y Postventa Virtual** para **Tublood SA** (empresa de dispositivos médicos, Argentina).

Reemplaza al SGO actual. Cubre el ciclo completo de un reclamo externo o interno: recepción → clasificación → derivación → resolución → cierre → encuesta → CAPA si corresponde.

**Empresa:** Tublood SA  
**Usuarios internos:** ~35  
**Cumplimiento regulatorio:** ANMAT (Argentina) — trazabilidad 10 años  
**ERP:** desarrollo propio con API JSON documentada  
**Referente del proyecto:** Iara (administradora del sistema)  
**Referente de Calidad/Postventa:** Nadia Pavia-ra  

---

## 2. Stack tecnológico

### Backend
- **PHP 8.3 / Laravel 13**
- **Spatie Laravel Permission** — roles y permisos granulares
- **Queue worker** — notificaciones por email, job de SLA cada 30 minutos
- **SQLite** (desarrollo local) → **MySQL** (producción)

### Frontend
- **Vue 3** con `<script setup>` y TypeScript
- **Inertia.js** — SPA-like sin API REST separada
- **Tailwind CSS v4**
- **Ziggy** — rutas Laravel disponibles en el frontend

### Infraestructura (a definir por Tublood)
- Hosting: a definir
- Email: SMTP corporativo o SendGrid/AWS SES

---

## 3. Estado actual del código

| Módulo | Estado |
|---|---|
| Autenticación (login/logout) | ✅ Completo |
| CRUD de Usuarios | ✅ Completo |
| CRUD de Roles | ✅ Completo |
| Dashboard (shell con componentes Vue) | ✅ Shell listo |
| Migraciones base (users, cache, jobs, permissions) | ✅ Listas |
| Portal externo | ❌ Pendiente |
| Observaciones (flujo central) | ❌ Pendiente |
| Clasificación (Garantía de Calidad) | ❌ Pendiente |
| Derivación entre sectores | ❌ Pendiente |
| No Conformidades (NC) | ❌ Pendiente |
| CAPA | ❌ Pendiente |
| SLAs y worker | ❌ Pendiente |
| Notificaciones (email + in-app) | ❌ Pendiente |
| Plantillas de notificación editables | ❌ Pendiente |
| Encuesta de satisfacción | ❌ Pendiente |
| Integración ERP | ❌ Pendiente |
| Reportes y exportaciones CSV | ❌ Pendiente |
| Tecnovigilancia (ANMAT) | ❌ Pendiente |
| Gestión de sectores (admin) | ❌ Pendiente |
| Configuración de SLAs (admin) | ❌ Pendiente |
| Formularios dinámicos por sector | ❌ Pendiente |

---

## 4. Roles y permisos

### Los 5 roles del sistema

| Rol | Descripción |
|---|---|
| `cliente_externo` | Accede solo al portal público sin login. Carga reclamos. |
| `usuario_interno` | Opera las observaciones de su sector. |
| `solo_lectura` | Ve todo, exporta reportes. No modifica nada. |
| `administrador` | Gestión completa del sistema, usuarios, config. NO clasifica. |
| `garantia_calidad` | Sector especial con atribución exclusiva de clasificar. |

### Matriz de permisos consolidada

| Acción | Cliente externo | Usuario interno | Solo lectura | Admin | Garantía de Calidad |
|---|:---:|:---:|:---:|:---:|:---:|
| Cargar observación desde portal | ✓ | — | — | — | — |
| Ver observaciones propias / del sector | — | ✓ | ✓ | ✓ | ✓ |
| Ver TODAS las observaciones | — | — | ✓ | ✓ | ✓ |
| Crear observación interna | — | ✓ | — | ✓ | ✓ |
| Comentar / Adjuntar | — | ✓ * | — | ✓ | ✓ |
| Cambiar estado | — | ✓ * | — | ✓ | ✓ |
| Derivar a otro sector | — | ✓ | — | ✓ | ✓ |
| **Clasificar** (prioridad, tipo, tecnovigilancia) | — | — | — | **—** | **✓** |
| Crear NC | — | ✓ | — | ✓ | ✓ |
| Generar CAPA | — | ✓ ** | — | ✓ | ✓ |
| Gestionar usuarios | — | — | — | ✓ | — |
| Gestionar sectores | — | — | — | ✓ | — |
| Configurar SLAs | — | — | — | ✓ | — |
| Editar plantillas de notificación | — | — | — | ✓ | — |
| Backup / Restore | — | — | — | ✓ | — |
| Eliminar observaciones / NC | — | — | — | ✓ | — |
| Exportar reportes CSV | — | ✓ | ✓ | ✓ | ✓ |

\* Solo donde es responsable principal o está en la lista de notificados.  
\*\* Solo vinculado a una NC existente.

### Regla especial: Clasificación

- Solo usuarios con `sector = garantia_calidad` pueden clasificar.
- Los administradores NO clasifican por defecto.
- **Mecanismo de emergencia:** Un admin puede asignarse temporalmente al sector Calidad para clasificar un caso urgente. No debe ser el flujo normal.
- La validación es **server-side** (Policy de Laravel). Ocultarlo en el frontend no es suficiente.

---

## 5. Sectores

| Sector | ID sugerido |
|---|---|
| Facturación | `facturacion` |
| Logística | `logistica` |
| Depósito | `deposito` |
| Comercial | `comercial` |
| COMEX | `comex` |
| Asuntos Regulatorios | `asuntos_regulatorios` |
| Garantía de Calidad | `garantia_calidad` |
| Dirección Técnica | `direccion_tecnica` |

Los sectores son **gestionables por el administrador** desde la pantalla de configuración (agregar, renombrar, desactivar).

---

## 6. Modelo de datos (tablas)

> Las columnas detalladas deben completarse con el capítulo correspondiente del documento técnico v3.

### Tablas principales

| Tabla | Descripción |
|---|---|
| `users` | Usuarios del sistema (ya existe) |
| `sectors` | Sectores de la empresa (dinámicos, gestionables por admin) |
| `observations` | Tabla central — observaciones / reclamos |
| `observation_history` | Historial inmutable de todas las acciones |
| `observation_comments` | Comentarios internos en una observación |
| `observation_attachments` | Archivos adjuntos |
| `observation_notified_users` | Usuarios en copia de una observación (pivot) |
| `non_conformities` | No conformidades vinculadas a observaciones |
| `capas` | Acciones correctivas/preventivas vinculadas a NC |
| `sla_configs` | Configuración de SLAs por tipo de caso / prioridad |
| `notification_templates` | Plantillas editables de notificaciones por email/in-app |
| `notifications` | Notificaciones in-app de cada usuario |
| `satisfaction_surveys` | Encuestas de satisfacción con token único |
| `erp_logs` | Registro de llamadas a la API del ERP |

### Enums / catálogos clave

**Estados de una observación:**
`pendiente_clasificacion` → `clasificada` → `en_proceso` → `derivada` → `resuelta` → `cerrada`
(también: `cancelada`)

**Prioridades** (asignadas por Calidad):
`critica` | `alta` | `media` | `baja`

**Tipos de caso** (asignados por Calidad):
A definir con el documento técnico v3. Incluye al menos: `falla_producto`, `disconformidad_servicio`.

**Tecnovigilancia:**
Checkbox booleano. Cuando es `true`, la prioridad se fuerza a `critica` automáticamente.

**Numeración de observaciones:**
Formato `N-AA` con reset anual (ej: `0001-26`, `0002-26`). Gestionado por el sistema, no editable.

---

## 7. Formularios por tipo de observación

### Falla de Producto (campos consolidados v3)

**Visibles en el portal externo (cliente llena):**
| Campo | Tipo | Oblig. |
|---|---|:---:|
| Título breve | texto | ✓ |
| Descripción detallada | texto largo | ✓ |
| Cantidad afectada ("Incidencia" en terminología médica) | número | ✓ |
| Lote | texto | ✓ |
| Fecha de vencimiento | fecha | ✓ |
| Número de remito | texto | ✓ |
| Tipo de comprobante | radio: Factura / Remito | ✓ |
| Institución | texto | ✓ |
| Provincia | lista (24 provincias argentinas) | ✓ |
| Teléfono de contacto | texto | — |
| Producto | texto | ✓ |
| Equipamiento utilizado | texto | — |
| Ejecutivo de cuenta a cargo | lookup de usuario (prepoblar desde ERP si existe) | — |

**Solo internos — asignados por Garantía de Calidad:**
| Campo | Tipo | Oblig. |
|---|---|:---:|
| Prioridad | lista | ✓ |
| Tipo de caso | lista | ✓ |
| Tecnovigilancia | checkbox | — |

### Disconformidad de Servicio
> Campos a relevar del documento técnico v3 (capítulos 13-14).

---

## 8. Flujos de negocio

### 8.1. Observación externa (portal público)

```
Cliente llena el formulario del portal (sin login)
  → Se crea observación con estado "pendiente_clasificacion"
  → Se asigna automáticamente al sector Garantía de Calidad
  → Responsable principal: usuario activo de GC (round-robin / configurable)
    · Si no hay usuarios activos en GC → "sin asignar" + notificación a admins
  → Notificación email + in-app a TODOS los usuarios activos de GC
  → El cliente recibe pantalla de confirmación con número de seguimiento (N-AA)
```

### 8.2. Clasificación (exclusiva de Garantía de Calidad)

```
Usuario de GC abre la observación (estado: pendiente_clasificacion)
  → Completa: prioridad + tipo de caso + tecnovigilancia (si aplica)
    · Si marca tecnovigilancia → prioridad forzada a "critica"
  → Estado pasa a "clasificada"
  → Se registra en historial
  → Notificación al responsable y notificados
```

### 8.3. Derivación a otro sector

```
Usuario (generalmente GC después de clasificar) deriva a sector destino
  → Sector asignado cambia al sector destino
  → Responsable principal anterior → LIBERADO (queda "sin asignar - [sector destino]")
  → Opcionalmente: se puede asignar a un usuario específico del sector destino
  → Notificaciones in-app limpias del responsable anterior (evitar ruido)
  → Notificación a TODOS los usuarios activos del sector destino:
    · "📌 Te asignaron: [N°]" si se asignó a uno específico
    · "📨 Nueva en [Sector]: [N°]" si quedó libre
  → Se registra en historial
```

### 8.4. Cierre de observación

```
Usuario con permiso cierra la observación
  → Estado → "cerrada"
  → Notificación al responsable y creador
  → Si es observación EXTERNA:
    → Se envía email al cliente con la resolución
    → Se envía link a encuesta de satisfacción (token único, válido 30 días)
    → Si en 7 días no respondió → único recordatorio automático
```

### 8.5. SLA Worker (job recurrente)

```
Cada 30 minutos:
  → Revisa observaciones abiertas contra sus SLAs configurados
  → Si SLA vencido → notificación al responsable principal
  → Si el escalamiento procede → notificación al superior del responsable
  → Registra en historial
```

### 8.6. Auto-corrección al inicializar

```
Al inicializarse el sistema:
  → Detecta observaciones mal asignadas (ej: asignadas al admin por error)
  → Las reasigna correctamente
  → Registra en historial: "Sistema (auto-fix): Reasignación automática"
```

---

## 9. Sistema de notificaciones

### Eventos y canales

| Evento | Email | In-app | Destinatarios |
|---|:---:|:---:|---|
| Carga de observación externa | ✓ | ✓ | Todos los usuarios activos de GC |
| Carga de observación interna | ✓ | ✓ | Responsable principal + notificados en copia |
| Clasificación de observación | ✓ | ✓ | Responsable + notificados |
| Derivación a otro sector | ✓ | ✓ | Todos los usuarios activos del sector destino |
| Cambio de responsable principal | ✓ | ✓ | Nuevo responsable (email+in-app), anterior (solo in-app) |
| Agregar usuario a notificados | ✓ | ✓ | El nuevo usuario agregado |
| Cierre de observación (interna) | ✓ | ✓ | Responsable + creador |
| Cierre de observación (externa) | ✓ | ✓ | Responsable + creador + **email al cliente con encuesta** |
| SLA vencido | ✓ | ✓ | Responsable principal |
| Escalamiento por SLA | ✓ | ✓ | Superior del responsable |
| Creación de NC vinculada | — | ✓ | Responsable de la observación origen |
| Disparo de CAPA | ✓ | ✓ | Responsable de la NC + área de Calidad + Dirección Técnica (si aplica) |

### Plantillas editables

Todas las notificaciones por email usan plantillas editables desde el panel admin.

**Variables disponibles en plantillas:**
`{{numero}}`, `{{titulo}}`, `{{prioridad}}`, `{{tipo_caso}}`, `{{cliente}}`, `{{responsable}}`, `{{sector_actual}}`, `{{resolucion}}`, `{{link_observacion}}`, `{{link_encuesta}}`

---

## 10. SLAs y KPIs preconfigurados

| KPI | Objetivo por defecto |
|---|---|
| Tiempo de primera respuesta | < 4 horas |
| Tiempo de resolución | < 72 horas |
| Satisfacción del cliente (Excelente + Buena / total respondidas) | > 85% |
| Reclamos repetitivos (mismo cliente + mismo producto + mismo lote) | seguimiento |
| % CAPA implementados a tiempo | > 90% |

Los administradores pueden ajustar todos estos valores desde la pantalla de configuración de SLAs.

---

## 11. Encuesta de satisfacción

- Se envía **solo** en observaciones externas al pasar a estado `cerrada` o `resuelta`.
- **Token único** por observación, válido por 30 días.
- URL pública sin login: `https://sgo.tublood.com/encuesta/:token`
- Si el cliente abre el link después de haber respondido → mensaje "Ya respondió esta encuesta, gracias."
- Si pasan 7 días sin respuesta → se envía **un único recordatorio** (no más de uno).

---

## 12. Integración con ERP

> Detalle completo en el capítulo 6 del documento técnico v3.

**Coordinación necesaria antes de desarrollar:**
1. Documentación completa de la API (Swagger/OpenAPI)
2. Credenciales y URL de sandbox
3. URL del ambiente productivo
4. Método de autenticación
5. Referente técnico del equipo ERP disponible (~15 días durante la integración)

**Usos conocidos del ERP desde el SGO:**
- Validar número de remito ingresado por el cliente
- Crear notas de crédito
- Prepoblar el campo "Ejecutivo de cuenta a cargo" desde datos del cliente en el ERP

---

## 13. Reglas de negocio adicionales

### Historial inmutable
- **Todas** las acciones generan una entrada de historial: clasificación, reclasificación, derivación, cambios de responsable, comentarios, cambios de estado, cierre, reasignaciones automáticas.
- El historial **no puede ser editado ni eliminado** por ningún usuario, incluido el administrador.
- Implementar con un observer o event listener desde el inicio.

### Badge "sin clasificar"
- Las observaciones pendientes de clasificación muestran badge **"⏳ SIN CLASIFICAR"** en color amarillo en todas las listas.
- El dashboard de GC muestra una métrica destacada con el conteo de observaciones sin clasificar.

### Numeración
- Formato `N-AA`: número secuencial + últimas 2 cifras del año.
- Ejemplo: `0001-26`, `0002-26`.
- Reset al inicio de cada año.
- Generado por el sistema, no editable por el usuario.

### Visibilidad de observaciones
- **Usuario interno:** ve solo las observaciones de su sector (donde es responsable o notificado).
- **Solo lectura / Admin / GC:** ve todas las observaciones del sistema.

---

## 14. Portal externo (público, sin login)

**URL:** `https://sgo.tublood.com/` (o ruta `/portal`)

### Lo que el cliente VE y LLENA:
- Tipo de observación: Falla de Producto / Disconformidad de Servicio
- Datos de contacto
- Campos descriptivos del caso (lote, vencimiento, remito, descripción, foto/adjuntos, institución, provincia, etc.)
- Botón de envío
- Pantalla de confirmación con número de seguimiento

### Lo que el cliente NO ve:
- Prioridad
- Tipo de caso interno
- Tecnovigilancia
- Selección de sector
- Asignación de responsable
- Cualquier campo técnico-administrativo interno

---

## 15. Plan de desarrollo (orden recomendado)

### Fase 0 — Fundación (hacer ANTES de todo)
1. Diseñar todas las migraciones (todas las tablas a la vez)
2. Seeders: roles, permisos, sectores, usuarios base
3. Configurar la queue (base de datos o Redis)

### Fase 1 — Flujo central
4. Portal externo (formulario público + confirmación)
5. Flujo de clasificación (GC)
6. Flujo de derivación y estados
7. Historial inmutable (observer transversal)

### Fase 2 — Automatizaciones
8. Sistema de notificaciones (Events + Listeners + Mail)
9. Plantillas de notificación editables
10. Worker de SLA (scheduled job cada 30 min)
11. Encuesta de satisfacción (token + recordatorio)

### Fase 3 — NC y CAPA
12. No Conformidades vinculadas a observaciones
13. Flujo CAPA

### Fase 4 — Admin y config
14. Gestión de sectores (CRUD admin)
15. Configuración de SLAs (admin)
16. Gestión de formularios dinámicos por sector

### Fase 5 — Integraciones y reportes
17. Integración con ERP (requiere coordinación con equipo Tublood)
18. Tecnovigilancia ANMAT (marcado + trazabilidad)
19. Reportes y exportaciones CSV
20. Backup / Restore

---

## 16. Pendientes / preguntas abiertas

- [ ] Acceso al documento técnico v3 (.docx) para relevar capítulos 6-14 (tipos de caso, campos de disconformidad de servicio, endpoints ERP, etc.)
- [ ] Regla de asignación de responsable en GC: ¿round-robin, primero alfabético, o configurable desde admin?
- [ ] ¿La DB de producción es MySQL? ¿Hay servidor ya disponible o se provisiona nuevo?
- [ ] Definir proveedor de email (SMTP corporativo / SendGrid / AWS SES)
- [ ] Contacto con el equipo del ERP para coordinar la integración
- [ ] Confirmar URL definitiva del sistema en producción
- [ ] ¿El sistema maneja múltiples idiomas o es exclusivamente en español?
- [ ] ¿Hay un diseño visual (colores, logo) o se usa el del prototipo HTML como referencia?
