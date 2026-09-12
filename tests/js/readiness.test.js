import { describe, expect, it } from 'vitest';
import {
	encodeBalanceOf,
	formatPayLabel,
	formatTokenAmount,
	formatUsdExchangeRate,
	hasSufficientBalance,
	hexQuantityToDecimal,
	networkMatches,
	shouldShowInsufficientBalance,
} from '../../plugin/src/pay-page/readiness.js';

describe('networkMatches', () => {
	it('matches case-insensitively', () => {
		expect(networkMatches('0x14a34', '0x14A34')).toBe(true);
		expect(networkMatches('0x1', '0x2')).toBe(false);
		expect(networkMatches(null, '0x1')).toBe(false);
	});
});

describe('hasSufficientBalance', () => {
	it('compares atomic decimal strings', () => {
		expect(hasSufficientBalance('1000', '1000')).toBe(true);
		expect(hasSufficientBalance('999', '1000')).toBe(false);
		expect(hasSufficientBalance('1000000', '22500000')).toBe(false);
		expect(hasSufficientBalance('22500000', '22500000')).toBe(true);
	});

	it('rejects non-numeric input', () => {
		expect(hasSufficientBalance('0x10', '16')).toBe(false);
		expect(hasSufficientBalance(null, '1')).toBe(false);
	});
});

describe('shouldShowInsufficientBalance', () => {
	const shortfall = {
		walletStatus: 'ready',
		networkOk: true,
		balanceAtomic: '1',
		requiredAtomic: '1000',
		paymentSubmitted: false,
	};

	it('shows only a confirmed shortfall on the matching network', () => {
		expect(shouldShowInsufficientBalance(shortfall)).toBe(true);
		expect(
			shouldShowInsufficientBalance({
				...shortfall,
				balanceAtomic: '1000',
			})
		).toBe(false);
	});

	it('hides while checking, on the wrong network, or after pay starts', () => {
		expect(
			shouldShowInsufficientBalance({
				...shortfall,
				walletStatus: 'checking',
			})
		).toBe(false);
		expect(
			shouldShowInsufficientBalance({
				...shortfall,
				walletStatus: 'error',
			})
		).toBe(false);
		expect(
			shouldShowInsufficientBalance({ ...shortfall, networkOk: false })
		).toBe(false);
		expect(
			shouldShowInsufficientBalance({
				...shortfall,
				balanceAtomic: null,
			})
		).toBe(false);
		expect(
			shouldShowInsufficientBalance({
				...shortfall,
				paymentSubmitted: true,
			})
		).toBe(false);
	});
});

describe('hexQuantityToDecimal', () => {
	it('converts hex quantities', () => {
		expect(hexQuantityToDecimal('0xff')).toBe('255');
		expect(hexQuantityToDecimal('0x0')).toBe('0');
	});
});

describe('encodeBalanceOf', () => {
	it('pads owner address into calldata', () => {
		const data = encodeBalanceOf('0xabc');
		expect(data.startsWith('0x70a08231')).toBe(true);
		expect(data.length).toBe(74);
	});
});

describe('formatPayLabel', () => {
	it('joins amount and symbol', () => {
		expect(formatPayLabel('1.5', 'USDC')).toBe('1.5 USDC');
	});
});

describe('formatTokenAmount', () => {
	it('trims long fractions', () => {
		expect(formatTokenAmount('0.104671569852999163', 8)).toBe(
			'0.10467156'
		);
		expect(formatTokenAmount('0.130000', 8)).toBe('0.13');
		expect(formatTokenAmount('1', 8)).toBe('1');
	});
});

describe('formatUsdExchangeRate', () => {
	it('formats tokens-per-USD', () => {
		expect(formatUsdExchangeRate('1', 'USDC')).toBe('1 USD = 1 USDC');
		expect(formatUsdExchangeRate('0.806185921946147334', 'ZCHF')).toBe(
			'1 USD = 0.80618592 ZCHF'
		);
	});
});
