export interface User {
    id: number
    name: string
    email: string
    roles: string[]
    permissions: string[]
    sector_id?: number | null
    sector?: Sector | null
    supervisor_id?: number | null
    gerente_id?: number | null
    es_gerente?: boolean
}

/** Sector: dónde trabaja la persona y destino de gestión de una observación. */
export interface Sector {
    id: number
    nombre: string
    slug: string
    dias_gestion: number | null
    activo: boolean
    usuarios_count?: number
}

export interface AlertaNotificacion {
    id: string
    created_at: string
    data: {
        tipo: 'observacion_asignada' | 'observacion_reasignada' | 'observacion_seguimiento' | 'observacion_critica' | 'observacion_vencida' | 'observacion_escalada' | 'observacion_finalizada'
        observacion_id: number
        numero: string
        titulo: string
        mensaje: string
        url: string
        /** `null` mientras el caso no esté clasificado. Pinta el aviso en rojo si es `critica`. */
        prioridad: string | null
    }
}

/**
 * Observación pendiente de clasificación, para la sección propia de la campana
 * del equipo de Garantía de Calidad. Sale de una consulta viva contra
 * `observations` (no de una notificación), así que se autolimpia sola en
 * cuanto alguien la clasifica.
 */
export interface ObservacionSinClasificar {
    id: number
    numero: string
    titulo: string
    origen: 'interna' | 'externa'
    contacto_nombre: string | null
    created_at: string
    /**
     * `null` mientras el caso no esté clasificado, que es el estado de todo
     * reclamo recién entrado: el rojo aparece en los bloques "a tu cargo" y
     * "en seguimiento", no en "Entró un reclamo nuevo".
     */
    prioridad: string | null
    /** Solo viaja en `asignadas`: los sin clasificar están todos en el mismo estado. */
    estado?: string
}

/** Artículo del catálogo de RP Sistemas. Los primeros campos son del ERP (solo lectura); los últimos cuatro son propios del panel. */
export interface Articulo {
    id: number
    codigo: string
    descripcion: string
    descripcion_adicional: string | null
    codigo_barras: string | null
    unidad_medida: string | null
    codigo_agrupacion_1: string | null
    descripcion_agrupacion_1: string | null
    codigo_agrupacion_2: string | null
    descripcion_agrupacion_2: string | null
    codigo_agrupacion_3: string | null
    descripcion_agrupacion_3: string | null
    stock: string | null
    stock_disponible: string | null
    /** String suelto del ERP. No confundir con `proveedor_id`: son padrones distintos. */
    codigo_proveedor: string | null
    /** FK al padrón local. Campo propio del panel: la sincronización no lo toca. */
    proveedor_id: number | null
    /** Solo viaja cuando el controller lo eager-loadea. */
    proveedor?: { id: number; numero: string | null; razon_social: string } | null
    modificado_en: string | null
    synced_at: string | null
    /** Vino en el último sync de RP (o lo cargó el Excel de Calidad sin sync). Ver ArticuloSyncService. */
    activo: boolean
    fecha_vencimiento: string | null
    /**
     * Registro de ANMAT. ⚠️ Desde el 30/9/2026 lo escribe la sincronización
     * (`ARTICULOS.NRO_REGISTRO`): ya no es un campo propio del panel.
     */
    pm: string | null
    /** Tipo ANMAT crudo del ERP (`PM`, `PMV`, `ME`, `A`). Etiqueta en config/articulos.php. */
    tipo_anmat: string | null
    legajo: string | null
    observaciones: string | null
    link_registro: string | null
}

