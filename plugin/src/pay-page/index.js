import { createRoot, useEffect, useMemo, useState } from '@wordpress/element';
import {
	PaywallProvider,
	PaywallGate,
	usePaywall,
	WalletPicker,
	WalletSelectionRequiredError,
} from '@ax402/react-paywall';
import '@ax402/react-paywall/styles.css';
import { pollUntilPaid } from './poll-status';
import {
	formatPayLabel,
	formatTokenAmount,
	formatUsdExchangeRate,
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

function shortAddress( address ) {
	if ( ! address ) {
		return '';
	}
	return `${ address.slice( 0, 6 ) }…${ address.slice( -4 ) }`;
}

function NetworkGlyph() {
	return (
		<svg
			className="ax402-settle-glyph"
			viewBox="0 0 16 16"
			width="12"
			height="12"
			aria-hidden="true"
			focusable="false"
		>
			<circle
				cx="8"
				cy="8"
				r="6.25"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.4"
			/>
			<path
				d="M2.2 8h11.6M8 1.75c1.7 1.85 2.55 3.85 2.55 6.25S9.7 12.4 8 14.25C6.3 12.4 5.45 10.4 5.45 8S6.3 3.6 8 1.75z"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.25"
			/>
		</svg>
	);
}

function RateGlyph() {
	return (
		<svg
			className="ax402-settle-glyph"
			viewBox="0 0 16 16"
			width="12"
			height="12"
			aria-hidden="true"
			focusable="false"
		>
			<path
				d="M3.5 5.5h7.2M8.2 3.2 10.8 5.5 8.2 7.8"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.4"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
			<path
				d="M12.5 10.5H5.3M7.8 8.2 5.2 10.5 7.8 12.8"
				fill="none"
				stroke="currentColor"
				strokeWidth="1.4"
				strokeLinecap="round"
				strokeLinejoin="round"
			/>
		</svg>
	);
}

function SettlementPicker( { options, selectedId, onSelect } ) {
	if ( ! options.length ) {
		return null;
	}

	const selectable = options.length > 1;

	return (
		<div className="ax402-settle">
			<p className="ax402-settle-label">
				{ selectable ? 'Choose settlement token' : 'Settlement token' }
			</p>
			<ul className="ax402-settle-list">
				{ options.map( ( option ) => {
					const active = option.tokenId === selectedId;
					const amountLabel = formatTokenAmount( option.amount, 8 );
					const rateLabel = formatUsdExchangeRate(
						option.rate,
						option.symbol
					);
					return (
						<li key={ option.tokenId }>
							<button
								type="button"
								className={
									active
										? 'ax402-settle-option is-active'
										: 'ax402-settle-option'
								}
								disabled={ ! selectable }
								aria-pressed={ active }
								onClick={ () => {
									if ( selectable ) {
										onSelect( option.tokenId );
									}
								} }
							>
								<span className="ax402-settle-top">
									<span className="ax402-settle-symbol">
										{ option.symbol }
									</span>
									<span className="ax402-settle-network">
										<NetworkGlyph />
										{ option.networkLabel ||
											option.network ||
											'' }
									</span>
								</span>
								<span className="ax402-settle-amount">
									{ amountLabel } { option.symbol }
								</span>
								{ rateLabel ? (
									<span className="ax402-settle-rate">
										<RateGlyph />
										{ rateLabel }
									</span>
								) : null }
							</button>
						</li>
					);
				} ) }
			</ul>
		</div>
	);
}

function StepCard( { title, description, children, footer } ) {
	return (
		<div className="ax402-step" role="region" aria-label={ title }>
			<h2 className="ax402-step-title">{ title }</h2>
			{ description ? (
				<p className="ax402-step-desc">{ description }</p>
			) : null }
			{ children }
			{ footer ? (
				<div className="ax402-step-footer">{ footer }</div>
			) : null }
		</div>
	);
}

function ConnectStep() {
	const { connectWallet, refreshWallets, connectedWalletName } = usePaywall();
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ pickerWallets, setPickerWallets ] = useState( [] );

	async function onConnect() {
		setBusy( true );
		setError( '' );
		setPickerWallets( [] );
		try {
			await connectWallet();
		} catch ( e ) {
			if ( e instanceof WalletSelectionRequiredError ) {
				setPickerWallets( e.wallets || [] );
			} else {
				try {
					const list = await refreshWallets();
					if ( list?.length > 1 ) {
						setPickerWallets( list );
					} else {
						setError( e?.message || 'Could not connect wallet' );
					}
				} catch {
					setError( e?.message || 'Could not connect wallet' );
				}
			}
		} finally {
			setBusy( false );
		}
	}

	async function onPick( walletId ) {
		setBusy( true );
		setError( '' );
		try {
			await connectWallet( walletId );
			setPickerWallets( [] );
		} catch ( e ) {
			setError( e?.message || 'Could not connect wallet' );
		} finally {
			setBusy( false );
		}
	}

	return (
		<StepCard
			title="Connect your wallet"
			description="Connect and just sign the payment to continue."
		>
			{ pickerWallets.length > 0 ? (
				<WalletPicker
					wallets={ pickerWallets }
					disabled={ busy }
					onSelect={ onPick }
					onCancel={ () => setPickerWallets( [] ) }
				/>
			) : (
				<button
					type="button"
					className="ax402-primary-btn"
					disabled={ busy }
					onClick={ onConnect }
				>
					{ busy ? 'Connecting…' : 'Connect wallet' }
				</button>
			) }
			{ error ? (
				<p className="ax402-step-error" role="alert">
					{ error }
				</p>
			) : null }
			{ connectedWalletName ? (
				<p className="ax402-step-meta">{ connectedWalletName }</p>
			) : null }
		</StepCard>
	);
}

