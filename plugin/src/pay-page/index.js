import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import { PaywallProvider, PaywallGate, usePaywall } from '@ax402/react-paywall';
import '@ax402/react-paywall/styles.css';
import { pollUntilPaid } from './poll-status';
import {
	formatPayLabel,
	hasSufficientBalance,
	networkMatches,
} from './readiness';
import {
	fetchTokenBalance,
	formatAtomicAmount,
	getEthereumProvider,
	getWalletChainId,
	switchOrAddChain,
} from './wallet-rpc';

function readConfig() {
	return window.ax402PayPage || {};
}

function SettlementPicker({ options, selectedId, onSelect }) {
	if (!options.length) {
		return null;
	}

	const selectable = options.length > 1;

	return (
		<div className="ax402-settle">
			<p className="ax402-settle-label">
				{selectable ? 'Choose settlement token' : 'Settlement token'}
			</p>
			<ul className="ax402-settle-list">
				{options.map((option) => {
					const active = option.tokenId === selectedId;
					return (
						<li key={option.tokenId}>
							<button
								type="button"
								className={
									active
										? 'ax402-settle-option is-active'
										: 'ax402-settle-option'
								}
								disabled={!selectable}
								aria-pressed={active}
								onClick={() => {
									if (selectable) {
										onSelect(option.tokenId);
									}
								}}
							>
								<span className="ax402-settle-symbol">
									{option.symbol}
								</span>
								<span className="ax402-settle-meta">
									{option.amount} · {option.networkLabel}
								</span>
							</button>
						</li>
					);
				})}
			</ul>
			{!selectable ? (
				<p className="ax402-settle-hint">
					Only one settlement token is enabled for this store. Enable
					more under WooCommerce → Settings → Payments → Ax402.
				</p>
			) : null}
		</div>
	);
}

function ReadinessPanel({ option, children }) {
	const { walletAddress } = usePaywall();
	const [chainId, setChainId] = useState(null);
	const [balanceAtomic, setBalanceAtomic] = useState(null);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');

	const networkOk = networkMatches(chainId, option?.chainIdHex);
	const balanceOk = hasSufficientBalance(
		balanceAtomic,
		option?.amountAtomic
	);
	const ready = Boolean(walletAddress && networkOk && balanceOk);

	useEffect(() => {
		const provider = getEthereumProvider();
		if (!provider || !walletAddress || !option) {
			setChainId(null);
			setBalanceAtomic(null);
			return undefined;
		}

		let cancelled = false;

		async function refresh() {
			try {
				const nextChain = await getWalletChainId(provider);
				if (cancelled) {
					return;
				}
				setChainId(nextChain);
				if (
					!networkMatches(nextChain, option.chainIdHex) ||
					!option.asset
				) {
					setBalanceAtomic(null);
					return;
				}
				const bal = await fetchTokenBalance(provider, {
					asset: option.asset,
					isNative: Boolean(option.isNative),
					owner: walletAddress,
				});
				if (!cancelled) {
					setBalanceAtomic(bal);
					setError('');
				}
			} catch (e) {
				if (!cancelled) {
					setError(e?.message || 'Could not read wallet balance');
				}
			}
		}

		refresh();
		const onChain = (id) => {
			setChainId(typeof id === 'string' ? id : null);
		};
		provider.on?.('chainChanged', onChain);
		provider.on?.('accountsChanged', refresh);
		const timer = setInterval(refresh, 8000);

		return () => {
			cancelled = true;
			clearInterval(timer);
			provider.removeListener?.('chainChanged', onChain);
			provider.removeListener?.('accountsChanged', refresh);
		};
	}, [walletAddress, option]);

	async function onSwitchNetwork() {
		const provider = getEthereumProvider();
		if (!provider || !option) {
			return;
		}
		setBusy(true);
		setError('');
		try {
			await switchOrAddChain(provider, {
				chainIdHex: option.chainIdHex,
				networkLabel: option.networkLabel,
				rpcUrl: option.rpcUrl,
				blockExplorerUrl: option.blockExplorerUrl,
			});
			const next = await getWalletChainId(provider);
			setChainId(next);
		} catch (e) {
			setError(e?.message || 'Network switch failed');
		} finally {
			setBusy(false);
		}
	}

	const needDisplay = option
		? formatAtomicAmount(option.amountAtomic, option.decimals)
		: '';
	const haveDisplay =
		balanceAtomic != null
			? formatAtomicAmount(balanceAtomic, option?.decimals ?? 6)
			: null;

	return (
		<div className="ax402-ready">
			{walletAddress && option && !networkOk && (
				<div className="ax402-banner ax402-banner-warn" role="status">
					<p>
						Switch to <strong>{option.networkLabel}</strong> to pay
						with {option.symbol}.
					</p>
					<button
						type="button"
						className="ax402-banner-btn"
						disabled={busy}
						onClick={onSwitchNetwork}
					>
						{busy ? 'Switching…' : `Switch to ${option.networkLabel}`}
					</button>
				</div>
			)}
			{walletAddress && option && networkOk && !balanceOk && (
				<div className="ax402-banner ax402-banner-error" role="status">
					<p>
						Need {needDisplay} {option.symbol}
						{haveDisplay != null
							? `, wallet has ${haveDisplay}`
							: ''}
						.
					</p>
				</div>
			)}
			{error ? (
				<p className="ax402-ready-error" role="alert">
					{error}
				</p>
			) : null}
			<div
				className={
					walletAddress && !ready
						? 'ax402-pay-gate is-blocked'
						: 'ax402-pay-gate'
				}
			>
				{children}
			</div>
		</div>
	);
}