/** Proveedor del padrón. `numero`, `razon_social` y `domicilio` vienen del Excel; el resto se carga a mano en el panel. */
export interface Proveedor {
    id: number
    /** `null` en los que creó el import de artículos: esa planilla no trae el NUM_PROV. */
    numero: string | null
    razon_social: string
    nombre_fantasia: string | null
    domicilio: string | null
    cuit: string | null
    telefono: string | null
    celular: string | null
    /** Celda cruda del ERP: puede traer varias direcciones separadas por `;`. */
    mail: string | null
    /**
     * Las direcciones de `mail` ya separadas, que arma el accesor del modelo.
     * Siempre viene (`$appends`), aunque sea un array vacío. Es lo que usan el
     * listado y la ficha: la regla de corte se escribe una sola vez, en PHP.
     */
    mails: string[]
    localidad: string | null
    provincia: string | null
    codigo_postal: string | null
    contacto: string | null
    /** Único campo propio del panel: la sincronización con el ERP no lo toca. */
    observaciones: string | null
    /** Estado en el ERP (A / S / I). No confundir con `habilitado`, que es del panel. */
    estado: string | null
    /**
     * Clasificación de RP (AGRU_1), como código crudo: `01`, `02`, `03`.
     * La etiqueta sale de `config/proveedores.php`. La sincronización lo pisa:
     * no confundir con `tipo_proveedor`, que es nuestro y se edita a mano.
     */
    clasificacion_erp: string | null
    /** Slug del catálogo compartido de config/documentacion.php. Campo propio del panel. */
    tipo_proveedor: string | null
    tiene_legajo: boolean
    /** Habilitación documental del panel. Distinto de `estado` (ERP). */
    habilitado: boolean
    /** Derivado: sale del documento que determina el VTO final de su tipo. */
    fecha_vencimiento: string | null
    /** Derivado, denormalizado para poder filtrar el listado. */
    documentacion_completa: boolean
    /** Solo en el listado (`withExists`): tiene algún documento vencido. */
    tiene_vencidos?: boolean
    documentos?: ClienteDocumento[]
    modificado_en: string | null
    synced_at: string | null
    created_at: string | null
    updated_at: string | null
}

/** Renglón de venta espejado del ERP. Solo lectura: la tabla se reemplaza en cada sincronización. */
export interface Venta {
    id: number
    compro_nro: string | null
    cod_comprobante: string | null
    grupo_compro_descrip: string | null
    fecha: string | null
    anio: number | null
    cliente: number | null
    razon_social: string | null
    nombre_fantasia: string | null
    provincia: string | null
    articulo: string | null
    descrip_arti: string | null
    cantidad: string | null
    /** Solo viajan si el usuario tiene `ventas.montos`; si no, el backend los saca. */
    precio_neto?: string | null
    sub_total?: string | null
    /** El ERP usa 0 para "sin remito", no null. */
    remito_nro: number | null
    vendedor: string | null
    codi_vende: string | null
    deposito: string | null
    transportista: string | null
    condi_venta: string | null
    synced_at: string | null
    /**
     * Lotes despachados en este renglón. Los resuelve el backend en vivo contra
     * `venta_partidas` (clave compuesta `compro_nro` + `articulo`).
     * Vacío es normal: el 11% de los renglones son artículos sin trazabilidad
     * de lote (servicios, ajustes de cambio).
     */
    lotes?: VentaPartida[]
}

export interface ClienteVencimiento {
    id: number
    numero: string
    razon_social: string
    fecha_vencimiento: string
}

/**
 * Marca y textos administrables desde /configuracion.
 * El catalogo de claves vive en config/configuracion.php; los valores por
 * defecto son los textos que antes estaban hardcodeados.
 * `logo` y `favicon` llegan ya resueltos como URL publica (o null).
 */
export interface ConfiguracionMarca {
    empresa_nombre: string
    empresa_bajada: string
    app_nombre: string
    app_bajada: string
    logo: string | null
    /** Versión clara, para fondos oscuros. Null cae al ícono por defecto, no al `logo`. */
    logo_dark: string | null
    favicon: string | null
    login_kicker: string
    login_titulo: string
    login_titulo_destacado: string
    login_parrafo: string
    /** Un punto destacado por linea. */
    login_features: string
    /** Foto del panel de branding. Null cae a la que viene con el sistema, no al gradiente pelado. */
    login_fondo: string | null
    login_footer: string
    login_pie_sistema: string
    portal_titulo: string
    portal_bajada: string
    pdf_encabezado: string
    pdf_pie: string
}

