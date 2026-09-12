import { describe, expect, it, vi } from 'vitest';
import {
	requestHasPaymentSignature,
	wrapPaywallFetch,
} from '../../plugin/src/pay-page/payment-fetch.js';

describe( 'requestHasPaymentSignature', () => {
	it( 'is false for unsigned inspect requests', () => {
		expect(
			requestHasPaymentSignature( 'https://gateway.example/pay', {
				headers: { Accept: 'application/json' },
			} )
		).toBe( false );
	} );

	it( 'detects PAYMENT-SIGNATURE on init headers', () => {
		expect(
			requestHasPaymentSignature( 'https://gateway.example/pay', {
				headers: { 'PAYMENT-SIGNATURE': 'sig' },
			} )
		).toBe( true );
	} );

	it( 'detects X-PAYMENT case-insensitively', () => {
		expect(
			requestHasPaymentSignature( 'https://gateway.example/pay', {
				headers: { 'x-payment': 'legacy' },
			} )
		).toBe( true );
	} );

	it( 'reads headers from a Request object', () => {
		const request = new Request( 'https://gateway.example/pay', {
			headers: { 'PAYMENT-SIGNATURE': 'sig' },
		} );
		expect( requestHasPaymentSignature( request ) ).toBe( true );
	} );
} );

describe( 'wrapPaywallFetch', () => {
	it( 'notifies on submit before the signed fetch settles', async () => {
		const order = [];
		let release;
		const pending = new Promise( ( resolve ) => {
			release = resolve;
		} );
		const fetchImpl = vi.fn( () => pending );
		const wrapped = wrapPaywallFetch( fetchImpl, {
			onPaymentSubmitted: () => order.push( 'submitted' ),
			onPaymentFailed: () => order.push( 'failed' ),
		} );

		const resultPromise = wrapped( 'https://gateway.example/pay', {
			headers: { 'PAYMENT-SIGNATURE': 'sig' },
		} );
		expect( order ).toEqual( [ 'submitted' ] );

		release( { ok: true, status: 200 } );
		await expect( resultPromise ).resolves.toMatchObject( { status: 200 } );
		expect( order ).toEqual( [ 'submitted' ] );
	} );

	it( 'does not notify on the unsigned 402 inspect', async () => {
		const onPaymentSubmitted = vi.fn();
		const wrapped = wrapPaywallFetch(
			vi.fn( async () => ( { ok: false, status: 402 } ) ),
			{ onPaymentSubmitted }
		);

		await wrapped( 'https://gateway.example/pay' );
		expect( onPaymentSubmitted ).not.toHaveBeenCalled();
	} );

	it( 'notifies failure when the signed retry is not ok', async () => {
		const onPaymentFailed = vi.fn();
		const wrapped = wrapPaywallFetch(
			vi.fn( async () => ( { ok: false, status: 402 } ) ),
			{
				onPaymentSubmitted: vi.fn(),
				onPaymentFailed,
			}
		);

		await wrapped( new Request( 'https://gateway.example/pay', {
			headers: { 'PAYMENT-SIGNATURE': 'sig' },
		} ) );
		expect( onPaymentFailed ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'notifies failure when the signed retry throws', async () => {
		const onPaymentFailed = vi.fn();
		const wrapped = wrapPaywallFetch(
			vi.fn( async () => {
				throw new Error( 'network down' );
			} ),
			{ onPaymentFailed }
		);

		await expect(
			wrapped( 'https://gateway.example/pay', {
				headers: { 'PAYMENT-SIGNATURE': 'sig' },
			} )
		).rejects.toThrow( /network down/ );
		expect( onPaymentFailed ).toHaveBeenCalledTimes( 1 );
	} );
} );
