const path = require('path');
const webpack = require('webpack');
const webpackConfig = require('@nextcloud/webpack-vue-config');

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
};

// Lazy chunks (file picker, highlight.js, Mermaid) load from the path set at
// runtime in src/publicPath.js. The content hash in the query string makes a
// new release fetch new chunks: Nextcloud's own ?v= cache-buster only covers
// the entry script.
webpackConfig.output.chunkFilename = 'markdownsite-[name].js?v=[contenthash]';

// Without these flags Vue, Vue Router and Pinia keep their devtools code in
// the production bundle.
webpackConfig.plugins.push(new webpack.DefinePlugin({
	__VUE_OPTIONS_API__: true,
	__VUE_PROD_DEVTOOLS__: false,
	__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: false,
}));

module.exports = webpackConfig;
