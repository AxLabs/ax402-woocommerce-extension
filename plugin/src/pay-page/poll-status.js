/**
 * Poll agent/order status until paid or timeout.
 *
 * @param {string} statusUrl
 * @param {{ intervalMs?: number, timeoutMs?: number }} [opts]
 * @return {Promise<object>}
 */
export async function pollUntilPaid(statusUrl, opts = {}) {
	const intervalMs = opts.intervalMs ?? 1000;
	const timeoutMs = opts.timeoutMs ?? 60000;
	const started = Date.now();

	while (Date.now() - started < timeoutMs) {
		const res = await fetch(statusUrl, { credentials: 'same-origin' });
		if (res.ok) {
			const data = await res.json();
			if (data?.paid) {
				return data;
			}
		}
		await new Promise((r) => setTimeout(r, intervalMs));
	}

	throw new Error('Timed out waiting for paid status');
}
