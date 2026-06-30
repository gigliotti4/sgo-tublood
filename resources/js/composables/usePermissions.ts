import { usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import type { PageProps } from '@/types'

export function usePermissions() {
    const page = usePage<PageProps>()

    const user = computed(() => page.props.auth.user)

    const hasPermission = (permission: string): boolean => {
        return user.value?.permissions.includes(permission) ?? false
    }

    const hasRole = (role: string): boolean => {
        return user.value?.roles.includes(role) ?? false
    }

    const hasAnyPermission = (permissions: string[]): boolean => {
        return permissions.some(p => hasPermission(p))
    }

    const hasAllPermissions = (permissions: string[]): boolean => {
        return permissions.every(p => hasPermission(p))
    }

    const isSuperAdmin = computed(() => hasRole('super-admin'))

    return {
        user,
        hasPermission,
        hasRole,
        hasAnyPermission,
        hasAllPermissions,
        isSuperAdmin,
    }
}