export interface PageProps extends Record<string, unknown> {
    auth: {
        user: User | null
    }
    flash: {
        success?: string
        error?: string
    }
    configuracion: ConfiguracionMarca
    /** Config de observaciones que necesita el layout, no una pantalla puntual. */
    incidencias: {
        prioridades: Record<string, string>
    }
    broadcasting: {
        driver: 'pusher' | 'reverb' | 'null'
        key: string | null
        cluster: string | null
        host: string | null
        port: number | null
        scheme: 'http' | 'https' | null
    }
    notificaciones: {
        vencimientos: ClienteVencimiento[]
        /** Alertas de vencimiento/escalamiento de observaciones. No incluye reclamos externos: esos tienen su propia sección. */
        alertas: AlertaNotificacion[]
        /** Todos los reclamos sin clasificar, para la sección "Sin clasificar" de la campana. */
        sinClasificar: ObservacionSinClasificar[]
        /** Subconjunto de `sinClasificar` de los que a este usuario le avisaron. Insiste en el modal (descarte del lado del cliente, ver AppLayout.vue) hasta que se clasifique el caso. */
        externas: ObservacionSinClasificar[]
        /** Casos abiertos donde este usuario es el responsable. Bloque del modal, insiste mientras el caso siga abierto. */
        asignadas: ObservacionSinClasificar[]
        /** Casos abiertos donde lo sumaron como "a notificar". Excluye los que ya están en `asignadas`. */
        seguimiento: ObservacionSinClasificar[]
    }
}

export interface Role {
    id: number
    name: string
    permissions: Permission[]
}

export interface Permission {
    id: number
    name: string
}

/** Permiso con su nombre en español y su grupo (config/permisos.php). */
export interface PermisoEtiquetado {
    id: number
    name: string
    label: string
    grupo: string
}

export interface ClienteAttachment {
    id: number
    original_name: string
    mime_type: string
    size: number
    created_at: string
}
/** Una fila del checklist de documentación de un cliente. */
export interface ClienteDocumento {
    id: number
    documento: string
    presentado: boolean
    fecha_vencimiento: string | null
}
/**
 * Un documento del catálogo del tipo de cliente, ya cruzado con lo que el
 * cliente tenga cargado. Lo arma ClienteController::checklist().
 */
/**
 * La definición de un documento dentro del catálogo de un tipo. Sale de
 * `Documentacion::checklistPorTipo()` y es **solo la definición**: qué se pide
 * y cómo se comporta, sin lo que el registro tenga cargado.
 */
export interface DocumentoChecklist {
    documento: string
    label: string
    obligatorio: boolean
    vence: boolean
    /** Su vencimiento es el del cliente ("lo que determina el VTO final"). */
    determina_vencimiento: boolean
}

/** Lo que un cliente o proveedor tiene cargado, por clave de documento. */
export type DocumentosCargados = Record<string, {
    presentado: boolean
    fecha_vencimiento: string | null
}>

/**
 * El checklist como lo maneja el formulario: strings, que es con lo que
 * trabajan RadioGroup e InputFecha, y es también la forma en que viaja al
 * backend.
 */
export type ChecklistFormulario = Record<string, {
    presentado: string
    fecha_vencimiento: string
}>
/** El "control automático" de la planilla — ver Cliente::estadoDocumentacion(). */
export interface EstadoDocumentacion {
    completa: boolean
    faltantes: string[]
    vencidos: { documento: string; label: string; fecha_vencimiento: string }[]
    proximo_vencimiento: string | null
    dias_para_vencer: number | null
}

