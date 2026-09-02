<script lang="ts">
import { ref } from 'vue'

/**
 * IDs de observación ya descartados del modal de avisos en esta carga de la
 * app. AppLayout se remonta en cada navegación de Inertia (no es un layout
 * persistente), así que esto tiene que vivir a nivel de módulo — igual que
 * `modalesAbiertos` en Modal.vue — para sobrevivir a la navegación y sin
 * embargo reiniciarse solo con un login, F5 o pestaña nueva.
 */
export const avisosVistos = ref(new Set<number>())
let broadcastingFingerprint: string | null = null
</script>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, watch } from 'vue'
import { Link, router, usePage, usePoll } from '@inertiajs/vue3'
import { configureEcho, useEchoNotification } from '@laravel/echo-vue'
import { route } from 'ziggy-js'
import { usePermissions } from '@/composables/usePermissions'
import { useDarkMode } from '@/composables/useDarkMode'
import Modal, { modalesAbiertos } from '@/Components/Modal.vue'
import FabSpeedDial, { type FabAction } from '@/Components/FabSpeedDial.vue'
import type { ObservacionSinClasificar, PageProps } from '@/types'

const { user, hasPermission } = usePermissions()
const { isDark, toggleTheme } = useDarkMode()
const page = usePage<PageProps>()

// Marca y textos administrables desde /configuracion.
const marca = computed(() => page.props.configuracion)

const currentBroadcastingFingerprint = JSON.stringify(page.props.broadcasting)

if (broadcastingFingerprint !== currentBroadcastingFingerprint && page.props.broadcasting.driver === 'pusher') {
    configureEcho({
        broadcaster: 'pusher',
        key: page.props.broadcasting.key ?? '',
        cluster: page.props.broadcasting.cluster ?? 'mt1',
        forceTLS: true,
    })
    broadcastingFingerprint = currentBroadcastingFingerprint
} else if (broadcastingFingerprint !== currentBroadcastingFingerprint && page.props.broadcasting.driver === 'reverb') {
    configureEcho({
        broadcaster: 'reverb',
        key: page.props.broadcasting.key ?? '',
        wsHost: page.props.broadcasting.host ?? 'localhost',
        wsPort: page.props.broadcasting.port ?? 8080,
        wssPort: page.props.broadcasting.port ?? 443,
        forceTLS: page.props.broadcasting.scheme === 'https',
        enabledTransports: ['ws', 'wss'],
    })
    broadcastingFingerprint = currentBroadcastingFingerprint
} else if (broadcastingFingerprint !== currentBroadcastingFingerprint) {
    configureEcho({ broadcaster: 'null' })
    broadcastingFingerprint = currentBroadcastingFingerprint
}

const sidebarCollapsed = ref(false)
const mobileSidebarOpen = ref(false)
const notificacionesOpen = ref(false)
const userMenuOpen = ref(false)
const fabOpen = ref(false)

// El logo se limita por alto con el ancho libre, para que uno apaisado no
// quede aplastado dentro de un cuadrado. Colapsado entra en la columna angosta.
const logoTamano = computed(() =>
    sidebarCollapsed.value && !mobileSidebarOpen.value ? 'h-9 max-w-[58px]' : 'h-10 max-w-45',
)

interface NotificacionTiempoReal {
    tipo: string
    observacion_id: number
    numero: string
    titulo: string
    mensaje: string
    url: string
    /** `null` mientras el caso no esté clasificado. */
    prioridad: string | null
}

interface ToastNotificacion extends NotificacionTiempoReal {
    id: string
}

const toasts = ref<ToastNotificacion[]>([])
const toastTimers = new Map<string, number>()

// Mismos íconos que las tarjetas de Admin/Observaciones/Nuevo.vue, para que
// el atajo del FAB se vea consistente con esa pantalla (que sigue existiendo).
const fabActions: FabAction[] = [
    {
        key: 'no_conformidad',
        label: 'No Conformidad',
        href: '/no-conformidades/crear',
        icon: 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.008v.008H12v-.008z',
    },
    {
        key: 'interna',
        label: 'Observación interna',
        href: route('observaciones.create', { origen: 'interna' }),
        icon: 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z',
    },
]

const vencimientos = computed(() => page.props.notificaciones?.vencimientos ?? [])
const alertas = computed(() => page.props.notificaciones?.alertas ?? [])
// Reclamos sin clasificar: sale de una consulta viva contra `observations`, no
// de una notificación, así que se autolimpia sola en cuanto alguien clasifica
// el caso. Es la fuente de verdad; `externasNuevas` (más abajo) es un
// subconjunto de esta misma lista.
const sinClasificar = computed(() => page.props.notificaciones?.sinClasificar ?? [])
const totalNotificaciones = computed(() => vencimientos.value.length + alertas.value.length + sinClasificar.value.length)

const quitarToast = (id: string) => {
    toasts.value = toasts.value.filter(toast => toast.id !== id)
    const timer = toastTimers.get(id)
    if (timer) window.clearTimeout(timer)
    toastTimers.delete(id)
}

