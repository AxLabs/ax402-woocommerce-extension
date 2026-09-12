/**
 * Timeouts and human-readable wallet errors for the pay page.
 */

export const WALLET_READ_TIMEOUT_MS = 20000;
export const WALLET_SWITCH_TIMEOUT_MS = 60000;
export const WALLET_CONNECT_TIMEOUT_MS = 60000;

/**
 * Reject if `promise` does not settle before `ms`.
 *
 * @param {Promise<any>} promise
 * @param {number}       ms
 * @param {string}       message
 * @return {Promise<any>} The settled value of `promise`.
 */
export function withTimeout( promise, ms, message ) {
	let timer;
	const timeout = new Promise( ( _, reject ) => {
		timer = setTimeout( () => {
			reject( new Error( message ) );
		}, ms );
	} );
	return Promise.race( [ promise, timeout ] ).finally( () => {
		clearTimeout( timer );
	} );
}

/**
 * Map wallet / RPC exceptions to a short shopper-facing sentence.
 *
 * @param {unknown} error
 * @param {string}  [fallback]
 * @return {string} Shopper-facing error text.
 */
export function humanizeWalletError( error, fallback ) {
	const fallbackText =
		fallback || 'Something went wrong with your wallet. Try again.';
	if ( error === null || error === undefined ) {
		return fallbackText;
	}

	const code =
		typeof error === 'object' && error && 'code' in error
			? error.code
			: null;
	const msg = String(
		typeof error === 'object' && error && 'message' in error
			? error.message
			: error
	);

	if (
		code === 4001 ||
		/user rejected|denied by user|rejected the request/i.test( msg )
	) {
		return 'You declined the request in your wallet.';
	}
	if (
		code === -32002 ||
		/already pending|request already pending|already processing/i.test(
			msg
		)
	) {
		return 'Your wallet already has a pending request. Open it to continue, then try again.';
	}
	if ( /timed out|timeout/i.test( msg ) ) {
		return msg;
	}
	if ( /failed to fetch|networkerror|load failed|econnreset/i.test( msg ) ) {
		return 'Network request failed. Check your connection and try again.';
	}

	const trimmed = msg.trim();
	return trimmed !== '' ? trimmed : fallbackText;
}

/**
 * Copy while we wait for chain id or token balance.
 *
 * @param {{ phase?: string, option?: { symbol?: string, networkLabel?: string } }} args
 * @return {{ title: string, description: string }} Title and body for the spinner step.
 */
export function walletCheckCopy( args = {} ) {
	const phase = args.phase || 'network';
	const option = args.option || {};
	const symbol = option.symbol || 'token';
	const network = option.networkLabel || 'the required network';

	if ( phase === 'balance' ) {
		return {
			title: 'Checking your balance…',
			description: `Reading your ${ symbol } balance on ${ network }. This can take a few seconds.`,
		};
	}

	return {
		title: 'Checking your wallet…',
		description: `Making sure your wallet is connected to ${ network }.`,
	};
}
