import { createRoot } from '@wordpress/element';
import { PaywallProvider, PaywallGate } from '@ax402/react-paywall';
import '@ax402/react-paywall/styles.css';
import { pollUntilPaid } from './poll-status';

function readConfig() {
	return window.ax402PayPage || {};
}

function PayApp() {
	const config = readConfig();
	const gatewayUrl = config.gatewayUrl;
	const thankYouUrl = config.thankYouUrl;
	const statusUrl = config.statusUrl;

	if (!gatewayUrl) {
		return (
			<div>
				<p>
					<strong>We could not start the wallet payment.</strong>
				</p>
				<p>
					Please refresh this page. If the problem continues, contact the
					store with your order number
					{config.orderId ? ` (#${config.orderId})` : ''}.
				</p>
			</div>
		);
	}

	return (
		<PaywallProvider
			policy={{
				preferredNetworks: config.preferredNetworks || config.network,
				allowedAssets: config.allowedAssets,
				strategy: 'preference-first',
			}}
			rpc={{ defaultUrl: config.rpcUrl }}
			appearance={{
				labels: {
					connectWallet: 'Connect wallet',
					payToRead: 'Pay with wallet',
					paying: 'Confirm in your wallet…',
					walletPickerTitle: 'Choose a wallet',
					walletPickerCancel: 'Cancel',
				},
			}}
		>
			<PaywallGate
				resourceUrl={gatewayUrl}
				title="Confirm USDC payment"
				description={`Pay ${config.amountUsdc || ''} USDC on Base to complete order #${config.orderId || ''}.`}
				priceLabel={`${config.amountUsdc || ''} USDC`}
				agentDiscovery={false}
				inspectOnMount
				onUnlocked={async () => {
					try {
						await pollUntilPaid(statusUrl, {
							intervalMs: 800,
							timeoutMs: 45000,
						});
					} catch (e) {
						// Fulfill may already have completed; still send shopper onward.
					}
					window.location.href = thankYouUrl;
				}}
				renderUnlocked={() => (
					<p>Payment received. Taking you to your order…</p>
				)}
			>
				<p>Payment received. Taking you to your order…</p>
			</PaywallGate>
		</PaywallProvider>
	);
}

const rootEl = document.getElementById('ax402-pay-root');
if (rootEl) {
	createRoot(rootEl).render(<PayApp />);
}
