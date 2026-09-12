/**
 * Detect when the paywall submits a signed x402 retry so the pay page can
 * leave “Confirm in your wallet…” as soon as the shopper has signed.
 */

const PAYMENT_SIGNATURE_HEADERS = [ 'PAYMENT-SIGNATURE', 'X-PAYMENT' ];

/**
 * @param {unknown} input  Fetch input (URL or Request).
 * @param {Object}  [init] Fetch init, when the first argument is a URL.
 * @return {Headers} Merged headers from the Request and init.
 */
export function headersFromFetchArgs( input, init ) {
	const headers = new Headers();
	if ( typeof Request !== 'undefined' && input instanceof Request ) {
		input.headers.forEach( ( value, key ) => {
			headers.set( key, value );
		} );
	}
	if ( init?.headers ) {
		new Headers( init.headers ).forEach( ( value, key ) => {
			headers.set( key, value );
		} );
	}
	return headers;
}

/**
 * True when this fetch already carries an x402 payment payload (v1 or v2).
 *
 * @param {unknown} input  Fetch input (URL or Request).
 * @param {Object}  [init] Fetch init, when the first argument is a URL.
 * @return {boolean} Whether a payment signature header is present.
 */
export function requestHasPaymentSignature( input, init ) {
	const headers = headersFromFetchArgs( input, init );
	return PAYMENT_SIGNATURE_HEADERS.some( ( name ) => headers.has( name ) );
}

/**
 * Wrap `fetch` used by PaywallProvider. Calls `onPaymentSubmitted` the moment
 * a signed retry is sent (before Ax402 verify/settle returns).
 *
 * @param {Function} [baseFetch]                Fetch implementation. Defaults to global fetch.
 * @param {Object}   [hooks]
 * @param {Function} [hooks.onPaymentSubmitted]
 * @param {Function} [hooks.onPaymentFailed]
 * @return {Function} Fetch-compatible wrapper.
 */
export function wrapPaywallFetch( baseFetch, hooks = {} ) {
	const fetchImpl = baseFetch || globalThis.fetch.bind( globalThis );
	const { onPaymentSubmitted, onPaymentFailed } = hooks;

	return async function paywallFetch( input, init ) {
		const signed = requestHasPaymentSignature( input, init );
		if ( signed ) {
			onPaymentSubmitted?.();
		}
		try {
			const response = await fetchImpl( input, init );
			if ( signed && ! response?.ok ) {
				onPaymentFailed?.();
			}
			return response;
		} catch ( error ) {
			if ( signed ) {
				onPaymentFailed?.();
			}
			throw error;
		}
	};
}
