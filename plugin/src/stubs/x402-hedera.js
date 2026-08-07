/**
 * EVM-only stubs for Hedera modules pulled in by @ax402/react-paywall@0.0.5.
 * WooCommerce pay page does not support Hedera; excluding those deps keeps the
 * pay-page bundle small enough for WordPress.org.
 */

function unsupported( name ) {
	return function unsupportedHedera() {
		throw new Error(
			`${ name } is unavailable in this WooCommerce build (EVM wallets only).`
		);
	};
}

export class ExactHederaScheme {
	constructor() {
		unsupported( 'ExactHederaScheme' )();
	}
}

export const AccountId = {};
export const Hbar = {};
export const TokenId = {};
export const TransactionId = {};
export const TransferTransaction = {};
export const PrivateKey = {};
export const createHederaClient = unsupported( 'createHederaClient' );
export const createClientHederaSigner = unsupported(
	'createClientHederaSigner'
);
