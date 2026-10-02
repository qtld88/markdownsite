/**
 * True when Nextcloud shows its dark theme. The body's data-themes lists the
 * enabled themes ("dark", "dark-highcontrast", "light", …); "default" means
 * "follow the system", which the prefers-color-scheme query decides.
 */
export function isDarkTheme(body = document.body, matchMedia = (query) => window.matchMedia?.(query)) {
	const themes = body?.dataset?.themes || ''
	if (themes.includes('dark')) {
		return true
	}
	if (themes.includes('light')) {
		return false
	}
	return !!matchMedia?.('(prefers-color-scheme: dark)')?.matches
}
