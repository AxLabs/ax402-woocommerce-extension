import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { pollUntilPaid } from '../../plugin/src/pay-page/poll-status.js';

describe('pollUntilPaid', () => {
  beforeEach(() => {
    vi.useFakeTimers();
  });
  afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
  });

  it('resolves when paid becomes true', async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ paid: false }),
      })
      .mockResolvedValueOnce({
        ok: true,
        json: async () => ({ paid: true, status: 'processing' }),
      });
    vi.stubGlobal('fetch', fetchMock);

    const promise = pollUntilPaid('https://example.test/status', {
      intervalMs: 10,
      timeoutMs: 1000,
    });
    await vi.advanceTimersByTimeAsync(20);
    const data = await promise;
    expect(data.paid).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('rejects when paid never becomes true before timeout', async () => {
    const fetchMock = vi.fn().mockResolvedValue({
      ok: true,
      json: async () => ({ paid: false }),
    });
    vi.stubGlobal('fetch', fetchMock);

    const promise = pollUntilPaid('https://example.test/status', {
      intervalMs: 10,
      timeoutMs: 35,
    });
    const expectation = expect(promise).rejects.toThrow(
      /Timed out waiting for paid status/
    );
    await vi.advanceTimersByTimeAsync(50);
    await expectation;
  });
});
