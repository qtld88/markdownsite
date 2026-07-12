const path = require('path');
const webpack = require('webpack');
const webpackConfig = require('@nextcloud/webpack-vue-config');

webpackConfig.entry = {
	main: path.join(__dirname, 'src', 'main.js'),
};

// Force everything (vendor splits AND async import() chunks, e.g. the
// @nextcloud/dialogs FilePicker) into a single main bundle. Nextcloud serves
// the registered main.js, but dynamically-requested chunk files are not
// reliably served across instances — a single bundle avoids that entirely.
webpackConfig.optimization = {
	...(webpackConfig.optimization || {}),
	splitChunks: false,
	runtimeChunk: false,
};
webpackConfig.plugins = webpackConfig.plugins || [];
webpackConfig.plugins.push(
	new webpack.optimize.LimitChunkCountPlugin({ maxChunks: 1 }),
);

module.exports = webpackConfig;