const recibirNotificacion = (payload: NotificacionTiempoReal & { id: string }) => {
    avisosVistos.value.add(payload.observacion_id)
    quitarToast(payload.id)
    toasts.value.unshift(payload)
    toastTimers.set(payload.id, window.setTimeout(() => quitarToast(payload.id), 8000))

    // La fila de database ya fue creada antes del broadcast. Recargar solo
    // esta prop actualiza la campana y las consultas vivas sin mover la vista.
    router.reload({ only: ['notificaciones'] })
}

const abrirToast = (toast: ToastNotificacion) => {
    quitarToast(toast.id)
    router.visit(toast.url)
}

const esCritica = (prioridad: string | null | undefined) => prioridad === 'critica'

/**
 * La criticidad manda sobre el tipo de aviso: un caso crítico se pinta rojo
 * aunque el aviso sea de asignación o de seguimiento. Reusa el mismo rojo que
 * ya usan vencida y escalada para no inventar un tono nuevo.
 */
const estiloToast = ({ tipo, prioridad }: ToastNotificacion) => {
    if (esCritica(prioridad) || tipo === 'observacion_vencida' || tipo === 'observacion_escalada') {
        return 'border-error-200 bg-error-50 text-error-700 dark:border-error-500/30 dark:bg-gray-900 dark:text-error-300'
    }
    if (tipo === 'observacion_finalizada') {
        return 'border-success-200 bg-success-50 text-success-700 dark:border-success-500/30 dark:bg-gray-900 dark:text-success-300'
    }
    if (tipo === 'observacion_externa_recibida') {
        return 'border-warning-200 bg-warning-50 text-warning-700 dark:border-warning-500/30 dark:bg-gray-900 dark:text-warning-300'
    }

    return 'border-brand-200 bg-white text-gray-800 dark:border-brand-500/30 dark:bg-gray-900 dark:text-white/90'
}

useEchoNotification<NotificacionTiempoReal>(
    `App.Models.User.${user.value?.id ?? 0}`,
    recibirNotificacion,
)

const marcarLeidas = () => router.post(route('notificaciones.leidas'), {}, { preserveScroll: true })

const origenLabels: Record<string, string> = { interna: 'Interna', externa: 'Externa' }

const estadoLabels: Record<string, string> = {
    pendiente_clasificacion: 'Pendiente de clasificación',
    clasificada: 'Clasificada',
    en_proceso: 'En proceso',
    derivada: 'Derivada',
}

/**
 * Modal de avisos al entrar al panel. Tiene tres bloques independientes, y se
 * abre si cualquiera de los tres tiene algo:
 *
 * - `externasNuevas`: reclamos nuevos del portal para el equipo de Garantía de
 *   Calidad. Es el subconjunto de `sinClasificar` de los que a este usuario le
 *   avisaron; insiste hasta que alguien clasifique el caso de verdad — cerrar
 *   el modal solo calla el popup, el reclamo se sigue viendo en la campana.
 * - `asignadas`/`seguimiento`: los casos abiertos donde esta persona es
 *   responsable o está sumada como "a notificar". Insisten mientras el caso
 *   siga abierto y se callan solos al llegar a `cerrada`/`cancelada`.
 *
 * El backend siempre devuelve el pendiente completo (son consultas vivas, sin
 * ningún "ya lo vi" de su lado); lo que hace que el modal no se reabra al
 * navegar entre pantallas es `avisosVistos`, el `Set` a nivel de módulo
 * declarado arriba: cerrar el modal descarta ahí los IDs que se mostraron, y
 * eso dura toda la carga de la app — se reinicia con login, F5 o pestaña
 * nueva, que es cuando tiene que volver a insistir.
 *
 * Se refresca con polling (no solo al entrar), así que puede aparecer en
 * cualquier pantalla mientras la persona ya está trabajando — por eso se
 * abstiene mientras haya un formulario en pantalla o cualquier otro modal
 * abierto (ver `modalesAbiertos` en Modal.vue), y solo se evalúa mientras está
 * cerrado: una vez abierto no se vuelve a tocar hasta que el usuario lo cierra.
 */
const externasNuevas = computed(() => (page.props.notificaciones?.externas ?? []).filter(o => !avisosVistos.value.has(o.id)))
const asignadas = computed(() => (page.props.notificaciones?.asignadas ?? []).filter(o => !avisosVistos.value.has(o.id)))
const seguimiento = computed(() => (page.props.notificaciones?.seguimiento ?? []).filter(o => !avisosVistos.value.has(o.id)))
const mostrarAvisos = ref(false)

const esPantallaDeFormulario = computed(() => /Crear|Create|Edit|Nuevo/.test(page.component))

/**
 * Los bloques con contenido, en el orden en que se muestran. Se arma como lista
 * y no como tres bloques de markup repetidos porque los tres se dibujan igual:
 * lo único que cambia es el encabezado y qué se pone de subtítulo en cada ítem.
 */