function PayShell({ config, option }) {
	const priceLabel = formatPayLabel(option?.amount, option?.symbol);
	const title = option
		? `Confirm ${option.symbol} payment`
		: 'Confirm payment';
	const description = option
		? `Pay ${priceLabel} on ${option.networkLabel} to complete order #${config.orderId || ''}.`
		: `Complete order #${config.orderId || ''}.`;

	return (
		<ReadinessPanel option={option}>
			<PaywallGate
				resourceUrl={config.gatewayUrl}
				title={title}
				description={description}
				priceLabel={priceLabel}
				agentDiscovery={false}
				inspectOnMount
				onUnlocked={async () => {
					try {
						await pollUntilPaid(config.statusUrl, {
							intervalMs: 800,
							timeoutMs: 45000,
						});
					} catch (e) {
						// Fulfill may already have completed; still send shopper onward.
					}
					window.location.href = config.thankYouUrl;
				}}
				renderUnlocked={() => (
					<p>Payment received. Taking you to your order…</p>
				)}
			>
				<p>Payment received. Taking you to your order…</p>
			</PaywallGate>
		</ReadinessPanel>
	);
}

function PayApp() {
	const config = readConfig();
	const gatewayUrl = config.gatewayUrl;
	const options = useMemo(
		() =>
			Array.isArray(config.settlementOptions)
				? config.settlementOptions
				: [],
		[config.settlementOptions]
	);
	const [selectedId, setSelectedId] = useState(
		() => options[0]?.tokenId || ''
	);

	useEffect(() => {
		if (
			options.length &&
			!options.some((o) => o.tokenId === selectedId)
		) {
			setSelectedId(options[0].tokenId);
		}
	}, [options, selectedId]);

	const selected =
		options.find((o) => o.tokenId === selectedId) || options[0] || null;

	if (!gatewayUrl) {
		return (
			<div>
				<p>
					<strong>We could not start the wallet payment.</strong>
				</p>
				<p>
					Please refresh this page. If the problem continues, contact
					the store with your order number
					{config.orderId ? ` (#${config.orderId})` : ''}.
				</p>
			</div>
		);
	}

	const policyNetwork =
		selected?.network || config.preferredNetworks || config.network;
	const policyAsset = selected?.asset || config.allowedAssets;
	const rpcDefault = selected?.rpcUrl || config.rpcUrl;

	return (
		<>
			<div className="ax402-order-summary">
				<div>
					<span>Order total (USD)</span>
					<strong>
						${config.amountUsd || config.amountUsdc || ''}
					</strong>
				</div>
				{selected ? (
					<div>
						<span>You will pay</span>
						<strong>
							{formatPayLabel(selected.amount, selected.symbol)}
						</strong>
					</div>
				) : null}
			</div>
			<SettlementPicker
				options={options}
				selectedId={selected?.tokenId || ''}
				onSelect={setSelectedId}
			/>
			<PaywallProvider
				key={selected?.tokenId || 'default'}
				policy={{
					preferredNetworks: policyNetwork,
					allowedAssets: policyAsset,
					preferredAssets: policyAsset,
					strategy: 'preference-first',
				}}
				rpc={{
					defaultUrl: rpcDefault,
					byNetwork: config.rpcByNetwork || undefined,
				}}
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
				<PayShell config={config} option={selected} />
			</PaywallProvider>
		</>
	);
}

const rootEl = document.getElementById('ax402-pay-root');
if (rootEl) {
	createRoot(rootEl).render(<PayApp />);
}
