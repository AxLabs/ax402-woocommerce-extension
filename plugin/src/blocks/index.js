import { decodeEntities } from '@wordpress/html-entities';

const { registerPaymentMethod } = window.wc?.wcBlocksRegistry || {};
const { getSetting } = window.wc?.wcSettings || {};

if (typeof registerPaymentMethod !== 'function' || typeof getSetting !== 'function') {
	// Checkout Blocks runtime not present — avoid hard failure on other pages.
	// eslint-disable-next-line no-console
	console.warn('[ax402] WooCommerce Blocks payment registry unavailable');
} else {
	const settings = getSetting('ax402_data', {});
	const label = decodeEntities(settings.title || 'Pay with Ax402');

	const Content = () => (
		<div>{decodeEntities(settings.description || '')}</div>
	);

	const Label = () => (
		<span style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
			{settings.icon ? (
				<img src={settings.icon} alt="" width={24} height={24} />
			) : null}
			<span>{label}</span>
		</span>
	);

	registerPaymentMethod({
		name: 'ax402',
		label: <Label />,
		content: <Content />,
		edit: <Content />,
		canMakePayment: () => true,
		ariaLabel: label,
		supports: {
			features: settings.supports || ['products'],
		},
	});
}