const bloquesAvisos = computed(() => [
    {
        key: 'externas',
        titulo: 'Reclamos nuevos del portal',
        bajada: 'Cargados por clientes desde el portal. Tocá uno para abrirlo.',
        items: externasNuevas.value,
        subtitulo: (o: ObservacionSinClasificar) => o.contacto_nombre
            ? `Cargado por ${o.contacto_nombre}`
            : (origenLabels[o.origen] ?? o.origen),
    },
    {
        key: 'asignadas',
        titulo: 'A tu cargo',
        bajada: 'Siguen abiertas y sos el responsable. Tocá una para abrirla.',
        items: asignadas.value,
        subtitulo: (o: ObservacionSinClasificar) => estadoLabels[o.estado ?? ''] ?? o.estado ?? '',
    },
    {
        key: 'seguimiento',
        titulo: 'En seguimiento',
        bajada: 'Te sumaron para que sigas el caso. Podés comentar en la bitácora, pero no gestionarlo.',
        items: seguimiento.value,
        subtitulo: (o: ObservacionSinClasificar) => estadoLabels[o.estado ?? ''] ?? o.estado ?? '',
    },
].filter(bloque => bloque.items.length > 0))

const tituloAvisos = computed(() => {
    // Con más de un bloque, enumerar las combinaciones no aporta nada.
    if (bloquesAvisos.value.length !== 1) return 'Tenés novedades'

    const { key, items } = bloquesAvisos.value[0]
    const n = items.length

    if (key === 'externas') return n === 1 ? 'Entró un reclamo nuevo' : `Entraron ${n} reclamos nuevos`
    if (key === 'asignadas') return n === 1 ? 'Tenés una observación en gestión' : `Tenés ${n} observaciones en gestión`

    return n === 1 ? 'Tenés una observación en seguimiento' : `Tenés ${n} observaciones en seguimiento`
})

watch(
    [bloquesAvisos, () => page.component, modalesAbiertos],
    () => {
        if (mostrarAvisos.value) return
        if (bloquesAvisos.value.length === 0) return
        if (esPantallaDeFormulario.value) return
        if (modalesAbiertos.value > 0) return

        mostrarAvisos.value = true
    },
    { immediate: true },
)

usePoll(60000, { only: ['notificaciones'] })

const cerrarAvisos = (observacionId?: number) => {
    mostrarAvisos.value = false

    // Descarta acá y no antes de abrir: así, si el modal nunca se abrió porque
    // otra pantalla lo bloqueaba (formulario u otro modal), lo pendiente sigue
    // ahí para la próxima vez que se evalúe.
    for (const bloque of bloquesAvisos.value) {
        for (const item of bloque.items) {
            avisosVistos.value.add(item.id)
        }
    }

    if (observacionId) {
        router.visit(route('observaciones.show', observacionId))
    }
}

const fechaCorta = (fecha: string) =>
    new Date(fecha).toLocaleDateString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric' })

const diasParaVencer = (fecha: string) => {
    const hoy = new Date()
    hoy.setHours(0, 0, 0, 0)
    const venc = new Date(fecha)
    venc.setHours(0, 0, 0, 0)
    return Math.round((venc.getTime() - hoy.getTime()) / 86400000)
}

const labelVencimiento = (fecha: string) => {
    const dias = diasParaVencer(fecha)
    if (dias < 0) return `Vencido hace ${Math.abs(dias)} día${Math.abs(dias) !== 1 ? 's' : ''}`
    if (dias === 0) return 'Vence hoy'
    return `Vence en ${dias} día${dias !== 1 ? 's' : ''}`
}

interface MenuItem {
    label: string
    route: string
    permission?: string
    icon: string
}

interface MenuSection {
    title: string
    items: MenuItem[]
}

const menuSections: MenuSection[] = [
    {
        title: 'General',
        items: [
            { label: 'Dashboard', route: 'dashboard', icon: 'home' },
        ]
    },
    {
        title: 'Gestión',
        items: [
            { label: 'Observaciones', route: 'observaciones.index', permission: 'observaciones.view', icon: 'document' },
            // URL propia (/bajas, no /observaciones/bajas) a propósito: isActive()
            // compara por el primer segmento, así que colgarla de /observaciones
            // dejaría los dos ítems del sidebar prendidos a la vez.
            { label: 'Bajas', route: 'bajas.index', permission: 'observaciones.delete', icon: 'trash' },
            { label: 'Clientes', route: 'clientes.index', permission: 'clientes.view', icon: 'building' },
            { label: 'Artículos', route: 'articulos.index', permission: 'articulos.view', icon: 'cube' },
            { label: 'Proveedores', route: 'proveedores.index', permission: 'proveedores.view', icon: 'truck' },
            { label: 'Ventas', route: 'ventas.index', permission: 'ventas.view', icon: 'recibo' },
            { label: 'Partidas', route: 'partidas.index', permission: 'partidas.view', icon: 'caja' },
        ]
    },
    {
        title: 'Administración',
        items: [
            { label: 'Usuarios',   route: 'users.index', permission: 'users.view', icon: 'users' },
            { label: 'Sectores',   route: 'sectores.index', permission: 'users.view', icon: 'squares' },
            { label: 'Roles',      route: 'roles.index',  permission: 'roles.view',  icon: 'shield' },
            { label: 'Bitácora',   route: 'bitacora.index', permission: 'bitacora.view', icon: 'search' },
            { label: 'Configuración', route: 'configuracion.index', permission: 'configuracion.view', icon: 'cog' },
        ]
    }
]

