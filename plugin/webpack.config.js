const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );
const webpack = require( 'webpack' );

const hederaStub = path.resolve( __dirname, 'src/stubs/x402-hedera.js' );
const hederaWalletStub = path.resolve( __dirname, 'src/stubs/hedera-wallet.js' );

module.exports = {
	...defaultConfig,
	entry: {
		'pay-page': path.resolve( __dirname, 'src/pay-page/index.js' ),
		blocks: path.resolve( __dirname, 'src/blocks/index.js' ),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname, 'build' ),
	},
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...( defaultConfig.resolve && defaultConfig.resolve.alias
				? defaultConfig.resolve.alias
				: {} ),
			// Keep Hedera out of the WooCommerce pay-page bundle (EVM-only).
			// `$` = exact match so subpath imports don't append to the .js file.
			'@x402/hedera$': hederaStub,
			'@x402/hedera/exact/client$': hederaStub,
			'@hiero-ledger/sdk$': hederaStub,
			'@hiero-ledger/proto$': hederaStub,
		},
	},
	plugins: [
		...( defaultConfig.plugins || [] ),
		new webpack.ProvidePlugin( {
			React: 'react',
		} ),
		new webpack.NormalModuleReplacementPlugin(
			/[@\\/]ax402[\\/]react-paywall[\\/]dist[\\/]hederaWallet\.js$/,
			hederaWalletStub
		),
		new webpack.IgnorePlugin( {
			resourceRegExp: /^@hashgraph\/hedera-wallet-connect$/,
		} ),
	],
};
