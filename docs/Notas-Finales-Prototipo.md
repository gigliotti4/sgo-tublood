# Notas finales del prototipo — Addendum al documento técnico v3

Este documento complementa la **Especificacion-Tecnica-SGO-v3.docx** con aprendizajes y ajustes específicos identificados durante la iteración del prototipo interactivo con la administradora del sistema (Iara) y la revisión del Plan Estratégico de Postventa Virtual de Garantía de Calidad (Nadia Pavia-ra).

Estos puntos son **complementarios** al documento técnico principal. Reflejan decisiones de UX y reglas de negocio que afinan la implementación pero no modifican la arquitectura central.

---

## 1. Flujo de clasificación exclusivo de Garantía de Calidad

### Decisión
**La clasificación de las observaciones (asignación de prioridad, tipo de caso y marca de tecnovigilancia) es una atribución exclusiva del sector Garantía de Calidad.** Ni los administradores ni otros sectores pueden clasificar.

### Justificación
Durante la iteración del prototipo se confirmó que la clasificación de un reclamo requiere criterio técnico-regulatorio específico que solo el equipo de Calidad posee. Permitir que cualquier usuario clasifique abre el riesgo de errores de criterio que afecten reportes, SLAs y cumplimiento regulatorio.

### Implementación esperada
- Solo usuarios con `sector_id = 'garantia_calidad'` ven el botón/acción **Clasificar** en el detalle de una observación pendiente.
- Los demás usuarios (incluido el administrador) ven un banner informativo "Esperando clasificación por Garantía de Calidad" pero NO pueden clasificar.
- El endpoint `POST /observaciones/:id/clasificar` debe validar este permiso server-side. No basta con ocultarlo en el frontend.
- Si Calidad no está disponible y un caso es urgente, el administrador puede temporalmente asignarse al sector Calidad para clasificar (mecanismo de emergencia), pero por defecto no tiene esa atribución.

---

## 2. Asignación inicial de observaciones del portal externo

### Decisión
Cuando un cliente carga una observación desde el portal externo:
- Se asigna automáticamente al sector **Garantía de Calidad**.
- El responsable principal se asigna a un usuario activo del sector Garantía de Calidad. Si hay varios, se puede definir una regla (primero alfabético, round-robin, configurable).
- Si no hay ningún usuario activo en Calidad, queda como **"sin asignar"** y se notifica a los administradores para que asignen.
- Se notifica por email y in-app a TODOS los usuarios activos de Garantía de Calidad.
- NO se asigna automáticamente al primer administrador (esto fue un error del flujo inicial que generaba ruido en el panel del admin).

### Justificación
Este flujo refleja el "Plan Estratégico de Postventa Virtual" donde el primer paso del flujo es "Atención Post Venta" (que en Tublood es responsabilidad de Calidad).

---

## 3. Comportamiento de derivación a otros sectores

### Decisión
Cuando un usuario (típicamente Calidad después de clasificar, o cualquier sector) deriva una observación a otro sector:

- El **sector asignado** cambia al nuevo sector.
- El **responsable principal anterior** se LIBERA (se desasigna). Queda como **"sin asignar - [sector destino]"**.
- En el momento de derivar, el usuario puede OPCIONALMENTE asignar a un usuario específico del sector destino. Si no lo hace, queda libre para que cualquier usuario del sector lo tome.
- Se notifican a TODOS los usuarios activos del sector destino con mensajes diferenciados:
  - "📌 Te asignaron: [N°]" si fue asignado a un usuario específico.
  - "📨 Nueva en [Sector]: [N°]" si quedó libre.
- Las notificaciones no leídas del responsable anterior se limpian para evitar ruido.

### Justificación
Antes de este ajuste, derivar solo cambiaba el sector pero el responsable principal seguía siendo el mismo, lo que generaba confusión: usuarios veían en su panel observaciones cuyo sector ya no les correspondía.

---

## 4. Aclaración semántica del campo "Incidencia" en propuesta de Calidad