export interface Cliente {
    id: number
    numero: string
    razon_social: string
    nombre_fantasia: string | null
    cuit: string | null
    codigo_iva: string | null
    descripcion_iva: string | null
    telefono: string | null
    mail: string | null
    /** Mail cargado por el propio cliente desde el portal. La sync con RP no lo pisa. */
    mail_nuevo: string | null
    contacto: string | null
    domicilio: string | null
    localidad: string | null
    codigo_provincia: string | null
    descripcion_provincia: string | null
    porcen_descuen: string | null
    usuario_web: string | null
    codigo_vendedor: string | null
    nombre_vendedor: string | null
    codigo_postal: string | null
    /** Derivado: sale del documento que determina el VTO final de su tipo. */
    fecha_vencimiento: string | null
    /** Slug del catálogo de config/documentacion.php. */
    tipo_cliente: string | null
    tiene_legajo: boolean
    habilitado: boolean
    notas: string | null
    /** Derivado, denormalizado para poder filtrar el listado. */
    documentacion_completa: boolean
    /** Solo en el listado (`withExists`): tiene algún documento vencido. */
    tiene_vencidos?: boolean
    synced_at: string | null
    attachments?: ClienteAttachment[]
    documentos?: ClienteDocumento[]
}

export interface ObservationProduct {
    id: number
    producto: string
    codigo: string | null
    cantidad_afectada: number
    tipo_presentacion: string | null
    lote: string
    fecha_vencimiento: string
    numero_remito: string
    tipo_comprobante: string
    /** Solo si `codigo` matchea un artículo del catálogo sincronizado. */
    articulo?: {
        descripcion: string
        pm: string | null
        /** De quién es el producto. Sale del padrón en vivo, no de una copia. */
        proveedor?: { id: number; razon_social: string } | null
    } | null
    /**
     * La partida del padrón que matchea el `lote` declarado, o `null` si no se
     * pudo atribuir ninguna. La resuelve el backend en vivo contra `partidas`
     * (clave compuesta `codigo` + `lote`), no es una copia guardada en la
     * observación — ver `Partida::adjuntarAProductos()`.
     */
    partida?: PartidaDeProducto | null
}

/** Lote despachado en un renglón de venta. */
export interface VentaPartida {
    id: number
    compro_nro: string
    codigo_articulo: string
    codigo_partida: string
    cantidad: string | null
    remito_tipo: string | null
    remito_numero: number | null
    /** Derivado: el remito con su serie (`VR8-8500`). El número solo es ambiguo. */
    remito: string | null
    fecha: string | null
}

/**
 * Un despacho en la ficha de una partida: el renglón de venta más los datos
 * del cliente, que salen de `ventas` por LEFT JOIN.
 */
export interface DespachoPartida {
    id: number
    compro_nro: string
    cantidad: string | null
    remito_tipo: string | null
    remito_numero: number | null
    fecha: string | null
    /** `null` en los despachos anteriores a la ventana que cubre `ventas`. */
    cliente: number | null
    razon_social: string | null
    provincia: string | null
    vendedor: string | null
}

/**
 * Fila del listado de partidas. Es el mismo registro que `PartidaDeProducto`
 * pero con el artículo y el proveedor siempre presentes como claves (aunque su
 * valor sea `null`), porque el listado los eager-loadea de entrada.
 */
export interface Partida {
    id: number
    codigo_articulo: string
    codigo_partida: string
    fecha_vencimiento: string | null
    ubicacion: string | null
    ultimo_movimiento_at: string | null
    proveedor_numero: string | null
    synced_at: string | null
    /** `null` si el código del ERP no está en el catálogo local de artículos. */
    articulo: { codigo: string; descripcion: string } | null
    /** `null` si ningún movimiento del kardex trae el proveedor cargado. */
    proveedor: { id: number; numero: string | null; razon_social: string } | null
}

/** Lo que el padrón de partidas sabe del lote que declaró el cliente. */
export interface PartidaDeProducto {
    id: number
    codigo_articulo: string
    codigo_partida: string
    /** Vencimiento según el ERP, que puede no coincidir con el que declaró el cliente. */
    fecha_vencimiento: string | null
    ubicacion: string | null
    /** Fecha del último movimiento de la partida en el ERP. */
    ultimo_movimiento_at: string | null
    /** De quién se compró la partida. Sale del padrón de proveedores. */
    proveedor?: { id: number; numero: string | null; razon_social: string } | null
    articulo?: { codigo: string; descripcion: string } | null
}

