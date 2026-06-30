<script setup lang="ts">
import { Link } from '@inertiajs/vue3'

interface Props {
    title: string
    description: string
    icon: string
    href: string
    color?: 'primary' | 'success' | 'warning' | 'info' | 'danger'
    external?: boolean
}

const props = withDefaults(defineProps<Props>(), {
    color: 'primary',
    external: false,
})

const colorMap = {
    primary: { bg: 'bg-[#2a3182]',    hover: 'group-hover:bg-[#1e2566]', icon: 'text-white', soft: 'bg-white/15' },
    success: { bg: 'bg-emerald-600',   hover: 'group-hover:bg-emerald-700', icon: 'text-white', soft: 'bg-white/15' },
    warning: { bg: 'bg-amber-500',     hover: 'group-hover:bg-amber-600',   icon: 'text-white', soft: 'bg-white/15' },
    info:    { bg: 'bg-sky-500',       hover: 'group-hover:bg-sky-600',     icon: 'text-white', soft: 'bg-white/15' },
    danger:  { bg: 'bg-red-500',       hover: 'group-hover:bg-red-600',     icon: 'text-white', soft: 'bg-white/15' },
}

const colors = colorMap[props.color]

const icons: Record<string, string> = {
    'user-plus':  'M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z',
    shield:       'M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z',
    chart:        'M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z',
    cog:          'M10.343 3.94c.09-.542.56-.94 1.11-.94h1.093c.55 0 1.02.398 1.11.94l.149.894c.07.424.384.764.78.93.398.164.855.142 1.205-.108l.737-.527a1.125 1.125 0 011.45.12l.773.774c.39.389.44 1.002.12 1.45l-.527.737c-.25.35-.272.806-.107 1.204.165.397.505.71.93.78l.893.15c.543.09.94.56.94 1.109v1.094c0 .55-.397 1.02-.94 1.11l-.893.149c-.425.07-.765.383-.93.78-.165.398-.143.854.107 1.204l.527.738c.32.447.269 1.06-.12 1.45l-.774.773a1.125 1.125 0 01-1.449.12l-.738-.527c-.35-.25-.806-.272-1.203-.107-.397.165-.71.505-.781.929l-.149.894c-.09.542-.56.94-1.11.94h-1.094c-.55 0-1.019-.398-1.11-.94l-.148-.894c-.071-.424-.384-.764-.781-.93-.398-.164-.854-.142-1.204.108l-.738.527c-.447.32-1.06.269-1.45-.12l-.773-.774a1.125 1.125 0 01-.12-1.45l.527-.737c.25-.35.273-.806.108-1.204-.165-.397-.505-.71-.93-.78l-.894-.15c-.542-.09-.94-.56-.94-1.109v-1.094c0-.55.398-1.02.94-1.11l.894-.149c.424-.07.765-.383.93-.78.165-.398.143-.854-.107-1.204l-.527-.738a1.125 1.125 0 01.12-1.45l.773-.773a1.125 1.125 0 011.45-.12l.737.527c.35.25.807.272 1.204.107.397-.165.71-.505.78-.929l.15-.894z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    users:        'M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z',
    document:     'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
    arrow:        'M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3',
}
</script>

<template>
    <component
        :is="external ? 'a' : Link"
        :href="href"
        class="group block bg-white rounded-xl border border-slate-200 p-5 shadow-sm hover:shadow-md hover:-translate-y-0.5 transition-all duration-200"
    >
        <div class="flex items-start justify-between">
            <div :class="['w-10 h-10 rounded-xl flex items-center justify-center transition-colors', colors.bg, colors.hover]">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="w-5 h-5 text-white">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="icons[icon] ?? icons.arrow" />
                </svg>
            </div>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" class="w-4 h-4 text-slate-300 group-hover:text-slate-500 group-hover:translate-x-0.5 transition-all">
                <path stroke-linecap="round" stroke-linejoin="round" :d="icons.arrow" />
            </svg>
        </div>
        <div class="mt-3">
            <p class="text-sm font-semibold text-slate-800 group-hover:text-[#2a3182] transition-colors">{{ title }}</p>
            <p class="text-xs text-slate-400 mt-0.5 leading-relaxed">{{ description }}</p>
        </div>
    </component>
</template>