### Decisión
La lista de campos del formulario de Falla de Producto propuesta por Garantía de Calidad incluye un ítem llamado **"Incidencia"**. Este campo debe interpretarse como **"Cantidad afectada / Cantidad de casos"** (acepción médico-regulatoria), no como un campo de texto libre adicional a "Descripción".

### Implementación
El campo **"Cantidad afectada" (numérico, obligatorio)** ya existente en el formulario cubre completamente este requerimiento. No se debe agregar un campo redundante de texto libre con nombre "Incidencia".

### Campos finales del formulario de Falla de Producto (consolidado v3)

Heredados del sistema base:
- Título breve (texto, obligatorio)
- Descripción detallada (texto largo, obligatorio)
- Cantidad afectada (número, obligatorio) ← este es el "Incidencia" de Calidad
- Lote (texto, obligatorio)
- Fecha de vencimiento (fecha, obligatorio)
- Número de remito (texto, obligatorio, validar contra ERP)
- Tipo de comprobante (radio: Factura / Remito, obligatorio)

Agregados por propuesta de Calidad:
- Institución (texto, obligatorio)
- Provincia (lista de las 24 provincias argentinas, obligatorio)
- Teléfono de contacto (texto, opcional)
- Producto (texto, obligatorio)
- Equipamiento utilizado (texto, opcional)
- Ejecutivo de cuenta a cargo (lookup de usuario, prepoblar desde el ERP si el cliente lo tiene asignado)

Asignados internamente (NO en portal externo):
- Prioridad (lista, obligatorio, solo Calidad)
- Tipo de caso (lista, obligatorio, solo Calidad)
- Tecnovigilancia (checkbox, solo Calidad)

---

## 5. Portal externo: simplicidad para el cliente

### Decisión
El portal externo accesible al cliente debe ser **lo más simple posible**. El cliente NO clasifica su propio reclamo.

### Lo que NO debe ver el cliente externo
- Prioridad
- Tipo de caso  
- Tecnovigilancia
- Selección de sector
- Asignación de responsable
- Cualquier campo técnico-administrativo interno

### Lo que SÍ debe ver
- Tipo de observación (Falla de producto / Disconformidad de servicio)
- Sus datos de contacto
- Campos descriptivos del caso (lote, vencimiento, remito, descripción, foto, institución, provincia, etc.)
- Botón de envío
- Pantalla de confirmación con número de seguimiento

El cliente describe el problema, el sistema lo categoriza internamente.

---

## 6. KPIs específicos de Calidad preconfigurados

### Decisión
Los SLAs por defecto del sistema deben preconfigurarse según los objetivos del Plan Estratégico de Postventa Virtual:

| KPI | Objetivo |
|---|---|
| Tiempo de primera respuesta | < 4 hs |
| Tiempo de resolución | < 72 hs |
| Satisfacción del cliente (Excelente + Buena / total respondidas) | > 85% |
| Reclamos repetitivos (mismo cliente + mismo producto + mismo lote) | seguimiento |
| % CAPA implementados a tiempo | > 90% |

Los administradores pueden ajustar estos valores desde la pantalla de configuración de SLAs.

---

## 7. Notificaciones que el sistema debe enviar (consolidado)

### Eventos disparadores
- Carga de observación externa (portal) → email + in-app a todos los usuarios de Garantía de Calidad.
- Carga de observación interna → email + in-app al responsable principal y notificados en copia.
- Clasificación de observación → email + in-app al responsable y notificados.
- Derivación a otro sector → email + in-app a TODOS los usuarios del sector destino.
- Cambio de responsable principal → email + in-app al nuevo responsable, in-app al anterior.
- Agregar usuario a notificados → email + in-app al nuevo agregado.
- Cierre de observación → email + in-app al responsable y creador. Si es externa: email + encuesta de satisfacción al cliente.
- SLA vencido → email + in-app al responsable principal.
- Escalamiento por incumplimiento de SLA → email + in-app al superior del responsable.
- Creación de NC vinculada → in-app al responsable de la observación origen.
- Disparo de CAPA → email + in-app al responsable de la NC + área de Calidad + Dirección Técnica si aplica.