export interface Observacion {
    id: number
    numero: string
    tipo: string
    estado: string
    origen: string
    titulo: string
    descripcion: string
    contacto_nombre: string
    contacto_email: string
    contacto_numero_cliente: string | null
    contacto_telefono: string | null
    responsable_id: number | null
    responsable: { id: number; name: string } | null
    responsable_asignado_at: string | null
    vence_at: string | null
    alerta_nivel: number
    sector_id: number | null
    sector: { id: number; nombre: string } | null
    prioridad: string | null
    tipo_caso: string | null
    datos_especificos: Record<string, string | number | null> | null
    cliente: { id: number; numero: string; razon_social: string; mail: string | null; telefono: string | null } | null
    productos: ObservationProduct[]
    /** Adjuntos sueltos (subidos con el botón de "Archivos adjuntos"), sin los de la bitácora. */
    attachments?: ObservationAttachment[]
    /** Bitácora del caso: comentarios y cambios, más reciente primero. */
    historial?: ObservationHistoryEntry[]
    /** Usuarios a notificar: reciben el aviso y ven el caso, pero no lo gestionan (ver ObservacionPolicy::update). */
    notificados?: { id: number; name: string; apellido: string | null }[]
    /**
     * Los desvíos a los que escaló este reclamo (§5).
     *
     * ⚠️ `numero` puede ser `null`: una NC en borrador todavía no tiene número
     * y eso no es un dato faltante — se asigna al aprobarla.
     */
    no_conformidades?: { id: number; numero: string | null; estado: string; motivo: string }[]
    created_at: string
    /** Solo tiene valor si está borrada (soft delete). El motivo de la baja está en `baja.nota`. */
    deleted_at?: string | null
    /** La última entrada de bitácora de tipo "baja" (cancelación o borrado), con motivo y autor. */
    baja?: ObservationHistoryEntry | null
    /** Sellada por ObservacionObserver al entrar en un estado final; vuelve a null si el caso se reabre. */
    cerrada_at?: string | null
    /**
     * Quién cerró el caso. Columna propia y no una lectura de la bitácora — ver
     * `ObservacionObserver::marcarCierre()`. El nombre lleva el sufijo
     * `_usuario` porque `cerrada_por` a secas es la columna `int`.
     */
    cerrada_por_usuario?: { id: number; name: string; apellido: string | null } | null
}

export interface ObservationAttachment {
    id: number
    original_name: string
    size: number
    /** Presentes cuando el adjunto se subió desde un comentario de bitácora, no desde el botón suelto. */
    observation_history_id?: number | null
    user_id?: number | null
}

/**
 * Entrada de la bitácora del caso: un cambio (estado, responsable, sector,
 * clasificación) registrado por ObservacionObserver, o un comentario manual
 * con sus adjuntos. Inmutable — no hay endpoint de edición ni de borrado.
 */
export interface ObservationHistoryEntry {
    id: number
    accion: 'comentario' | 'estado' | 'responsable' | 'sector' | 'clasificacion' | 'adjunto' | 'notificados' | 'sistema' | 'baja' | 'restauracion'
    nota: string | null
    /** Forma según `accion`: `{de, a}` para estado/responsable/sector, `{prioridad: {de,a}, tipo_caso: {de,a}}` para clasificacion. */
    cambios: Record<string, unknown> | null
    created_at: string
    /** Null en las entradas automáticas: no tienen usuario detrás. */
    user: { id: number; name: string; apellido: string | null } | null
    adjuntos: ObservationAttachment[]
    /** Solo viene cargada en Admin/Bitacora/Index: ahí la entrada se ve fuera del contexto de un caso puntual. */
    observacion?: { id: number; numero: string; titulo: string; sector: { id: number; nombre: string } | null }
}

export interface PaginatedData<T> {
    data: T[]
    current_page: number
    last_page: number
    per_page: number
    total: number
    links: { url: string | null; label: string; active: boolean }[]
}

/**
 * Un artículo dentro de un grupo del tablero de reposición.
 *
 * ⚠️ Las claves son de una letra a propósito: el dataset son ~4.800 grupos con
 * ~5.200 artículos y 25 meses cada uno, y viaja entero en las props. Con nombres
 * largos el payload pasaría de ~1 MB a más de 2 MB. Ver ReposicionService.
 */
