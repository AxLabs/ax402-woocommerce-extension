import { defineConfig } from 'vitest/config';
import path from 'node:path';

export default defineConfig({
  test: {
    environment: 'node',
    include: ['tests/js/**/*.test.js'],
  },
  resolve: {
    alias: {
      '@pay-page': path.resolve('plugin/src/pay-page'),
    },
  },
});
