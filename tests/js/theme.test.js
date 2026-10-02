import { describe, expect, it } from 'vitest'
import { isDarkTheme } from '../../src/services/theme.js'

const body = (themes) => ({ dataset: themes === undefined ? {} : { themes } })
const media = (dark) => () => ({ matches: dark })

describe('isDarkTheme', () => {
	it('follows an explicit dark or light theme', () => {
		expect(isDarkTheme(body('dark'), media(false))).toBe(true)
		expect(isDarkTheme(body('dark-highcontrast'), media(false))).toBe(true)
		expect(isDarkTheme(body('light'), media(true))).toBe(false)
	})

	it('follows the system preference for the default theme', () => {
		expect(isDarkTheme(body('default'), media(true))).toBe(true)
		expect(isDarkTheme(body('default'), media(false))).toBe(false)
		expect(isDarkTheme(body(undefined), media(true))).toBe(true)
	})
})
