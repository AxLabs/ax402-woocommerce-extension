import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
	humanizeWalletError,
	walletCheckCopy,
	withTimeout,
} from '../../plugin/src/pay-page/wallet-errors.js';

describe('withTimeout', () => {
	beforeEach(() => {
		vi.useFakeTimers();
	});
	afterEach(() => {
		vi.useRealTimers();
	});

	it('resolves when the promise wins', async () => {
		const promise = withTimeout(
			Promise.resolve('ok'),
			1000,
			'Timed out'
		);
		await expect(promise).resolves.toBe('ok');
	});

	it('rejects with the timeout message', async () => {
		const hung = new Promise(() => {});
		const promise = withTimeout(hung, 50, 'Timed out reading balance');
		const expectation = expect(promise).rejects.toThrow(
			/Timed out reading balance/
		);
		await vi.advanceTimersByTimeAsync(60);
		await expectation;
	});
});

describe('humanizeWalletError', () => {
	it('maps user rejection and pending requests', () => {
		expect(humanizeWalletError({ code: 4001, message: 'x' })).toMatch(
			/declined/i
		);
		expect(humanizeWalletError({ code: -32002, message: 'x' })).toMatch(
			/pending request/i
		);
	});

	it('keeps timeout copy and falls back', () => {
		expect(
			humanizeWalletError(new Error('Timed out reading your USDC balance'))
		).toContain('Timed out');
		expect(humanizeWalletError(null)).toMatch(/try again/i);
	});
});

describe('walletCheckCopy', () => {
	it('describes network vs balance waits', () => {
		expect(
			walletCheckCopy({
				phase: 'network',
				option: { symbol: 'XGAS', networkLabel: 'Neo X Mainnet' },
			}).title
		).toMatch(/Checking your wallet/i);
		expect(
			walletCheckCopy({
				phase: 'balance',
				option: { symbol: 'USDC', networkLabel: 'Base' },
			}).description
		).toMatch(/USDC balance on Base/);
	});
});
