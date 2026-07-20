import { describe, expect, it } from 'vitest';
import {
	encodeBalanceOf,
	formatPayLabel,
	hasSufficientBalance,
	hexQuantityToDecimal,
	networkMatches,
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
