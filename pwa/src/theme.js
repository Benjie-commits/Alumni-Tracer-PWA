/**
 * Light / dark switch. With no choice saved the app follows the phone's own setting; once the user
 * picks one it is kept on this device (it is a display preference, not personal data).
 * index.html applies the saved choice before the first paint, so there is no flash of the wrong theme.
 */
const KEY = 'sunates.theme'

export function currentTheme(root = document.documentElement, media = globalThis.matchMedia) {
  const chosen = root.dataset.theme
  if (chosen === 'light' || chosen === 'dark') return chosen
  return media?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

export function setTheme(theme, root = document.documentElement) {
  root.dataset.theme = theme
  try {
    localStorage.setItem(KEY, theme)
  } catch {
    /* storage blocked: the choice lasts for this visit only */
  }
}

export function nextTheme(theme) {
  return theme === 'dark' ? 'light' : 'dark'
}
