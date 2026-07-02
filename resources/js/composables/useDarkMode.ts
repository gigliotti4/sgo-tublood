import { ref } from 'vue'

const isDark = ref(typeof document !== 'undefined' && document.documentElement.classList.contains('dark'))

function apply(dark: boolean) {
    document.documentElement.classList.toggle('dark', dark)
    localStorage.setItem('theme', dark ? 'dark' : 'light')
}

export function useDarkMode() {
    const toggleTheme = () => {
        isDark.value = !isDark.value
        apply(isDark.value)
    }

    return { isDark, toggleTheme }
}