const visibleSections = computed(() =>
    menuSections.map(section => ({
        ...section,
        items: section.items.filter(item => !item.permission || hasPermission(item.permission))
    })).filter(section => section.items.length > 0)
)

const isActive = (routeName: string) => {
    const segment = routeName.split('.')[0]
    return page.url === `/${segment}` || page.url.startsWith(`/${segment}/`)
}

const logout = () => router.post(route('logout'))

const handleEsc = (e: KeyboardEvent) => {
    if (e.key === 'Escape') {
        mobileSidebarOpen.value = false
        userMenuOpen.value = false
        notificacionesOpen.value = false
        fabOpen.value = false
    }
}
onMounted(() => window.addEventListener('keydown', handleEsc))
onUnmounted(() => {
    window.removeEventListener('keydown', handleEsc)
    toastTimers.forEach(timer => window.clearTimeout(timer))
    toastTimers.clear()
})

// Heroicons paths (24px stroke)
const icons: Record<string, string> = {
    caja: 'M20.25 7.5l-.625 10.632a2.25 2.25 0 01-2.247 2.118H6.622a2.25 2.25 0 01-2.247-2.118L3.75 7.5M10 11.25h4M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z',
    home: 'M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25',
    users: 'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    squares: 'M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z',
    shield: 'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    building: 'M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z',
    document: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m3.75 9h3.75M12 15.75h5.25M8.25 9h1.5m-1.5 3.75h1.5m-1.5 3.75h1.5M6.75 3h6.879a2.25 2.25 0 011.591.659l4.121 4.121a2.25 2.25 0 01.659 1.591V19.5a2.25 2.25 0 01-2.25 2.25H6.75a2.25 2.25 0 01-2.25-2.25V5.25A2.25 2.25 0 016.75 3z',
    cube: 'M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9',
    truck: 'M8.25 18.75a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h6m-9 0H3.375a1.125 1.125 0 01-1.125-1.125V14.25m17.25 4.5a1.5 1.5 0 01-3 0m3 0a1.5 1.5 0 00-3 0m3 0h1.125c.621 0 1.129-.504 1.09-1.124a17.902 17.902 0 00-3.213-9.193 2.056 2.056 0 00-1.58-.86H14.25M16.5 18.75h-2.649m-9.6-3.75h9.6m0 0V5.625c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v9.75c0 .621.504 1.125 1.125 1.125h1.5',
    recibo: 'M9 14.25l6-6m4.5-3.493V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0111.186 0c1.1.128 1.907 1.077 1.907 2.185zM9.75 9h.008v.008H9.75V9zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm4.125 4.5h.008v.008h-.008V13.5zm.375 0a.375.375 0 11-.75 0 .375.375 0 01.75 0z',
    trash: 'M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0',
    search: 'M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z',
    bell: 'M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75v-.7V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0',
    logout: 'M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9',
    chevronDown: 'M19.5 8.25l-7.5 7.5-7.5-7.5',
    menu: 'M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5',
    x: 'M6 18L18 6M6 6l12 12',
    sun: 'M12 3v1.5m0 15V21m9-9h-1.5M4.5 12H3m15.364 6.364l-1.06-1.06M6.696 6.696l-1.06-1.06m12.728 0l-1.06 1.06M6.696 17.304l-1.06 1.06M16.5 12a4.5 4.5 0 11-9 0 4.5 4.5 0 019 0z',
    moon: 'M21.752 15.002A9.72 9.72 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z',
    dots: 'M6.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM12.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0zM18.75 12a.75.75 0 11-1.5 0 .75.75 0 011.5 0z',
    plus: 'M12 4.5v15m7.5-7.5h-15',
    cog: 'M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
}
</script>