### Plantillas
Todas las notificaciones usan plantillas editables desde la pantalla de admin (capítulo 12 del documento técnico). Variables disponibles: `{{numero}}`, `{{titulo}}`, `{{prioridad}}`, `{{tipo_caso}}`, `{{cliente}}`, `{{responsable}}`, `{{sector_actual}}`, `{{resolucion}}`, `{{link_observacion}}`, `{{link_encuesta}}`, etc.

---

## 8. Reglas de negocio adicionales

### a) Auto-corrección al cargar sistema
El sistema debe poder, al inicializarse, detectar observaciones que quedaron mal asignadas por bugs o errores de carga (por ejemplo, asignadas al admin cuando deberían haberlo sido a Calidad) y corregirlas automáticamente, registrando una entrada en el historial: "Sistema (auto-fix): Reasignación automática".

### b) Visibilidad de observaciones sin clasificar
En las listas y dashboards, las observaciones pendientes de clasificación deben mostrarse con un badge especial **"⏳ SIN CLASIFICAR"** en color amarillo. En el dashboard de Calidad debe aparecer una métrica destacada con la cantidad de observaciones pendientes.

### c) Historial inmutable
TODAS las acciones se registran en el historial: clasificación, reclasificación, derivación, cambios de responsable, agregado de notificados, comentarios, cambios de estado, cierre, reasignaciones automáticas. El historial NO debe poderse editar ni eliminar por ningún usuario, incluido el administrador.

### d) Encuesta de satisfacción
- Se envía SOLO en observaciones externas al pasar a estado Cerrada o Resuelta.
- Token único por observación, valido por 30 días.
- URL pública sin login: `https://sgo.tublood.com/encuesta/:token`
- Si el cliente abre el link después de respondido, mensaje "Ya respondió esta encuesta, gracias".
- Si pasa 7 días sin responder, se envía un único recordatorio.

---

## 9. Resumen de roles y permisos (versión consolidada)

| Acción | Cliente externo | Usuario interno | Solo lectura | Admin | Garantía de Calidad |
|---|---|---|---|---|---|
| Cargar observación desde portal | ✓ | — | — | — | — |
| Ver observaciones propias / del sector | — | ✓ | ✓ | ✓ | ✓ |
| Ver todas las observaciones | — | — | ✓ | ✓ | ✓ |
| Crear observación interna | — | ✓ | — | ✓ | ✓ |
| Comentar / Adjuntar | — | ✓ (donde es responsable o notificado) | — | ✓ | ✓ |
| Cambiar estado | — | ✓ (donde es responsable) | — | ✓ | ✓ |
| Derivar a otro sector | — | ✓ | — | ✓ | ✓ |
| **Clasificar** (prioridad, tipo, TV) | — | — | — | **—** | **✓** |
| Crear NC | — | ✓ | — | ✓ | ✓ |
| Generar CAPA | — | ✓ (vinculado a NC) | — | ✓ | ✓ |
| Gestionar usuarios | — | — | — | ✓ | — |
| Gestionar sectores | — | — | — | ✓ | — |
| Configurar SLAs | — | — | — | ✓ | — |
| Editar plantillas notificación | — | — | — | ✓ | — |
| Backup / Restore | — | — | — | ✓ | — |
| Eliminar observaciones / NC | — | — | — | ✓ | — |
| Exportar reportes a CSV | — | ✓ | ✓ | ✓ | ✓ |

---

## 10. Contactos durante el desarrollo

- **Iara**: referente del proyecto, administradora del sistema, validadora final.
- **Nadia Pavia-ra**: referente del flujo Postventa y de la propuesta de Garantía de Calidad.
- **Equipo del ERP**: para consultas sobre la API JSON (referente técnico a designar por Tublood).

---

*Estas notas son complementarias al documento técnico principal. En caso de discrepancia, prevalece lo establecido en estas notas finales como reflejo de las decisiones más recientes con la administración del sistema.*
