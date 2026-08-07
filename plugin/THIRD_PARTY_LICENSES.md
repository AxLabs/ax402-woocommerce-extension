# Third-party licenses

This plugin’s PHP code is licensed under **GPL-3.0-or-later**.

Frontend assets in `build/` are produced from npm dependencies. Direct runtime dependencies and their licenses:

| Package | License |
| --- | --- |
| `@ax402/react-paywall` | Apache-2.0 |
| `@ax402/sdk` | Apache-2.0 |
| `@hashgraph/hedera-wallet-connect` | Apache-2.0 |
| `@hashgraph/proto` | Apache-2.0 |
| `@walletconnect/modal` | Apache-2.0 |
| `@wordpress/element` | GPL-2.0-or-later |
| `@wordpress/html-entities` | GPL-2.0-or-later |
| `@x402/evm` | Apache-2.0 |
| `@x402/fetch` | Apache-2.0 |
| `@x402/hedera` | Apache-2.0 |
| `viem` | MIT |

Transitive packages pulled into the pay-page bundle (Hedera / WalletConnect / protobuf / etc.) are typically Apache-2.0 or MIT. Those licenses are **GPL-3.0-compatible**. This plugin uses **GPLv3 or later** (not GPLv2-only) so Apache-2.0 code may be distributed with it.

Source for the frontend stack and build instructions:

https://github.com/AxLabs/ax402-woocommerce-extension