function SwitchNetworkStep( { option } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const { walletAddress, connectedWalletName } = usePaywall();

	async function onSwitchNetwork() {
		const provider = getEthereumProvider();
		if ( ! provider || ! option ) {
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			await switchOrAddChain( provider, {
				chainIdHex: option.chainIdHex,
				networkLabel: option.networkLabel,
				rpcUrl: option.rpcUrl,
				blockExplorerUrl: option.blockExplorerUrl,
			} );
		} catch ( e ) {
			setError( e?.message || 'Network switch failed' );
		} finally {
			setBusy( false );
		}
	}

	return (
		<StepCard
			title={ `Switch to ${ option.networkLabel }` }
			description={ `Your wallet is on the wrong network for ${ option.symbol }. Switch, then you’ll confirm the payment.` }
			footer={
				<span>
					{ connectedWalletName ? `${ connectedWalletName } · ` : '' }
					{ shortAddress( walletAddress ) }
				</span>
			}
		>
			<button
				type="button"
				className="ax402-primary-btn"
				disabled={ busy }
				onClick={ onSwitchNetwork }
			>
				{ busy ? 'Switching…' : `Switch to ${ option.networkLabel }` }
			</button>
			{ error ? (
				<p className="ax402-step-error" role="alert">
					{ error }
				</p>
			) : null }
		</StepCard>
	);
}

function InsufficientBalanceStep( { option, balanceAtomic } ) {
	const { walletAddress, connectedWalletName } = usePaywall();
	const need = formatAtomicAmount( option.amountAtomic, option.decimals );
	const have =
		balanceAtomic !== null && balanceAtomic !== undefined
			? formatAtomicAmount( balanceAtomic, option.decimals )
			: '—';

	return (
		<StepCard
			title="Insufficient balance"
			description={ `This order needs ${ need } ${ option.symbol } on ${ option.networkLabel }. Your wallet has ${ have } ${ option.symbol }.` }
			footer={
				<span>
					{ connectedWalletName ? `${ connectedWalletName } · ` : '' }
					{ shortAddress( walletAddress ) }
				</span>
			}
		>
			<p className="ax402-step-hint">
				Add funds on { option.networkLabel }, then this page will unlock
				payment automatically.
			</p>
		</StepCard>
	);
}