<template>
    <div class="min-h-screen bg-gray-50 dark:bg-gray-950 flex font-outfit text-gray-700 dark:text-gray-400">

        <div
            aria-live="polite"
            class="pointer-events-none fixed right-4 top-20 z-[70] flex w-[calc(100%-2rem)] max-w-sm flex-col gap-2 sm:right-6 sm:w-full"
        >
            <TransitionGroup name="toast">
                <div
                    v-for="toast in toasts"
                    :key="toast.id"
                    class="pointer-events-auto flex items-start gap-3 rounded-lg border p-3 shadow-theme-lg"
                    :class="estiloToast(toast)"
                >
                    <button type="button" class="min-w-0 flex-1 text-left" @click="abrirToast(toast)">
                        <p class="flex items-center gap-2 text-xs font-semibold uppercase">
                            Nueva notificación
                            <!-- El chip además del color: un rojo sobre ámbar no
                                 se distingue de un vistazo, y hay gente que no
                                 diferencia esos dos tonos. -->
                            <span
                                v-if="esCritica(toast.prioridad)"
                                class="rounded-full bg-error-500 px-1.5 py-0.5 text-[10px] font-bold leading-none text-white"
                            >
                                Crítica
                            </span>
                        </p>
                        <p class="mt-0.5 truncate text-sm font-semibold">{{ toast.numero }} - {{ toast.titulo }}</p>
                        <p class="mt-1 line-clamp-2 text-xs opacity-80">{{ toast.mensaje }}</p>
                    </button>
                    <button
                        type="button"
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md opacity-60 transition hover:bg-black/5 hover:opacity-100 dark:hover:bg-white/10"
                        title="Cerrar notificación"
                        @click="quitarToast(toast.id)"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="icons.x" />
                        </svg>
                    </button>
                </div>
            </TransitionGroup>
        </div>

        <!-- Overlay mobile -->
        <Transition name="fade">
            <div
                v-if="mobileSidebarOpen"
                class="fixed inset-0 bg-gray-900/50 z-40 lg:hidden"
                @click="mobileSidebarOpen = false"
            />
        </Transition>

        <!-- SIDEBAR -->
        <aside
            class="fixed top-0 left-0 z-50 flex h-full flex-col border-r border-gray-200 bg-white transition-all duration-300 dark:border-gray-800 dark:bg-gray-900"
            :class="[
                sidebarCollapsed ? 'lg:w-[90px] w-[290px]' : 'w-[290px]',
                mobileSidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'
            ]"
        >
            <!-- Logo -->
            <div
                class="flex h-16 shrink-0 items-center gap-3 px-5"
                :class="sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''"
            >
                <!--
                    Logo cargado desde /configuracion, sin texto al lado: el logo
                    ya trae la marca.

                    Las dos variantes se alternan por CSS (`dark:hidden` /
                    `hidden dark:block`) y no leyendo `isDark` del composable: la
                    clase `.dark` la escribe el blade antes de que cargue Vue, así
                    que no hay parpadeo al recargar en modo oscuro.

                    El tamaño se limita por alto con el ancho libre, para que un
                    logo apaisado no quede aplastado dentro de un cuadrado.
                -->
                <!-- Variante clara: se ve con el tema claro. -->
                <img
                    v-if="marca.logo"
                    :src="marca.logo"
                    :alt="marca.app_nombre"
                    class="w-auto shrink-0 object-contain dark:hidden"
                    :class="logoTamano"
                />
                <div v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-500 shadow-theme-xs dark:hidden">
                    <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="sr-only">{{ marca.app_nombre }}</span>
                </div>

                <!-- Variante oscura. Sin logo claro cargado va el ícono por
                     defecto y NO el `logo` normal, que no se leería acá. -->
                <img
                    v-if="marca.logo_dark"
                    :src="marca.logo_dark"
                    :alt="marca.app_nombre"
                    class="hidden w-auto shrink-0 object-contain dark:block"
                    :class="logoTamano"
                />
                <div v-else class="hidden h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-500 shadow-theme-xs dark:flex">
                    <svg class="h-5 w-5 text-white" viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5" stroke="currentColor" stroke-width="2" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    <span class="sr-only">{{ marca.app_nombre }}</span>
                </div>
            </div>

            <!-- Nav -->
            <nav class="flex-1 overflow-y-auto px-4 py-4 space-y-6">
                <div v-for="section in visibleSections" :key="section.title">
                    <p
                        class="mb-2 text-xs uppercase leading-5 text-gray-400"
                        :class="sidebarCollapsed ? 'lg:text-center' : 'px-3'"
                    >
                        <template v-if="!sidebarCollapsed">{{ section.title }}</template>
                        <svg v-else class="mx-auto hidden h-4 w-4 lg:block" viewBox="0 0 24 24" fill="currentColor">
                            <path :d="icons.dots" />
                        </svg>
                        <template v-if="sidebarCollapsed"><span class="lg:hidden">{{ section.title }}</span></template>
                    </p>
                    <div class="space-y-1">
                        <Link
                            v-for="item in section.items"
                            :key="item.route"
                            :href="route(item.route)"
                            class="group flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors"
                            :class="[
                                isActive(item.route)
                                    ? 'bg-brand-50 text-brand-500 dark:bg-brand-500/[0.12] dark:text-brand-300'
                                    : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05]',
                                sidebarCollapsed ? 'lg:justify-center lg:px-0' : ''
                            ]"
                            :title="sidebarCollapsed ? item.label : ''"
                        >
                            <svg
                                viewBox="0 0 24 24"
                                fill="none"
                                stroke="currentColor"
                                stroke-width="1.75"
                                class="h-5 w-5 shrink-0"
                                :class="isActive(item.route)
                                    ? 'text-brand-500 dark:text-brand-300'
                                    : 'text-gray-500 group-hover:text-gray-700 dark:text-gray-400 dark:group-hover:text-gray-200'"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" :d="icons[item.icon]" />
                            </svg>
                            <Transition name="slide">
                                <span v-if="!sidebarCollapsed || mobileSidebarOpen" class="truncate">{{ item.label }}</span>
                            </Transition>
                        </Link>
                    </div>
                </div>
            </nav>

            <!-- User -->
            <div class="shrink-0 border-t border-gray-200 p-4 dark:border-gray-800">
                <div
                    class="flex items-center gap-3 rounded-lg p-1.5"
                    :class="sidebarCollapsed ? 'lg:justify-center' : ''"
                >
                    <div class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-brand-500 text-sm font-semibold text-white">
                        {{ user?.name?.charAt(0)?.toUpperCase() ?? 'U' }}
                    </div>
                    <Transition name="slide">
                        <div v-if="!sidebarCollapsed || mobileSidebarOpen" class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ user?.name }}</p>
                            <p class="truncate text-theme-xs text-gray-400">{{ user?.roles[0] ?? '' }}</p>
                        </div>
                    </Transition>
                    <Transition name="slide">
                        <button
                            v-if="!sidebarCollapsed || mobileSidebarOpen"
                            @click="logout"
                            class="shrink-0 text-gray-400 transition-colors hover:text-error-500"
                            title="Cerrar sesión"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                                <path stroke-linecap="round" stroke-linejoin="round" :d="icons.logout" />
                            </svg>
                        </button>
                    </Transition>
                </div>
            </div>
        </aside>

        <!-- MAIN -->
        <div
            class="flex min-w-0 flex-1 flex-col transition-all duration-300"
            :class="sidebarCollapsed ? 'lg:pl-[90px]' : 'lg:pl-[290px]'"
        >
            <!-- Header -->
            <header class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-3 border-b border-gray-200 bg-white px-4 dark:border-gray-800 dark:bg-gray-900 lg:px-6">
                <!-- Sidebar toggle -->
                <button
                    class="flex h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-200 lg:hidden"
                    @click="mobileSidebarOpen = !mobileSidebarOpen"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.menu" />
                    </svg>
                </button>
                <button
                    class="hidden h-10 w-10 items-center justify-center rounded-lg border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-200 lg:flex"
                    @click="sidebarCollapsed = !sidebarCollapsed"
                >
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.menu" />
                    </svg>
                </button>

                <div class="flex-1" />

                <!-- Flash success inline -->
                <Transition name="fade">
                    <span
                        v-if="page.props.flash.success"
                        class="hidden items-center gap-1.5 rounded-full bg-success-50 px-3 py-1.5 text-theme-xs font-medium text-success-600 dark:bg-success-500/15 dark:text-success-400 sm:inline-flex"
                    >
                        <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" /></svg>
                        {{ page.props.flash.success }}
                    </span>
                </Transition>

                <!-- Dark mode toggle -->
                <button
                    class="flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-200"
                    :title="isDark ? 'Modo claro' : 'Modo oscuro'"
                    @click="toggleTheme"
                >
                    <svg v-if="isDark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.sun" />
                    </svg>
                    <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="icons.moon" />
                    </svg>
                </button>

                <!-- Notification bell -->
                <div class="relative">
                    <button
                        class="relative flex h-10 w-10 items-center justify-center rounded-full border border-gray-200 text-gray-500 transition-colors hover:bg-gray-100 hover:text-gray-700 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-white/[0.05] dark:hover:text-gray-200"
                        @click="notificacionesOpen = !notificacionesOpen"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="icons.bell" />
                        </svg>
                        <span
                            v-if="totalNotificaciones > 0"
                            class="absolute -right-0.5 -top-0.5 flex h-4.5 min-w-4.5 items-center justify-center rounded-full bg-warning-500 px-1 text-[10px] font-bold text-white ring-2 ring-white dark:ring-gray-900"
                        >
                            {{ totalNotificaciones > 9 ? '9+' : totalNotificaciones }}
                        </span>
                    </button>

                    <!-- Click-outside overlay -->
                    <div v-if="notificacionesOpen" class="fixed inset-0 z-30" @click="notificacionesOpen = false" />

                    <Transition name="fade">
                        <div
                            v-if="notificacionesOpen"
                            class="absolute right-0 top-full z-40 mt-3 max-h-96 w-80 overflow-y-auto rounded-2xl border border-gray-200 bg-white shadow-theme-lg dark:border-gray-800 dark:bg-gray-900"
                        >
                            <!--
                                Sin clasificar: consulta viva contra `observations`, no una
                                notificación — no lleva "Marcar leídas" porque esta lista no se
                                descarta a mano, se limpia sola cuando alguien clasifica el caso.
                            -->
                            <template v-if="sinClasificar.length > 0">
                                <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
                                    <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Sin clasificar</p>
                                </div>
                                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <li v-for="o in sinClasificar" :key="o.id">
                                        <Link
                                            :href="route('observaciones.show', o.id)"
                                            class="block px-5 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                                            @click="notificacionesOpen = false"
                                        >
                                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">
                                                {{ o.numero }} — {{ o.titulo }}
                                            </p>
                                            <p class="mt-0.5 text-theme-xs text-warning-600 dark:text-warning-400">
                                                {{ origenLabels[o.origen] ?? o.origen }}<template v-if="o.contacto_nombre"> · {{ o.contacto_nombre }}</template>
                                            </p>
                                        </Link>
                                    </li>
                                </ul>
                            </template>

                            <!-- Alertas de observaciones: las deja el comando observaciones:alertas -->
                            <template v-if="alertas.length > 0">
                                <div class="flex items-center justify-between gap-2 border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
                                    <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Observaciones</p>
                                    <button
                                        class="cursor-pointer text-theme-xs font-medium text-brand-500 hover:text-brand-600 dark:text-brand-300 dark:hover:text-brand-200"
                                        @click="marcarLeidas"
                                    >
                                        Marcar leídas
                                    </button>
                                </div>
                                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <li v-for="a in alertas" :key="a.id">
                                        <Link
                                            :href="a.data.url ?? route('observaciones.index')"
                                            class="block px-5 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                                            @click="notificacionesOpen = false"
                                        >
                                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">
                                                {{ a.data.numero }} — {{ a.data.titulo }}
                                            </p>
                                            <p
                                                class="mt-0.5 text-theme-xs"
                                                :class="a.data.tipo === 'observacion_finalizada'
                                                    ? 'text-success-600 dark:text-success-400'
                                                    : 'text-error-500'"
                                            >
                                                {{ a.data.mensaje }}
                                            </p>
                                        </Link>
                                    </li>
                                </ul>
                            </template>

                            <!-- Solo para quien sigue los vencimientos: sin el permiso no se muestra ni la cabecera. -->
                            <template v-if="hasPermission('clientes.vencimientos')">
                                <div class="border-b border-gray-100 px-5 py-3.5 dark:border-gray-800">
                                    <p class="text-sm font-semibold text-gray-800 dark:text-white/90">Clientes por vencer</p>
                                </div>
                                <ul v-if="vencimientos.length > 0" class="divide-y divide-gray-100 dark:divide-gray-800">
                                    <li v-for="c in vencimientos" :key="c.id">
                                        <Link
                                            :href="route('clientes.index', { search: c.numero })"
                                            class="block px-5 py-3 transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                                            @click="notificacionesOpen = false"
                                        >
                                            <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ c.razon_social }}</p>
                                            <p
                                                class="mt-0.5 text-theme-xs"
                                                :class="diasParaVencer(c.fecha_vencimiento) < 0 ? 'text-error-500' : 'text-warning-600 dark:text-warning-400'"
                                            >
                                                {{ labelVencimiento(c.fecha_vencimiento) }}
                                            </p>
                                        </Link>
                                    </li>
                                </ul>
                                <p v-else class="px-5 py-6 text-center text-sm text-gray-400">
                                    No hay clientes por vencer.
                                </p>
                            </template>

                            <!-- Sin ninguna de las tres secciones la campana quedaría vacía. -->
                            <p
                                v-if="!hasPermission('clientes.vencimientos') && sinClasificar.length === 0 && alertas.length === 0"
                                class="px-5 py-6 text-center text-sm text-gray-400"
                            >
                                No tenés notificaciones.
                            </p>
                        </div>
                    </Transition>
                </div>

                <!-- User dropdown -->
                <div class="relative">
                    <button
                        class="flex cursor-pointer items-center gap-2.5 rounded-full py-1 pl-1 pr-2 transition-colors hover:bg-gray-100 dark:hover:bg-white/[0.05]"
                        @click="userMenuOpen = !userMenuOpen"
                    >
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-brand-500 text-sm font-semibold text-white">
                            {{ user?.name?.charAt(0)?.toUpperCase() ?? 'U' }}
                        </div>
                        <span class="hidden text-sm font-medium text-gray-700 dark:text-gray-300 sm:block">{{ user?.name }}</span>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="hidden h-4 w-4 text-gray-400 transition-transform sm:block" :class="userMenuOpen ? 'rotate-180' : ''">
                            <path stroke-linecap="round" stroke-linejoin="round" :d="icons.chevronDown" />
                        </svg>
                    </button>

                    <div v-if="userMenuOpen" class="fixed inset-0 z-30" @click="userMenuOpen = false" />

                    <Transition name="fade">
                        <div
                            v-if="userMenuOpen"
                            class="absolute right-0 top-full z-40 mt-3 w-64 rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-800 dark:bg-gray-900"
                        >
                            <div class="border-b border-gray-100 px-2 pb-3 dark:border-gray-800">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">{{ user?.name }}</p>
                                <p class="truncate text-theme-xs text-gray-400">{{ user?.email }}</p>
                                <p v-if="user?.roles[0]" class="mt-0.5 truncate text-theme-xs text-gray-400">{{ user?.roles[0] }}</p>
                            </div>
                            <button
                                class="mt-2 flex w-full cursor-pointer items-center gap-3 rounded-lg px-2 py-2.5 text-sm font-medium text-gray-700 transition-colors hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                                @click="logout"
                            >
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="h-5 w-5 text-gray-500 dark:text-gray-400">
                                    <path stroke-linecap="round" stroke-linejoin="round" :d="icons.logout" />
                                </svg>
                                Cerrar sesión
                            </button>
                        </div>
                    </Transition>
                </div>
            </header>

            <!-- Flash error banner -->
            <Transition name="slide-down">
                <div v-if="page.props.flash.error" class="flex items-center gap-2 border-b border-error-200 bg-error-50 px-6 py-3 text-sm text-error-600 dark:border-error-500/30 dark:bg-error-500/15 dark:text-error-400">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" /></svg>
                    {{ page.props.flash.error }}
                </div>
            </Transition>

            <!-- Content -->
            <main class="flex-1 overflow-auto p-4 md:p-6">
                <div class="mx-auto w-full max-w-[1536px]">
                    <slot />
                </div>
            </main>
        </div>

        <!-- FAB: acceso rápido a los formularios de alta, visible en todas las secciones -->
        <FabSpeedDial v-if="hasPermission('observaciones.edit')" v-model:open="fabOpen" :actions="fabActions" />

        <!-- Avisos al entrar: reclamos del portal, casos propios y casos que se siguen -->
        <Modal :show="mostrarAvisos" size="lg" :title="tituloAvisos" @close="cerrarAvisos()">
            <template v-for="(bloque, i) in bloquesAvisos" :key="bloque.key">
                <p
                    class="mb-1 text-theme-xs font-medium uppercase tracking-wide text-gray-400"
                    :class="i > 0 ? 'mt-6 border-t border-gray-100 pt-5 dark:border-gray-800' : '-mt-2'"
                >
                    {{ bloque.titulo }}
                </p>
                <p class="mb-3 text-theme-sm text-gray-500 dark:text-gray-400">
                    {{ bloque.bajada }}
                </p>

                <ul class="divide-y divide-gray-100 dark:divide-gray-800">
                    <li v-for="o in bloque.items" :key="o.id">
                        <button
                            type="button"
                            class="flex w-full cursor-pointer items-start justify-between gap-4 py-3.5 text-left transition-colors hover:bg-gray-50 dark:hover:bg-white/[0.03]"
                            :class="esCritica(o.prioridad)
                                ? 'border-l-2 border-error-500 bg-error-50/40 pl-3 dark:bg-error-500/10'
                                : 'px-1'"
                            @click="cerrarAvisos(o.id)"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-gray-800 dark:text-white/90">
                                    <span
                                        v-if="esCritica(o.prioridad)"
                                        class="mr-1.5 rounded-full bg-error-500 px-1.5 py-0.5 text-[10px] font-bold uppercase leading-none text-white"
                                    >
                                        Crítica
                                    </span>
                                    {{ o.numero }} — {{ o.titulo }}
                                </p>
                                <p class="mt-0.5 text-theme-xs text-gray-500 dark:text-gray-400">
                                    {{ bloque.subtitulo(o) }}
                                </p>
                            </div>
                            <span class="shrink-0 pt-0.5 text-theme-xs text-gray-400">
                                {{ fechaCorta(o.created_at) }}
                            </span>
                        </button>
                    </li>
                </ul>
            </template>

            <div class="mt-5 flex justify-end gap-3 border-t border-gray-100 pt-4 dark:border-gray-800">
                <button
                    type="button"
                    class="cursor-pointer rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-sm font-medium text-gray-700 shadow-theme-xs transition hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-300 dark:hover:bg-white/[0.05]"
                    @click="cerrarAvisos()"
                >
                    Después los veo
                </button>
                <Link
                    :href="route('observaciones.index')"
                    class="cursor-pointer rounded-lg bg-brand-500 px-4 py-2.5 text-sm font-medium text-white shadow-theme-xs transition hover:bg-brand-600"
                    @click="cerrarAvisos()"
                >
                    Ver todos
                </Link>
            </div>
        </Modal>
    </div>
</template>

<style scoped>
.fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
.fade-enter-from, .fade-leave-to { opacity: 0; }

.slide-enter-active, .slide-leave-active { transition: all 0.2s; overflow: hidden; }
.slide-enter-from, .slide-leave-to { opacity: 0; width: 0; }

.slide-down-enter-active, .slide-down-leave-active { transition: all 0.2s; }
.slide-down-enter-from, .slide-down-leave-to { opacity: 0; transform: translateY(-8px); }

.toast-enter-active, .toast-leave-active { transition: opacity 0.2s, transform 0.2s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-8px); }
</style>
