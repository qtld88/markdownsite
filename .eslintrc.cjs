module.exports = {
	root: true,
	env: { browser: true, es2022: true, node: true },
	parserOptions: { ecmaVersion: 2022, sourceType: 'module' },
	extends: ['eslint:recommended', 'plugin:vue/vue3-essential'],
	globals: { __webpack_public_path__: 'writable' },
	rules: {},
}
