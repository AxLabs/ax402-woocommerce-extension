/**
 * Stub for @ax402/react-paywall/dist/hederaWallet.js (EVM-only build).
 */

function unsupported( name ) {
	return function unsupportedHedera() {
		throw new Error(
			`${ name } is unavailable in this WooCommerce build (EVM wallets only).`
		);
	};
}

export const parseHederaPrivateKey = unsupported( 'parseHederaPrivateKey' );
export const createPrivateKeyHederaSigner = unsupported(
	'createPrivateKeyHederaSigner'
);
export const createWalletConnectHederaSigner = unsupported(
	'createWalletConnectHederaSigner'
);
export const connectHederaWalletConnect = unsupported(
	'connectHederaWalletConnect'
);
