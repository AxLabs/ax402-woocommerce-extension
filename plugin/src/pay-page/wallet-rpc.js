/**
 * Lightweight EIP-1193 helpers for network switch + balances.
 */

import { encodeBalanceOf, hexQuantityToDecimal } from './readiness';

/**
 * @return {Object|null} Injected EIP-1193 provider, if any.
 */
export function getEthereumProvider() {
	if ( typeof window === 'undefined' ) {
		return null;
	}
	return window.ethereum || null;
}

/**
 * @param {Object} provider
 * @return {Promise<string|null>} Wallet chain id hex, or null.
 */
export async function getWalletChainId( provider ) {
	if ( ! provider?.request ) {
		return null;
	}
	const chainId = await provider.request( { method: 'eth_chainId' } );
	return typeof chainId === 'string' ? chainId : null;
}

/**
 * @param {Object}                                                                                    provider
 * @param {{ chainIdHex: string, networkLabel?: string, rpcUrl?: string, blockExplorerUrl?: string }} chain
 */
export async function switchOrAddChain( provider, chain ) {
	if ( ! provider?.request ) {
		throw new Error( 'No wallet provider' );
	}
	const chainId = chain.chainIdHex;
	try {
		await provider.request( {
			method: 'wallet_switchEthereumChain',
			params: [ { chainId } ],
		} );
		return;
	} catch ( error ) {
		const code = error && typeof error === 'object' ? error.code : null;
		if ( code !== 4902 && code !== -32603 ) {
			throw error;
		}
	}

	const rpcUrl = chain.rpcUrl || '';
	if ( ! rpcUrl ) {
		throw new Error(
			`Chain ${
				chain.networkLabel || chainId
			} is not in your wallet. Add it, then retry.`
		);
	}

	await provider.request( {
		method: 'wallet_addEthereumChain',
		params: [
			{
				chainId,
				chainName: chain.networkLabel || `Chain ${ chainId }`,
				nativeCurrency: {
					name: 'Ether',
					symbol: 'ETH',
					decimals: 18,
				},
				rpcUrls: [ rpcUrl ],
				blockExplorerUrls: chain.blockExplorerUrl
					? [ chain.blockExplorerUrl ]
					: undefined,
			},
		],
	} );
}

/**
 * @param {Object}                                                                provider
 * @param {{ asset: string, isNative?: boolean, rpcUrl?: string, owner: string }} opts
 * @return {Promise<string>} Decimal atomic balance.
 */
export async function fetchTokenBalance( provider, opts ) {
	const owner = opts.owner;
	if ( ! provider?.request || ! owner ) {
		return '0';
	}

	if ( opts.isNative ) {
		const hex = await provider.request( {
			method: 'eth_getBalance',
			params: [ owner, 'latest' ],
		} );
		return hexQuantityToDecimal( hex );
	}

	const data = encodeBalanceOf( owner );
	const hex = await provider.request( {
		method: 'eth_call',
		params: [ { to: opts.asset, data }, 'latest' ],
	} );
	return hexQuantityToDecimal( hex );
}

/**
 * Format atomic decimal for display with token decimals.
 *
 * @param {string} atomic
 * @param {number} decimals
 * @return {string} Human-readable token amount.
 */
export function formatAtomicAmount( atomic, decimals ) {
	const d = Number.isFinite( decimals ) ? decimals : 6;
	const raw = String( atomic || '0' );
	if ( ! /^\d+$/.test( raw ) ) {
		return '0';
	}
	const padded = raw.padStart( d + 1, '0' );
	const whole = padded.slice( 0, -d ).replace( /^0+(?=\d)/, '' ) || '0';
	const frac = padded.slice( -d ).replace( /0+$/, '' );
	return frac ? `${ whole }.${ frac }` : whole;
}