export interface ArticuloReposicion {
    /** Código. */
    c: string
    /** Descripción. */
    d: string
    /** Activo: 1 / 0. */
    a: 0 | 1
    /** Unidades por envase. 1 = se cuenta de a uno (la pantalla muestra "–"). */
    u: number
    /** Categoría (código de AGRU_1, o SERVICIOS / SIN_CAT). */
    k: string
    /** Stock en envases, CRUDO del ERP: puede venir negativo. */
    s: number
    /** Reservado, en envases. */
    r: number
    /** OC pendiente, en envases. */
    o: number
    /** Unidades vendidas por mes. Ausente si el artículo nunca vendió. */
    v?: number[]
    /** Importe neto vendido por mes. Ausente si el artículo nunca vendió. */
    m?: number[]
}

/** Producto unificado del tablero: uno o varios artículos con el mismo GTIN. */
export interface GrupoReposicion {
    /**
     * Clave estable y única del grupo.
     *
     * ⚠️ El nombre no sirve como clave: 73 descripciones se repiten entre 166
     * artículos distintos sin GTIN. Usar `id` en `:key` y en cualquier Set.
     */
    id: string
    /** Nombre: el GTIN si unifica, la descripción del artículo si va solo. */
    n: string
    /** Categorías presentes en el grupo. Puede haber más de una. */
    c: string[]
    /** Envase común para mostrar. 0 = "varios" (los artículos difieren). */
    u: number
    /** Artículos que unifica. */
    i: ArticuloReposicion[]
}

/** Entrada de la bitácora de una No Conformidad. Inmutable — ver §7 del instructivo. */
export interface NonConformityHistoryEntry {
    id: number
    accion: 'comentario' | 'estado' | 'aprobacion' | 'devolucion' | 'rechazo' | 'observaciones' | 'investigacion' | 'contencion' | 'responsable' | 'plan' | 'avance' | 'verificacion' | 'cierre' | 'adjunto' | 'reapertura' | 'cancelacion' | 'sistema'
    nota: string | null
    /** Forma según `accion`: `{de, a}` en un cambio de estado, `{sumadas, sacadas}` en el vínculo con observaciones. */
    cambios: Record<string, unknown> | null
    created_at: string
    /** Null en las entradas automáticas: no tienen usuario detrás. */
    user: { id: number; name: string; apellido: string | null } | null
    adjuntos: { id: number; original_name: string; size: number | null }[]
}

/**
 * No Conformidad: un incumplimiento confirmado que se investiga, se trata y se
 * verifica. No confundir con "Nota de Crédito", que la especificación técnica
 * también abrevia "NC".
 */
