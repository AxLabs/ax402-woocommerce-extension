/**
 * Pay-page readiness helpers (network match + balance gate).
 */

/**
 * @param {string|null|undefined} walletChainIdHex
 * @param {string|null|undefined} requiredChainIdHex
 * @return {boolean} Whether both chain ids are present and equal (case-insensitive).
 */
export function networkMatches( walletChainIdHex, requiredChainIdHex ) {
	if ( ! walletChainIdHex || ! requiredChainIdHex ) {
		return false;
	}
	return (
		String( walletChainIdHex ).toLowerCase() ===
		String( requiredChainIdHex ).toLowerCase()
	);
}

/**
 * Compare atomic integer strings (decimal digits).
 *
 * @param {string|null|undefined} balanceAtomic
 * @param {string|null|undefined} requiredAtomic
 * @return {boolean} Whether balanceAtomic is at least requiredAtomic.
 */
export function hasSufficientBalance( balanceAtomic, requiredAtomic ) {
	if ( balanceAtomic === null || balanceAtomic === undefined ) {
		return false;
	}
	if ( requiredAtomic === null || requiredAtomic === undefined ) {
		return false;
	}
	const balance = String( balanceAtomic );
	const required = String( requiredAtomic );
	// Atomic amounts are decimal digit strings (not hex).
	if ( ! /^\d+$/.test( balance ) || ! /^\d+$/.test( required ) ) {
		return false;
	}
	const bal = BigInt( balance );
	const need = BigInt( required );
	return bal >= need;
}

/**
 * True when we have a real on-chain atomic balance (not "still loading").
 *
 * @param {string|null|undefined} balanceAtomic
 * @return {boolean} Whether the value is a decimal integer string.
 */
export function isKnownAtomicBalance( balanceAtomic ) {
	if ( balanceAtomic === null || balanceAtomic === undefined ) {
		return false;
	}
	return /^\d+$/.test( String( balanceAtomic ) );
}

/**
 * Insufficient-balance copy is only for a confirmed shortfall on the right
 * network, before the shopper pays or switches networks.
 *
 * @param {{
 *   walletStatus?: string,
 *   networkOk?: boolean,
 *   balanceAtomic?: string|null,
 *   requiredAtomic?: string|null,
 *   paymentSubmitted?: boolean,
 * }} state
 * @return {boolean} Whether the insufficient-balance step should render.
 */
export function shouldShowInsufficientBalance( state = {} ) {
	if ( state.paymentSubmitted ) {
		return false;
	}
	if ( state.walletStatus !== 'ready' ) {
		return false;
	}
	if ( ! state.networkOk ) {
		return false;
	}
	if ( ! isKnownAtomicBalance( state.balanceAtomic ) ) {
		return false;
	}
	return ! hasSufficientBalance( state.balanceAtomic, state.requiredAtomic );
}

/**
 * @param {string} hexQuantity
 * @return {string} Decimal atomic string.
 */
export function hexQuantityToDecimal( hexQuantity ) {
	if (
		hexQuantity === null ||
		hexQuantity === undefined ||
		hexQuantity === ''
	) {
		return '0';
	}
	const hex = String( hexQuantity ).startsWith( '0x' )
		? String( hexQuantity )
		: `0x${ hexQuantity }`;
	try {
		return BigInt( hex ).toString( 10 );
	} catch {
		return '0';
	}
}

/**
 * Encode ERC-20 balanceOf(address) calldata.
 *
 * @param {string} ownerAddress
 * @return {string} Hex-encoded balanceOf calldata.
 */
export function encodeBalanceOf( ownerAddress ) {
	const addr = String( ownerAddress || '' )
		.replace( /^0x/i, '' )
		.toLowerCase()
		.padStart( 64, '0' );
	return `0x70a08231${ addr }`;
}

/**
 * @param {string} amount
 * @param {string} symbol
 * @return {string} Display label with amount and symbol.
 */
export function formatPayLabel( amount, symbol ) {
	const a = amount || '';
	const s = symbol || 'token';
	return `${ a } ${ s }`.trim();
}

/**
 * Trim long decimal strings for UI (keeps up to maxFrac significant fraction digits).
 *
 * @param {string|number|null|undefined} value
 * @param {number}                       [maxFrac=8]
 * @return {string} Trimmed token amount for display.
 */
export function formatTokenAmount( value, maxFrac = 8 ) {
	if ( value === null || value === undefined || value === '' ) {
		return '';
	}
	const raw = String( value ).trim();
	if ( ! /^-?\d+(\.\d+)?$/.test( raw ) ) {
		return raw;
	}
	const neg = raw.startsWith( '-' );
	const unsigned = neg ? raw.slice( 1 ) : raw;
	const [ whole, frac = '' ] = unsigned.split( '.' );
	if ( frac === '' ) {
		return `${ neg ? '-' : '' }${ whole }`;
	}
	const trimmed = frac
		.slice( 0, Math.max( 0, maxFrac ) )
		.replace( /0+$/, '' );
	if ( trimmed === '' ) {
		return `${ neg ? '-' : '' }${ whole }`;
	}
	return `${ neg ? '-' : '' }${ whole }.${ trimmed }`;
}

/**
 * Settlement options store tokens-per-1-USD in `rate`.
 *
 * @param {string|number|null|undefined} tokensPerUsd
 * @param {string}                       symbol
 * @return {string} Human-readable USD exchange rate line.
 */
export function formatUsdExchangeRate( tokensPerUsd, symbol ) {
	const amount = formatTokenAmount( tokensPerUsd, 8 );
	const s = symbol || 'TOKEN';
	if ( ! amount ) {
		return '';
	}
	return `1 USD = ${ amount } ${ s }`;
}
