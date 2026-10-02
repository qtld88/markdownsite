const path = require('path');
const webpackConfig = require('@nextcloud/webpack-vue-config');

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
};

// Lazy chunks (file picker, highlight.js, Mermaid) load from the path set at
// runtime in src/publicPath.js. The content hash in the query string makes a
// new release fetch new chunks: Nextcloud's own ?v= cache-buster only covers
// the entry script.
webpackConfig.output.chunkFilename = 'markdownsite-[name].js?v=[contenthash]';

module.exports = webpackConfig;
