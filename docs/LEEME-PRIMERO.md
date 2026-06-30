# Sistema SGO Tublood — Paquete completo para entregar al programador

Esta carpeta contiene toda la información para desarrollar el **Sistema de Gestión de Observaciones, No Conformidades y Postventa Virtual** que reemplazará al SGO actual de Tublood SA.

---

## ✅ Lo que tenés que mandarle al programador (solo 3 archivos)

| Archivo | Descripción |
|---|---|
| **`Especificacion-Tecnica-SGO-v3.docx`** | Documento técnico principal (~50 páginas, 28 capítulos). Contiene TODA la especificación del sistema. |
| **`SGO-Tublood-v3-v3.html`** | Prototipo interactivo completo (un solo archivo, se abre en cualquier navegador). Demo visual del UX. |
| **`Notas-Finales-Prototipo.md`** | Addendum con los últimos ajustes y aprendizajes del prototipo. |

Comprimí esos tres archivos en un ZIP, mandalos por mail (o servicio de archivos) al programador o estudio elegido. Con eso tiene todo lo que necesita para cotizar y arrancar el desarrollo.

Los demás archivos en esta carpeta son versiones intermedias e iteraciones del proceso. Pueden ignorarse o borrarse desde el Explorador de Windows.

---

## Cómo usar el paquete (orden recomendado para el programador)

### 1. Leer el documento técnico v3

`Especificacion-Tecnica-SGO-v3.docx` es la fuente de verdad. Sus 28 capítulos cubren todo lo necesario para construir el sistema desde cero:

- Roles y permisos (4 niveles: cliente externo, usuario interno, **solo lectura**, administrador)
- Modelo de datos completo (12+ tablas)
- **Integración con ERP propio vía API JSON** (capítulo 6 — clave para Tublood)
- **Clasificación: prioridad y tipo de caso** (capítulo 7)
- **Encuesta de satisfacción al cierre** (capítulo 8)
- **Resoluciones tipificadas** (capítulo 9)
- **Flujo CAPA** — Corrective and Preventive Action (capítulo 10)
- **Tecnovigilancia** — obligación regulatoria ANMAT (capítulo 11)
- **Plantillas de notificaciones editables** (capítulo 12)
- Formularios por sector con todos los campos (capítulos 13-14)
- Gestión dinámica de sectores y formularios editables por admin
- Workflow de estados, numeración N-AA con reset anual
- SLAs con tolerancias y escalamiento automático al superior
- Notificaciones in-app y por email a múltiples destinatarios
- Arquitectura recomendada con stack tecnológico sugerido
- Lista completa de endpoints API REST
- Requerimientos no funcionales (seguridad, performance, backups, cumplimiento regulatorio)
- Plan de implementación en 3 fases (15-19 semanas estimadas)
- Criterios de aceptación

### 2. Leer el addendum (Notas-Finales-Prototipo.md)

Mientras iterábamos sobre el prototipo identificamos detalles importantes del flujo Postventa que conviene que el programador conozca antes de arrancar. Están consolidados en ese archivo.

### 3. Probar el prototipo interactivo

Abrir `SGO-Tublood-v3-v3.html` en un navegador moderno. Es un único archivo HTML autocontenido. Primer ingreso: usuario `admin`, contraseña `admin`. Permite explorar el look & feel completo del sistema y validar los flujos.

### 4. Coordinar con el equipo de ERP de Tublood

Tublood tiene un ERP de desarrollo propio con API JSON documentada. **Antes de cotizar**, el programador del SGO debe coordinar con el equipo del ERP para:

1. Documentación completa de la API (Swagger/OpenAPI deseado).
2. Credenciales y URL de ambiente sandbox.
3. URL del ambiente productivo.
4. Método de autenticación.
5. Referente técnico del equipo ERP disponible (~15 días durante la integración).

El capítulo 6 del documento técnico detalla exactamente qué endpoints del ERP va a consumir el SGO y para qué.

### 5. Cotizar

Con todo lo anterior, el programador puede preparar una cotización seria con cronograma realista.

---

## Resumen ejecutivo del sistema

**Para clientes externos**: cargan reclamos desde un portal público sin login. Solo describen el problema (no clasifican). Reciben número de seguimiento. Al cierre del caso, reciben automáticamente un email con la resolución y un link a la encuesta de satisfacción.

**Para Garantía de Calidad**: recibe todos los reclamos externos para clasificación inicial (prioridad, tipo de caso, tecnovigilancia). Después de clasificar, deriva al sector que corresponda. **La clasificación es exclusiva de este sector.**

**Para usuarios internos** (Facturación, Logística, Depósito, Comercial, COMEX, Asuntos Regulatorios, Dirección Técnica): operan las observaciones que les llegan ya clasificadas. Pueden derivar, comentar, adjuntar, cambiar estado, crear NC vinculadas.

**Para administradores** (3 personas): gestión completa del sistema, usuarios, sectores, formularios dinámicos, SLAs, plantillas de notificación, integración con ERP. **No clasifican** (eso es de Calidad).

**Para Dirección / Auditoría (rol Solo Lectura)**: ven todo, exportan reportes, sin posibilidad de modificar.

**Automatizaciones**: numeración N-AA con reset anual; integración con ERP para validar remitos y crear notas de crédito; notificaciones por email a responsable principal + usuarios en copia; worker de SLA cada 30 minutos con alertas y escalamiento al superior; encuesta automática al cierre; tecnovigilancia con prioridad crítica forzada.

---

## Datos del proyecto

- **Empresa**: Tublood SA (dispositivos médicos)
- **Usuarios**: ~35 internos
- **Sectores**: Facturación, Logística, Depósito, Comercial, COMEX, Asuntos Regulatorios, **Garantía de Calidad**, **Dirección Técnica**
- **ERP**: desarrollo propio con API JSON documentada
- **Cumplimiento regulatorio**: ANMAT (Argentina), trazabilidad de tecnovigilancia 10 años
- **Hosting**: a definir
- **Email**: a definir (SMTP corporativo o SendGrid/AWS SES)

---

## Referencias

- **Iara**: administradora principal del sistema (referente del proyecto en Tublood).
- **Nadia Pavia-ra**: autora del Plan Estratégico de Postventa Virtual (sector Garantía de Calidad).

---

*Toda la información de este paquete fue elaborada a partir del análisis funcional, los requerimientos de la administración del sistema, la propuesta del sector Garantía de Calidad y la información del ERP corporativo.*
