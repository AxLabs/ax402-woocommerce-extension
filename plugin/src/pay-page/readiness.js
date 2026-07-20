/**
 * Pay-page readiness helpers (network match + balance gate).
 */

/**
 * @param {string|null|undefined} walletChainIdHex
 * @param {string|null|undefined} requiredChainIdHex
 * @return {boolean}
 */
export function networkMatches(walletChainIdHex, requiredChainIdHex) {
	if (!walletChainIdHex || !requiredChainIdHex) {
		return false;
	}
	return (
		String(walletChainIdHex).toLowerCase() ===
		String(requiredChainIdHex).toLowerCase()
	);
}

/**
 * Compare atomic integer strings (decimal digits).
 *
 * @param {string|null|undefined} balanceAtomic
 * @param {string|null|undefined} requiredAtomic
 * @return {boolean}
 */
export function hasSufficientBalance(balanceAtomic, requiredAtomic) {
	if (balanceAtomic == null || requiredAtomic == null) {
		return false;
	}
	const balance = String(balanceAtomic);
	const required = String(requiredAtomic);
	// Atomic amounts are decimal digit strings (not hex).
	if (!/^\d+$/.test(balance) || !/^\d+$/.test(required)) {
		return false;
	}
	const bal = BigInt(balance);
	const need = BigInt(required);
	return bal >= need;
}

/**
 * @param {string} hexQuantity
 * @return {string} decimal atomic string
 */
export function hexQuantityToDecimal(hexQuantity) {
	if (hexQuantity == null || hexQuantity === '') {
		return '0';
	}
	const hex = String(hexQuantity).startsWith('0x')
		? String(hexQuantity)
		: `0x${hexQuantity}`;
	try {
		return BigInt(hex).toString(10);
	} catch {
		return '0';
	}
}

/**
 * Encode ERC-20 balanceOf(address) calldata.
 *
 * @param {string} ownerAddress
 * @return {string}
 */
export function encodeBalanceOf(ownerAddress) {
	const addr = String(ownerAddress || '')
		.replace(/^0x/i, '')
		.toLowerCase()
		.padStart(64, '0');
	return `0x70a08231${addr}`;
}

/**
 * @param {string} amount
 * @param {string} symbol
 * @return {string}
 */
export function formatPayLabel(amount, symbol) {
	const a = amount || '';
	const s = symbol || 'token';
	return `${a} ${s}`.trim();
}