function PayStep( { config, option } ) {
	const priceLabel = formatPayLabel( option?.amount, option?.symbol );
	const [ phase, setPhase ] = useState( 'pay' );
	const [ error, setError ] = useState( '' );

	if ( phase === 'confirming' ) {
		return (
			<StepCard
				title="Confirming payment…"
				description="Your wallet payment was submitted. Waiting for the store to confirm it — this usually takes a few seconds."
			>
				<div className="ax402-spinner" aria-hidden="true" />
				<p className="ax402-step-meta">Do not close this page.</p>
			</StepCard>
		);
	}

	if ( phase === 'error' ) {
		return (
			<StepCard title="Payment not confirmed yet" description={ error }>
				<button
					type="button"
					className="ax402-primary-btn"
					onClick={ () => {
						window.location.reload();
					} }
				>
					Check again
				</button>
				{ config.thankYouUrl ? (
					<p className="ax402-step-meta">
						<a href={ config.thankYouUrl }>View order status</a>
					</p>
				) : null }
			</StepCard>
		);
	}

	return (
		<div className="ax402-pay-step">
			<PaywallGate
				resourceUrl={ config.gatewayUrl }
				title={ `Pay ${ priceLabel }` }
				description={ `Confirm in your wallet to complete order #${
					config.orderId || ''
				} on ${ option.networkLabel }.` }
				priceLabel={ priceLabel }
				agentDiscovery={ false }
				inspectOnMount
				className="ax402-inline-gate"
				onUnlocked={ async () => {
					setPhase( 'confirming' );
					setError( '' );
					try {
						await pollUntilPaid( config.statusUrl, {
							intervalMs: 800,
							timeoutMs: 90000,
						} );
						window.location.href = config.thankYouUrl;
					} catch ( e ) {
						setError(
							'The store has not marked this order as paid yet. If you already confirmed in your wallet, wait a moment and tap Check again. If this keeps happening, contact the store with your order number.'
						);
						setPhase( 'error' );
					}
				} }
				renderUnlocked={ () => (
					<p>Payment received. Confirming with the store…</p>
				) }
			>
				<p>Payment received. Confirming with the store…</p>
			</PaywallGate>
		</div>
	);
}

function PaymentSteps( { config, option, endpointReady, lockError } ) {
	const { walletAddress } = usePaywall();
	const [ chainId, setChainId ] = useState( null );
	const [ balanceAtomic, setBalanceAtomic ] = useState( null );
	const [ readError, setReadError ] = useState( '' );

	const networkOk = networkMatches( chainId, option?.chainIdHex );
	const balanceOk = hasSufficientBalance(
		balanceAtomic,
		option?.amountAtomic
	);

	useEffect( () => {
		const provider = getEthereumProvider();
		if ( ! provider || ! walletAddress || ! option ) {
			setChainId( null );
			setBalanceAtomic( null );
			return undefined;
		}

		let cancelled = false;

		async function refresh() {
			try {
				const nextChain = await getWalletChainId( provider );
				if ( cancelled ) {
					return;
				}
				setChainId( nextChain );
				if (
					! networkMatches( nextChain, option.chainIdHex ) ||
					! option.asset
				) {
					setBalanceAtomic( null );
					return;
				}
				const bal = await fetchTokenBalance( provider, {
					asset: option.asset,
					isNative: Boolean( option.isNative ),
					owner: walletAddress,
				} );
				if ( ! cancelled ) {
					setBalanceAtomic( bal );
					setReadError( '' );
				}
			} catch ( e ) {
				if ( ! cancelled ) {
					setReadError( e?.message || 'Could not read wallet state' );
				}
			}
		}

		refresh();
		const onChain = ( id ) => {
			setChainId( typeof id === 'string' ? id : null );
		};
		provider.on?.( 'chainChanged', onChain );
		provider.on?.( 'accountsChanged', refresh );
		const timer = setInterval( refresh, 8000 );

		return () => {
			cancelled = true;
			clearInterval( timer );
			provider.removeListener?.( 'chainChanged', onChain );
			provider.removeListener?.( 'accountsChanged', refresh );
		};
	}, [ walletAddress, option ] );

	let step = null;
	if ( ! walletAddress ) {
		step = <ConnectStep />;
	} else if ( ! option ) {
		step = (
			<StepCard
				title="No settlement token"
				description="This store has no payable settlement token configured."
			/>
		);
	} else if ( ! networkOk ) {
		step = <SwitchNetworkStep option={ option } />;
	} else if ( ! balanceOk ) {
		step = (
			<InsufficientBalanceStep
				option={ option }
				balanceAtomic={ balanceAtomic }
			/>
		);
	} else if ( lockError ) {
		step = (
			<StepCard
				title="Could not prepare payment"
				description={ lockError }
			>
				<button
					type="button"
					className="ax402-primary-btn"
					onClick={ () => {
						window.location.reload();
					} }
				>
					Try again
				</button>
			</StepCard>
		);
	} else if ( ! endpointReady ) {
		step = (
			<StepCard
				title="Preparing payment…"
				description="Setting the exact settlement amount for the selected token."
			>
				<div className="ax402-spinner" aria-hidden="true" />
			</StepCard>
		);
	} else {
		step = <PayStep config={ config } option={ option } />;
	}

	return (
		<div className="ax402-steps">
			{ readError ? (
				<p className="ax402-step-error" role="alert">
					{ readError }
				</p>
			) : null }
			{ step }
		</div>
	);
}

