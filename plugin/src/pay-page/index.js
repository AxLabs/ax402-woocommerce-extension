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
	networkMatches,
	shouldShowInsufficientBalance,
} from './readiness';
import {
	fetchTokenBalance,
	formatAtomicAmount,
	getEthereumProvider,
	getWalletChainId,
	switchOrAddChain,
} from './wallet-rpc';
import {
	fetchHederaBalance,
	isHederaOption,
	shortHederaAccount,
} from './hedera-readiness';
import {
	WALLET_CONNECT_TIMEOUT_MS,
	WALLET_READ_TIMEOUT_MS,
	WALLET_SWITCH_TIMEOUT_MS,
	humanizeWalletError,
	walletCheckCopy,
	withTimeout,
} from './wallet-errors';
import { wrapPaywallFetch } from './payment-fetch';

function readConfig() {
	return window.ax402PayPage || {};
}

function shortAddress( address, hedera = false ) {
	if ( ! address ) {
		return '';
	}
	if ( hedera ) {
		return shortHederaAccount( address );
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

function SettlementPicker( { options, selectedId, onSelect, locked } ) {
	if ( ! options.length ) {
		return null;
	}

	const selectable = options.length > 1 && ! locked;

	return (
		<div className={ locked ? 'ax402-settle is-locked' : 'ax402-settle' }>
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

function StepCard( { title, description, children, footer, live } ) {
	return (
		<div
			className="ax402-step"
			role="region"
			aria-label={ title }
			aria-live={ live || undefined }
		>
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

function ConnectStep( { hedera } ) {
	const {
		connectWallet,
		connectHederaWallet,
		refreshWallets,
		connectedWalletName,
	} = usePaywall();
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );
	const [ pickerWallets, setPickerWallets ] = useState( [] );

	async function onConnect() {
		setBusy( true );
		setError( '' );
		setPickerWallets( [] );
		try {
			if ( hedera ) {
				await withTimeout(
					connectHederaWallet(),
					WALLET_CONNECT_TIMEOUT_MS,
					'Timed out connecting your Hedera wallet. Open the WalletConnect prompt and try again.'
				);
			} else {
				await withTimeout(
					connectWallet(),
					WALLET_CONNECT_TIMEOUT_MS,
					'Timed out connecting your wallet. Open MetaMask (or your wallet) and try again.'
				);
			}
		} catch ( e ) {
			if ( ! hedera && e instanceof WalletSelectionRequiredError ) {
				setPickerWallets( e.wallets || [] );
			} else if ( ! hedera ) {
				try {
					const list = await refreshWallets();
					if ( list?.length > 1 ) {
						setPickerWallets( list );
					} else {
						setError(
							humanizeWalletError( e, 'Could not connect wallet' )
						);
					}
				} catch {
					setError(
						humanizeWalletError( e, 'Could not connect wallet' )
					);
				}
			} else {
				setError(
					humanizeWalletError( e, 'Could not connect Hedera wallet' )
				);
			}
		} finally {
			setBusy( false );
		}
	}

	async function onPick( walletId ) {
		setBusy( true );
		setError( '' );
		try {
			await withTimeout(
				connectWallet( walletId ),
				WALLET_CONNECT_TIMEOUT_MS,
				'Timed out connecting your wallet. Open MetaMask (or your wallet) and try again.'
			);
			setPickerWallets( [] );
		} catch ( e ) {
			setError( humanizeWalletError( e, 'Could not connect wallet' ) );
		} finally {
			setBusy( false );
		}
	}

	return (
		<StepCard
			title="Connect your wallet"
			description={
				hedera
					? 'Connect a Hedera wallet via WalletConnect, then sign the payment.'
					: 'Connect and just sign the payment to continue.'
			}
		>
			{ ! hedera && pickerWallets.length > 0 ? (
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
					{ ( () => {
						if ( busy ) {
							return 'Connecting…';
						}
						return hedera
							? 'Connect Hedera wallet'
							: 'Connect wallet';
					} )() }
				</button>
			) }
			{ busy ? (
				<div className="ax402-spinner" aria-hidden="true" />
			) : null }
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
			setError(
				'No Ethereum wallet found. Unlock MetaMask and try again.'
			);
			return;
		}
		setBusy( true );
		setError( '' );
		try {
			await withTimeout(
				switchOrAddChain( provider, {
					chainIdHex: option.chainIdHex,
					networkLabel: option.networkLabel,
					rpcUrl: option.rpcUrl,
					blockExplorerUrl: option.blockExplorerUrl,
				} ),
				WALLET_SWITCH_TIMEOUT_MS,
				`Timed out waiting to switch to ${ option.networkLabel }. Open your wallet to approve the switch, then try again.`
			);
		} catch ( e ) {
			setError( humanizeWalletError( e, 'Network switch failed' ) );
		} finally {
			setBusy( false );
		}
	}

	return (
		<StepCard
			title={ `Switch to ${ option.networkLabel }` }
			description={
				busy
					? `Waiting for your wallet to switch to ${ option.networkLabel }. Check the wallet popup if nothing happens.`
					: `Your wallet is on the wrong network for ${ option.symbol }. Switch, then this page will check your balance before you pay.`
			}
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
			{ busy ? (
				<div className="ax402-spinner" aria-hidden="true" />
			) : null }
			{ error ? (
				<p className="ax402-step-error" role="alert">
					{ error }
				</p>
			) : null }
		</StepCard>
	);
}

function CheckingWalletStep( { option, phase } ) {
	const copy = walletCheckCopy( { phase, option } );
	return (
		<StepCard title={ copy.title } description={ copy.description }>
			<div className="ax402-spinner" aria-hidden="true" />
			<p className="ax402-step-meta" aria-live="polite">
				This is not a payment yet. We only read your network and
				balance.
			</p>
		</StepCard>
	);
}

function WalletIssueStep( { message, onRetry } ) {
	return (
		<StepCard
			title="Could not check your wallet"
			description={
				message ||
				'Something went wrong while talking to your wallet. Try again.'
			}
		>
			<button
				type="button"
				className="ax402-primary-btn"
				onClick={ onRetry }
			>
				Try again
			</button>
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

function ConfirmingPaymentStep() {
	return (
		<StepCard
			title="Confirming payment…"
			description="Your wallet payment was submitted. You don’t need to confirm anything else in your wallet. Waiting for the store to confirm it — this usually takes a few seconds."
			live="polite"
		>
			<div className="ax402-spinner" aria-hidden="true" />
			<p className="ax402-step-meta">Do not close this page.</p>
		</StepCard>
	);
}

function PayStep( {
	config,
	option,
	onPaymentSubmitted,
	startConfirming,
	settling,
} ) {
	const priceLabel = formatPayLabel( option?.amount, option?.symbol );
	const [ phase, setPhase ] = useState(
		startConfirming ? 'confirming' : 'pay'
	);
	const [ error, setError ] = useState( '' );
	const confirming = phase === 'confirming' || Boolean( settling );

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
			{ confirming ? <ConfirmingPaymentStep /> : null }
			{ phase === 'confirming' ? null : (
				<div
					className="ax402-pay-gate"
					hidden={ confirming }
					aria-hidden={ confirming }
				>
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
							// Freeze the parent balance gate: post-settlement balance is
							// below the order amount and would otherwise flash "Insufficient".
							onPaymentSubmitted?.();
							setPhase( 'confirming' );
							setError( '' );
							const confirmingStartedAt = Date.now();
							const minConfirmingMs = 3000;
							try {
								await pollUntilPaid( config.statusUrl, {
									intervalMs: 800,
									timeoutMs: 90000,
								} );
								const elapsed =
									Date.now() - confirmingStartedAt;
								if ( elapsed < minConfirmingMs ) {
									await new Promise( ( resolve ) =>
										setTimeout(
											resolve,
											minConfirmingMs - elapsed
										)
									);
								}
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
			) }
		</div>
	);
}

function PaymentSteps( {
	config,
	option,
	endpointReady,
	lockError,
	signedPaymentInFlight,
} ) {
	const { walletAddress } = usePaywall();
	const [ chainId, setChainId ] = useState( null );
	const [ balanceAtomic, setBalanceAtomic ] = useState( null );
	const [ walletStatus, setWalletStatus ] = useState( 'idle' );
	const [ checkPhase, setCheckPhase ] = useState( 'network' );
	const [ readError, setReadError ] = useState( '' );
	const [ checkNonce, setCheckNonce ] = useState( 0 );
	const [ paymentSubmitted, setPaymentSubmitted ] = useState( false );
	const hedera = isHederaOption( option );
	const tokenKey = option?.tokenId || '';
	const freezePay = paymentSubmitted || Boolean( signedPaymentInFlight );

	useEffect( () => {
		if ( signedPaymentInFlight ) {
			setPaymentSubmitted( true );
		}
	}, [ signedPaymentInFlight ] );

	const networkOk = hedera
		? true
		: networkMatches( chainId, option?.chainIdHex );
	const showInsufficient = shouldShowInsufficientBalance( {
		walletStatus,
		networkOk,
		balanceAtomic,
		requiredAtomic: option?.amountAtomic,
		paymentSubmitted: freezePay,
	} );

	useEffect( () => {
		if ( ! walletAddress || ! option || freezePay ) {
			if ( freezePay ) {
				return undefined;
			}
			setChainId( null );
			setBalanceAtomic( null );
			setWalletStatus( 'idle' );
			return undefined;
		}

		let cancelled = false;
		setWalletStatus( 'checking' );
		setCheckPhase( hedera ? 'balance' : 'network' );
		setBalanceAtomic( null );
		setReadError( '' );

		async function refresh( { silent = false } = {} ) {
			try {
				if ( hedera ) {
					if ( ! silent ) {
						setCheckPhase( 'balance' );
					}
					const bal = await withTimeout(
						fetchHederaBalance( {
							mirrorBase: option.rpcUrl,
							accountId: walletAddress,
							asset: option.asset,
							isNative: Boolean( option.isNative ),
						} ),
						WALLET_READ_TIMEOUT_MS,
						`Timed out reading your ${ option.symbol } balance from Hedera. Check your connection and try again.`
					);
					if ( cancelled ) {
						return;
					}
					setChainId( 'hedera' );
					setBalanceAtomic( bal );
					setReadError( '' );
					setWalletStatus( 'ready' );
					return;
				}

				const provider = getEthereumProvider();
				if ( ! provider ) {
					if ( silent ) {
						return;
					}
					setChainId( null );
					setBalanceAtomic( null );
					setWalletStatus( 'error' );
					setReadError(
						'No Ethereum wallet found. Unlock MetaMask and try again.'
					);
					return;
				}
				if ( ! silent ) {
					setCheckPhase( 'network' );
				}
				const nextChain = await withTimeout(
					getWalletChainId( provider ),
					WALLET_READ_TIMEOUT_MS,
					'Timed out asking your wallet for the current network. Open your wallet and try again.'
				);
				if ( cancelled ) {
					return;
				}
				setChainId( nextChain );
				if (
					! networkMatches( nextChain, option.chainIdHex ) ||
					! option.asset
				) {
					setBalanceAtomic( null );
					setWalletStatus( 'ready' );
					return;
				}
				if ( ! silent ) {
					setCheckPhase( 'balance' );
				}
				const bal = await withTimeout(
					fetchTokenBalance( provider, {
						asset: option.asset,
						isNative: Boolean( option.isNative ),
						owner: walletAddress,
					} ),
					WALLET_READ_TIMEOUT_MS,
					`Timed out reading your ${ option.symbol } balance on ${ option.networkLabel }. The network may be slow — try again.`
				);
				if ( cancelled ) {
					return;
				}
				setBalanceAtomic( bal );
				setReadError( '' );
				setWalletStatus( 'ready' );
			} catch ( e ) {
				if ( cancelled || silent ) {
					return;
				}
				setWalletStatus( 'error' );
				setReadError(
					humanizeWalletError( e, 'Could not read wallet state' )
				);
			}
		}

		refresh( { silent: false } );

		if ( hedera ) {
			const timer = setInterval( () => {
				refresh( { silent: true } );
			}, 8000 );
			return () => {
				cancelled = true;
				clearInterval( timer );
			};
		}

		const provider = getEthereumProvider();
		if ( ! provider ) {
			return () => {
				cancelled = true;
			};
		}

		const onChain = () => {
			setBalanceAtomic( null );
			setWalletStatus( 'checking' );
			setCheckPhase( 'network' );
			refresh( { silent: false } );
		};
		const onAccounts = () => {
			refresh( { silent: false } );
		};
		provider.on?.( 'chainChanged', onChain );
		provider.on?.( 'accountsChanged', onAccounts );
		const timer = setInterval( () => {
			refresh( { silent: true } );
		}, 8000 );

		return () => {
			cancelled = true;
			clearInterval( timer );
			provider.removeListener?.( 'chainChanged', onChain );
			provider.removeListener?.( 'accountsChanged', onAccounts );
		};
	}, [ walletAddress, tokenKey, hedera, freezePay, checkNonce, option ] );

	let step = null;
	if ( ! walletAddress && ! freezePay ) {
		step = <ConnectStep hedera={ hedera } />;
	} else if ( ! option ) {
		step = (
			<StepCard
				title="No settlement token"
				description="This store has no payable settlement token configured."
			/>
		);
	} else if ( freezePay ) {
		step = (
			<PayStep
				key={ option.tokenId }
				config={ config }
				option={ option }
				settling={ Boolean( signedPaymentInFlight ) }
				startConfirming={ paymentSubmitted }
				onPaymentSubmitted={ () => setPaymentSubmitted( true ) }
			/>
		);
	} else if ( walletStatus === 'checking' || walletStatus === 'idle' ) {
		step = <CheckingWalletStep option={ option } phase={ checkPhase } />;
	} else if ( walletStatus === 'error' ) {
		step = (
			<WalletIssueStep
				message={ readError }
				onRetry={ () => {
					setReadError( '' );
					setWalletStatus( 'checking' );
					setCheckNonce( ( n ) => n + 1 );
				} }
			/>
		);
	} else if ( ! networkOk ) {
		step = <SwitchNetworkStep option={ option } />;
	} else if ( showInsufficient ) {
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
		step = (
			<PayStep
				key={ option.tokenId }
				config={ config }
				option={ option }
				settling={ Boolean( signedPaymentInFlight ) }
				startConfirming={ paymentSubmitted }
				onPaymentSubmitted={ () => setPaymentSubmitted( true ) }
			/>
		);
	}

	return <div className="ax402-steps">{ step }</div>;
}

function PayApp() {
	const config = readConfig();
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
	const [ endpointReady, setEndpointReady ] = useState( () =>
		Boolean( options[ 0 ]?.gatewayUrl || config.gatewayUrl )
	);
	const [ lockError, setLockError ] = useState( '' );
	const [ activeGatewayUrl, setActiveGatewayUrl ] = useState(
		() => options[ 0 ]?.gatewayUrl || config.gatewayUrl || ''
	);
	const [ signedPaymentInFlight, setSignedPaymentInFlight ] =
		useState( false );

	const paywallFetch = useMemo(
		() =>
			wrapPaywallFetch( globalThis.fetch.bind( globalThis ), {
				onPaymentSubmitted: () => {
					setSignedPaymentInFlight( true );
				},
				onPaymentFailed: () => {
					setSignedPaymentInFlight( false );
				},
			} ),
		[]
	);

	useEffect( () => {
		setSignedPaymentInFlight( false );
	}, [ selectedId ] );

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

		setActiveGatewayUrl( selected.gatewayUrl || config.gatewayUrl || '' );
		setEndpointReady( Boolean( selected.gatewayUrl || config.gatewayUrl ) );
		setLockError( '' );

		const url = config.selectSettlementUrl;
		if ( ! url ) {
			return undefined;
		}

		let cancelled = false;

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
				if ( ! cancelled && data?.gateway_url ) {
					setActiveGatewayUrl( data.gateway_url );
				}
			} catch ( e ) {
				if (
					! cancelled &&
					! selected.gatewayUrl &&
					! config.gatewayUrl
				) {
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
	}, [
		selected?.tokenId,
		selected?.gatewayUrl,
		config.selectSettlementUrl,
		config.gatewayUrl,
	] );

	const gatewayUrl = activeGatewayUrl || config.gatewayUrl;
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
	const payConfig = { ...config, gatewayUrl };

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
				locked={ signedPaymentInFlight }
			/>
			<PaywallProvider
				key={ isHederaOption( selected ) ? 'hedera' : 'evm' }
				fetch={ paywallFetch }
				policy={ {
					preferredNetworks: policyNetwork,
					allowedAssets: policyAsset,
					preferredAssets: policyAsset,
					strategy: 'preference-first',
				} }
				hederaWalletConnect={ config.hederaWalletConnect || undefined }
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
					config={ payConfig }
					option={ selected }
					endpointReady={ endpointReady }
					lockError={ lockError }
					signedPaymentInFlight={ signedPaymentInFlight }
				/>
			</PaywallProvider>
		</>
	);
}

const rootEl = document.getElementById( 'ax402-pay-root' );
if ( rootEl ) {
	createRoot( rootEl ).render( <PayApp /> );
}