export interface NoConformidad {
    id: number
    /** ⚠️ `null` mientras no esté aprobada: el número se asigna recién ahí (§4.4). */
    numero: string | null
    anio: number
    estado: 'borrador' | 'pendiente_aprobacion' | 'abierta' | 'plan_accion' | 'en_implementacion' | 'verificacion_eficacia' | 'cerrada' | 'rechazada' | 'cancelada'
    tipo_desvio: 'interno' | 'externo'
    fecha_deteccion: string
    motivo: string
    descripcion: string
    sector_id: number | null
    cliente_id: number | null
    proveedor_id: number | null
    aprobada_at: string | null
    cerrada_at: string | null
    created_at: string
    /** La NC que ésta reemplaza, si nació de una verificación ineficaz. */
    reemplaza_a_id: number | null
    /**
     * Quien gestiona el caso de punta a punta. `null` = la gestiona Calidad.
     * ⚠️ No es `creador`: cualquiera carga un desvío, y quien lo trabaja se
     * designa al aprobarlo (o después, pasando la posta).
     */
    responsable_id: number | null
    // Investigación (§4.4). Todos nullable: la etapa se completa de a poco.
    investigacion: string | null
    alcance: string | null
    afectados: string | null
    evaluacion_riesgo: string | null
    es_grave: boolean
    es_repetitivo: boolean
    requiere_capa: boolean
    /**
     * Cuándo se va a comprobar si el plan sirvió. Se carga al armar el plan
     * (sección 5) y es lo que dispara el recordatorio.
     *
     * ⚠️ No es `fecha_seguimiento`, que es cuándo se verificó (sección 6).
     */
    fecha_verificacion_prevista: string | null
    causa_raiz: string | null
    /**
     * Las 6M: solo los factores que aportaron algo. `null` = no se analizó por
     * factores, que se lee distinto de "se analizó y ninguno aplicó".
     */
    causa_raiz_factores: Record<string, string> | null
    conclusion: string | null
    // Verificación de eficacia (§4.7).
    metodo_seguimiento: string | null
    fecha_seguimiento: string | null
    evidencia_revisada: string | null
    /** ⚠️ No es un estado: la NC se queda en verificación hasta que se pueda medir. */
    resultado_eficacia: 'eficaz' | 'ineficaz' | 'pendiente_evaluacion' | null
    observaciones_verificacion: string | null
    // Cierre (§4.8).
    resultado_final: string | null
    observaciones_finales: string | null
    sector: { id: number; nombre: string } | null
    cliente: { id: number; numero: string; razon_social: string } | null
    proveedor: { id: number; numero: string | null; razon_social: string } | null
    creador: { id: number; name: string; apellido: string | null } | null
    responsable?: { id: number; name: string; apellido: string | null } | null
    aprobada_por_usuario?: { id: number; name: string; apellido: string | null } | null
    cerrada_por_usuario?: { id: number; name: string; apellido: string | null } | null
    /** Solo viaja en el detalle. */
    observaciones?: { id: number; numero: string; titulo: string; estado: string }[]
    observaciones_count?: number
    acciones?: NonConformityAction[]
    /** Sección 3: las acciones inmediatas de contención. */
    contenciones?: NonConformityContainment[]
    reemplaza_a?: { id: number; numero: string | null } | null
    /**
     * El "Nuevo desvío N°" de la sección 7. ⚠️ Puede no tener número todavía:
     * nace en borrador y lo recibe al aprobarse, como cualquier otra.
     */
    reemplazada_por?: { id: number; numero: string | null; estado: string } | null
    historial?: NonConformityHistoryEntry[]
    attachments?: { id: number; original_name: string; size: number | null }[]
}

/**
 * Una acción inmediata de contención — sección 3 del Informe de Desvío.
 * No confundir con `NonConformityAction`, que es el plan de acción (sección 5).
 */
export interface NonConformityContainment {
    id: number
    fecha: string | null
    accion: string
    responsable_id: number | null
    responsable?: { id: number; name: string; apellido: string | null } | null
}

/**
 * Una acción del plan de una NC (§4.5). Es lo que el instructivo llama CAPA.
 * ⚠️ Sin `tipo` desde el 24/9/2026: el formulario no lo tiene y no lo usan.
 */
export interface NonConformityAction {
    id: number
    descripcion: string
    responsable_id: number | null
    fecha_prevista: string
    evidencia_requerida: string | null
    observaciones: string | null
    estado: 'pendiente' | 'en_curso' | 'completada' | 'vencida' | 'cancelada'
    avance: string | null
    fecha_real: string | null
    motivo_cancelacion: string | null
    responsable?: { id: number; name: string; apellido: string | null } | null
}

/** Qué puede hacer el usuario actual sobre una NC. Lo decide NoConformidadPolicy, no la pantalla. */
export interface PermisosNoConformidad {
    editar: boolean
    /** Designar o cambiar el responsable del caso — incluye "pasar la posta". */
    asignarResponsable: boolean
    enviarAAprobacion: boolean
    aprobar: boolean
    gestionar: boolean
    /**
     * Cargar la acción inmediata (sección 3).
     *
     * ⚠️ **No es `gestionar`**: la sección 3 se escribe desde antes de la
     * aprobación, cuando quien cargó el desvío todavía no es responsable del
     * caso ni tiene `nc.gestionar`.
     */
    contencion: boolean
    reabrir: boolean
    cancelar: boolean
}
