/**
 * Hedera Mirror Node balance helpers for the pay page.
 */

export function isHederaOption( option ) {
	if ( ! option ) {
		return false;
	}
	if ( option.isHedera ) {
		return true;
	}
	const network = String( option.network || '' )
		.trim()
		.toLowerCase();
	return network.startsWith( 'hedera:' );
}

export function shortHederaAccount( accountId ) {
	if ( ! accountId ) {
		return '';
	}
	const s = String( accountId );
	if ( s.length <= 12 ) {
		return s;
	}
	return `${ s.slice( 0, 6 ) }…${ s.slice( -4 ) }`;
}

/**
 * Fetch HBAR or HTS token balance in atomic units (tinybars / token base units).
 *
 * @param {Object}  args
 * @param {string}  args.mirrorBase Mirror Node REST base URL
 * @param {string}  args.accountId  Hedera account id
 * @param {string}  args.asset      Token id or native marker
 * @param {boolean} args.isNative   Whether the asset is native HBAR
 * @return {Promise<string|null>} Decimal string of atomic balance
 */
export async function fetchHederaBalance( {
	mirrorBase,
	accountId,
	asset,
	isNative,
} ) {
	if ( ! mirrorBase || ! accountId ) {
		return null;
	}
	const base = String( mirrorBase ).replace( /\/$/, '' );

	if ( isNative ) {
		const res = await fetch(
			`${ base }/api/v1/accounts/${ encodeURIComponent( accountId ) }`
		);
		if ( ! res.ok ) {
			throw new Error( `Mirror account ${ res.status }` );
		}
		const data = await res.json();
		const bal = data?.balance?.balance;
		if ( bal === undefined || bal === null ) {
			return null;
		}
		return String( bal );
	}

	const tokenId = String( asset || '' ).trim();
	if ( ! tokenId ) {
		return null;
	}
	const res = await fetch(
		`${ base }/api/v1/accounts/${ encodeURIComponent(
			accountId
		) }/tokens?limit=100`
	);
	if ( ! res.ok ) {
		throw new Error( `Mirror tokens ${ res.status }` );
	}
	const data = await res.json();
	const rows = Array.isArray( data?.tokens ) ? data.tokens : [];
	const row = rows.find( ( t ) => String( t?.token_id || '' ) === tokenId );
	if ( ! row || row.balance === undefined || row.balance === null ) {
		return '0';
	}
	return String( row.balance );
}