function PayApp() {
	const config = readConfig();
	const gatewayUrl = config.gatewayUrl;
	const options = useMemo(
		() =>
			Array.isArray( config.settlementOptions )
				? config.settlementOptions
				: [],
		[ config.settlementOptions ]
	);
	const [ selectedId, setSelectedId ] = useState(
		() => options[ 0 ]?.tokenId || ''
	);
	const [ endpointReady, setEndpointReady ] = useState( false );
	const [ lockError, setLockError ] = useState( '' );

	useEffect( () => {
		if (
			options.length &&
			! options.some( ( o ) => o.tokenId === selectedId )
		) {
			setSelectedId( options[ 0 ].tokenId );
		}
	}, [ options, selectedId ] );

	const selected =
		options.find( ( o ) => o.tokenId === selectedId ) ||
		options[ 0 ] ||
		null;

	useEffect( () => {
		if ( ! selected?.tokenId ) {
			setEndpointReady( false );
			setLockError( '' );
			return undefined;
		}

		const url = config.selectSettlementUrl;
		if ( ! url ) {
			setEndpointReady( true );
			setLockError( '' );
			return undefined;
		}

		let cancelled = false;
		setEndpointReady( false );
		setLockError( '' );

		( async () => {
			try {
				const res = await fetch( url, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						Accept: 'application/json',
						'Content-Type': 'application/json',
					},
					body: JSON.stringify( { tokenId: selected.tokenId } ),
				} );
				const data = await res.json().catch( () => ( {} ) );
				if ( ! res.ok ) {
					const msg =
						data?.message ||
						data?.code ||
						`Could not lock settlement (${ res.status })`;
					throw new Error( msg );
				}
				if ( ! cancelled ) {
					setEndpointReady( true );
				}
			} catch ( e ) {
				if ( ! cancelled ) {
					setEndpointReady( false );
					setLockError(
						e?.message ||
							'Could not set the settlement amount on Ax402'
					);
				}
			}
		} )();

		return () => {
			cancelled = true;
		};
	}, [ selected?.tokenId, config.selectSettlementUrl ] );

	if ( ! gatewayUrl ) {
		return (
			<div>
				<p>
					<strong>We could not start the wallet payment.</strong>
				</p>
				<p>
					Please refresh this page. If the problem continues, contact
					the store with your order number
					{ config.orderId ? ` (#${ config.orderId })` : '' }.
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
						${ config.amountUsd || config.amountUsdc || '' }
					</strong>
				</div>
				{ selected ? (
					<div>
						<span>You will pay</span>
						<strong>
							{ formatPayLabel(
								formatTokenAmount( selected.amount, 8 ),
								selected.symbol
							) }
						</strong>
					</div>
				) : null }
			</div>
			<SettlementPicker
				options={ options }
				selectedId={ selected?.tokenId || '' }
				onSelect={ setSelectedId }
			/>
			<PaywallProvider
				key={ `${ selected?.tokenId || 'default' }-${
					endpointReady ? 'ready' : 'locking'
				}` }
				policy={ {
					preferredNetworks: policyNetwork,
					allowedAssets: policyAsset,
					preferredAssets: policyAsset,
					strategy: 'preference-first',
				} }
				rpc={ {
					defaultUrl: rpcDefault,
					byNetwork: config.rpcByNetwork || undefined,
				} }
				appearance={ {
					labels: {
						connectWallet: 'Connect wallet',
						payToRead: 'Pay with wallet',
						paying: 'Confirm in your wallet…',
						walletPickerTitle: 'Choose a wallet',
						walletPickerCancel: 'Cancel',
					},
				} }
			>
				<PaymentSteps
					config={ config }
					option={ selected }
					endpointReady={ endpointReady }
					lockError={ lockError }
				/>
			</PaywallProvider>
		</>
	);
}

const rootEl = document.getElementById( 'ax402-pay-root' );
if ( rootEl ) {
	createRoot( rootEl ).render( <PayApp /> );
}
