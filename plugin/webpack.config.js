const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
const webpack = require('webpack');

module.exports = {
	...defaultConfig,
	entry: {
		'pay-page': path.resolve(__dirname, 'src/pay-page/index.js'),
		blocks: path.resolve(__dirname, 'src/blocks/index.js'),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve(__dirname, 'build'),
	},
	plugins: [
		...(defaultConfig.plugins || []),
		new webpack.ProvidePlugin({
			React: 'react',
		}),
	],
};
