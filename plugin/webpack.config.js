const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );
const webpack = require( 'webpack' );

const hederaStub = path.resolve( __dirname, 'src/stubs/x402-hedera.js' );
const hederaWalletStub = path.resolve( __dirname, 'src/stubs/hedera-wallet.js' );
const evmOnly = process.env.AX402_EVM_ONLY === '1';

const hederaResolve = evmOnly
	? {
			alias: {
				...( defaultConfig.resolve && defaultConfig.resolve.alias
					? defaultConfig.resolve.alias
					: {} ),
				// Keep Hedera out of the WooCommerce pay-page bundle (lean build).
				'@x402/hedera$': hederaStub,
				'@x402/hedera/exact/client$': hederaStub,
				'@hiero-ledger/sdk$': hederaStub,
				'@hiero-ledger/proto$': hederaStub,
			},
	  }
	: {
			alias: {
				...( defaultConfig.resolve && defaultConfig.resolve.alias
					? defaultConfig.resolve.alias
					: {} ),
			},
	  };

const hederaPlugins = evmOnly
	? [
			new webpack.NormalModuleReplacementPlugin(
				/[@\\/]ax402[\\/]react-paywall[\\/]dist[\\/]hederaWallet\.js$/,
				hederaWalletStub
			),
			new webpack.IgnorePlugin( {
				resourceRegExp: /^@hashgraph\/hedera-wallet-connect$/,
			} ),
	  ]
	: [];

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
		...hederaResolve,
	},
	plugins: [
		...( defaultConfig.plugins || [] ),
		new webpack.ProvidePlugin( {
			React: 'react',
		} ),
		...hederaPlugins,
	],
};
