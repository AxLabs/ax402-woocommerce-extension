<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Woo shipping / local pickup ↔ UCP fulfillment.
 *
 * Checkout `fulfillment.methods[].type` is only `shipping` | `pickup` (UCP
 * 2026-04-08). Virtual/downloadable Woo products are digital goods: they are
 * omitted from checkout methods. `digital` is used on order expectations.
 */
final class Ax402_WC_Ucp_Fulfillment
{
    public const META_DESTINATION = '_ax402_ucp_destination';
    public const META_SHIPPING_OPTION = '_ax402_ucp_shipping_option_id';
    public const META_PICKUP_LOCATION = '_ax402_ucp_pickup_location_id';
    public const META_METHOD_TYPE = '_ax402_ucp_fulfillment_method';

    /**
     * Order-expectation method_type (shipping | pickup | digital).
     */
    public static function expectation_method_type(bool $needs_shipping, bool $pickup_selected): string
    {
        if (!$needs_shipping) {
            return 'digital';
        }

        return $pickup_selected ? 'pickup' : 'shipping';
    }

    public static function is_pickup_method_id(string $method_id): bool
    {
        return $method_id === 'local_pickup' || $method_id === 'pickup_location';
    }

    public static function product_is_digital(WC_Product $product): bool
    {
        return !$product->needs_shipping();
    }

    /**
     * Catalog metadata: Woo already stores virtual / downloadable / shipping.
     *
     * @return array{virtual: bool, downloadable: bool, needs_shipping: bool}
     */
    public static function catalog_metadata(WC_Product $product): array
    {
        return [
            'virtual' => $product->is_virtual(),
            'downloadable' => $product->is_downloadable(),
            'needs_shipping' => $product->needs_shipping(),
        ];
    }

    public static function order_needs_shipping(WC_Order $order): bool
    {
        foreach ($order->get_items() as $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            if ($product instanceof WC_Product && $product->needs_shipping()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $fulfillment
     */
    public static function apply_request(WC_Order $order, array $fulfillment): void
    {
        $methods = $fulfillment['methods'] ?? [];
        if (!is_array($methods) || $methods === []) {
            return;
        }

        foreach ($methods as $method) {
            if (!is_array($method)) {
                continue;
            }
            $type = (string) ($method['type'] ?? '');
            if ($type === 'pickup') {
                self::apply_pickup_method($order, $method);
                continue;
            }
            if ($type === 'shipping') {
                self::apply_shipping_method($order, $method);
            }
        }
    }

    public static function sync_shipping(WC_Order $order): void
    {
        if (!self::order_needs_shipping($order)) {
            $order->remove_order_items('shipping');
            return;
        }

        $rates = self::calculate_rates($order);
        $wanted = (string) $order->get_meta(self::META_SHIPPING_OPTION);
        $chosen = null;
        if ($wanted !== '') {
            foreach ($rates as $rate) {
                if (self::rate_id($rate) === $wanted) {
                    $chosen = $rate;
                    break;
                }
            }
        }
        if ($chosen === null && count($rates) === 1) {
            $chosen = $rates[0];
            $order->update_meta_data(self::META_SHIPPING_OPTION, self::rate_id($chosen));
            $order->update_meta_data(
                self::META_METHOD_TYPE,
                self::is_pickup_method_id($chosen->get_method_id()) ? 'pickup' : 'shipping'
            );
        }

        $order->remove_order_items('shipping');
        if ($chosen instanceof WC_Shipping_Rate) {
            $item = new WC_Order_Item_Shipping();
            $item->set_method_id($chosen->get_method_id());
            $item->set_instance_id($chosen->get_instance_id());
            $item->set_method_title($chosen->get_label());
            $item->set_total((string) $chosen->get_cost());
            $taxes = $chosen->get_taxes();
            if (is_array($taxes) && $taxes !== []) {
                $item->set_taxes(['total' => $taxes]);
            }
            $order->add_item($item);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function map_for_session(WC_Order $order): ?array
    {
        $ship_ids = self::line_item_ids($order, true);
        $digital_ids = self::line_item_ids($order, false);
        unset($digital_ids);

        if ($ship_ids === []) {
            return null;
        }

        $rates = self::calculate_rates($order);
        $shipping_rates = [];
        $pickup_rates = [];
        foreach ($rates as $rate) {
            if (self::is_pickup_method_id($rate->get_method_id())) {
                $pickup_rates[] = $rate;
            } else {
                $shipping_rates[] = $rate;
            }
        }

        $selected = (string) $order->get_meta(self::META_SHIPPING_OPTION);
        $method_type = (string) $order->get_meta(self::META_METHOD_TYPE);
        $methods = [];

        $shipping = [
            'id' => 'shipping_1',
            'type' => 'shipping',
            'line_item_ids' => $ship_ids,
        ];
        $dest = self::stored_destination($order);
        if ($dest !== null) {
            $dest_id = (string) ($dest['id'] ?? 'dest_1');
            $shipping['selected_destination_id'] = $dest_id;
            $shipping['destinations'] = [self::shipping_destination_payload($dest, $order, $dest_id)];
        }
        $shipping['groups'] = [self::option_group('package_1', $ship_ids, $shipping_rates, $selected, $method_type !== 'pickup')];
        $methods[] = $shipping;

        $pickup_locations = self::pickup_locations();
        if ($pickup_locations !== [] || $pickup_rates !== []) {
            if ($pickup_locations === []) {
                $pickup_locations = [self::store_pickup_location()];
            }
            $pickup = [
                'id' => 'pickup_1',
                'type' => 'pickup',
                'line_item_ids' => $ship_ids,
                'destinations' => $pickup_locations,
            ];
            $picked = (string) $order->get_meta(self::META_PICKUP_LOCATION);
            if ($picked !== '') {
                $pickup['selected_destination_id'] = $picked;
            }
            $pickup['groups'] = [self::option_group('pickup_package_1', $ship_ids, $pickup_rates, $selected, $method_type === 'pickup')];
            $methods[] = $pickup;
        }

        return ['methods' => $methods];
    }

    public static function has_destination(WC_Order $order): bool
    {
        if (self::selected_method_type($order) === 'pickup') {
            return self::has_pickup_destination($order);
        }

        return (string) $order->get_meta(self::META_DESTINATION) !== ''
            || $order->get_shipping_country() !== '';
    }

    public static function has_pickup_destination(WC_Order $order): bool
    {
        return (string) $order->get_meta(self::META_PICKUP_LOCATION) !== '';
    }

    public static function selected_method_type(WC_Order $order): string
    {
        $stored = (string) $order->get_meta(self::META_METHOD_TYPE);
        if ($stored === 'pickup' || $stored === 'shipping') {
            return $stored;
        }
        foreach ($order->get_items('shipping') as $item) {
            if (!$item instanceof WC_Order_Item_Shipping) {
                continue;
            }
            if (self::is_pickup_method_id((string) $item->get_method_id())) {
                return 'pickup';
            }

            return 'shipping';
        }

        return '';
    }

    public static function has_shipping_selection(WC_Order $order): bool
    {
        if ((string) $order->get_meta(self::META_SHIPPING_OPTION) !== '') {
            return true;
        }
        foreach ($order->get_items('shipping') as $item) {
            if ($item instanceof WC_Order_Item_Shipping) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<WC_Shipping_Rate>
     */
    public static function calculate_rates(WC_Order $order): array
    {
        if (!self::ensure_shipping_runtime()) {
            return [];
        }

        $country = $order->get_shipping_country();
        $state = $order->get_shipping_state();
        $postcode = $order->get_shipping_postcode();
        $city = $order->get_shipping_city();
        if ($country === '' && self::has_pickup_destination($order)) {
            $store = self::store_address();
            $country = $store['address_country'];
            $state = $store['address_region'];
            $postcode = $store['postal_code'];
            $city = $store['address_locality'];
        }

        if (WC()->customer) {
            WC()->customer->set_shipping_location($country, $state, $postcode, $city);
            WC()->customer->set_billing_location(
                $order->get_billing_country() ?: $country,
                $order->get_billing_state() ?: $state,
                $order->get_billing_postcode() ?: $postcode,
                $order->get_billing_city() ?: $city
            );
        }
        WC()->shipping()->reset_shipping();

        $package = self::package_from_order($order, $country, $state, $postcode, $city);
        if ($package['contents'] === []) {
            return [];
        }

        try {
            $calculated = WC()->shipping()->calculate_shipping([$package]);
        } catch (Throwable $e) {
            return [];
        }
        $rates = [];
        foreach ($calculated as $pkg) {
            if (!is_array($pkg) || empty($pkg['rates']) || !is_array($pkg['rates'])) {
                continue;
            }
            foreach ($pkg['rates'] as $rate) {
                if ($rate instanceof WC_Shipping_Rate) {
                    $rates[] = $rate;
                }
            }
        }

        return $rates;
    }

    /**
     * @param array<string, mixed> $method
     */
    private static function apply_shipping_method(WC_Order $order, array $method): void
    {
        $destinations = $method['destinations'] ?? [];
        $selected_id = (string) ($method['selected_destination_id'] ?? '');
        $dest = self::pick_destination(is_array($destinations) ? $destinations : [], $selected_id);
        if ($dest !== null) {
            self::apply_address($order, $dest);
            $order->update_meta_data(self::META_DESTINATION, wp_json_encode($dest));
        }

        $option_id = self::selected_option_id($method);
        if ($option_id !== '') {
            $order->update_meta_data(self::META_SHIPPING_OPTION, $option_id);
            $order->update_meta_data(self::META_METHOD_TYPE, 'shipping');
        } elseif ($dest !== null) {
            $order->update_meta_data(self::META_METHOD_TYPE, 'shipping');
        }
    }

    /**
     * @param array<string, mixed> $method
     */
    private static function apply_pickup_method(WC_Order $order, array $method): void
    {
        $selected = (string) ($method['selected_destination_id'] ?? '');
        if ($selected === '') {
            $destinations = $method['destinations'] ?? [];
            if (is_array($destinations) && $destinations !== []) {
                $first = $destinations[0];
                if (is_array($first)) {
                    $selected = (string) ($first['id'] ?? '');
                }
            }
        }
        if ($selected !== '') {
            $order->update_meta_data(self::META_PICKUP_LOCATION, $selected);
        }

        $option_id = self::selected_option_id($method);
        if ($option_id !== '') {
            $order->update_meta_data(self::META_SHIPPING_OPTION, $option_id);
            $order->update_meta_data(self::META_METHOD_TYPE, 'pickup');
        } elseif ($selected !== '') {
            $order->update_meta_data(self::META_METHOD_TYPE, 'pickup');
        }
    }

    /**
     * @param list<array<string, mixed>> $destinations
     * @return array<string, mixed>|null
     */
    private static function pick_destination(array $destinations, string $selected_id): ?array
    {
        if ($destinations === []) {
            return null;
        }
        if ($selected_id !== '') {
            foreach ($destinations as $dest) {
                if (is_array($dest) && (string) ($dest['id'] ?? '') === $selected_id) {
                    return self::normalize_destination($dest);
                }
            }
        }
        $first = $destinations[0];
        return is_array($first) ? self::normalize_destination($first) : null;
    }

    /**
     * @param array<string, mixed> $dest
     * @return array<string, mixed>
     */
    public static function coerce_destination(array $dest): array
    {
        return self::normalize_destination($dest);
    }

    /**
     * @param array<string, mixed> $dest
     * @return array<string, mixed>
     */
    private static function normalize_destination(array $dest): array
    {
        $id = (string) ($dest['id'] ?? '');
        if ($id === '') {
            $id = 'dest_1';
        }
        $address = is_array($dest['address'] ?? null) ? $dest['address'] : $dest;

        return [
            'id' => $id,
            'type' => (string) ($dest['type'] ?? 'shipping_address'),
            'street_address' => self::first_postal_field($address, $dest, [
                'street_address',
                'address_line_1',
                'address_line1',
                'address1',
            ]),
            'address_locality' => self::first_postal_field($address, $dest, [
                'address_locality',
                'city',
                'town',
            ]),
            'address_region' => self::first_postal_field($address, $dest, [
                'address_region',
                'region',
                'state',
                'province',
            ]),
            'postal_code' => self::first_postal_field($address, $dest, [
                'postal_code',
                'postcode',
                'zip',
                'zip_code',
            ]),
            'address_country' => strtoupper(self::first_postal_field($address, $dest, [
                'address_country',
                'country',
            ])),
        ];
    }

    /**
     * Official UCP postal keys first; common agent aliases after.
     *
     * @param array<string, mixed> $address
     * @param array<string, mixed> $dest
     * @param list<string> $keys
     */
    public static function first_postal_field(array $address, array $dest, array $keys): string
    {
        foreach ($keys as $key) {
            foreach ([$address, $dest] as $bag) {
                if (!isset($bag[$key]) || !is_scalar($bag[$key])) {
                    continue;
                }
                $value = trim((string) $bag[$key]);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $dest
     */
    private static function apply_address(WC_Order $order, array $dest): void
    {
        $country = (string) ($dest['address_country'] ?? '');
        $state = (string) ($dest['address_region'] ?? '');
        $city = (string) ($dest['address_locality'] ?? '');
        $postcode = (string) ($dest['postal_code'] ?? '');
        $address = (string) ($dest['street_address'] ?? '');

        $order->set_shipping_country($country);
        $order->set_shipping_state($state);
        $order->set_shipping_city($city);
        $order->set_shipping_postcode($postcode);
        $order->set_shipping_address_1($address);

        if ($order->get_billing_country() === '') {
            $order->set_billing_country($country);
            $order->set_billing_state($state);
            $order->set_billing_city($city);
            $order->set_billing_postcode($postcode);
            $order->set_billing_address_1($address);
        }
    }

    /**
     * @param array<string, mixed> $method
     */
    private static function selected_option_id(array $method): string
    {
        if (!empty($method['selected_option_id'])) {
            return (string) $method['selected_option_id'];
        }
        $groups = $method['groups'] ?? [];
        if (!is_array($groups)) {
            return '';
        }
        foreach ($groups as $group) {
            if (is_array($group) && !empty($group['selected_option_id'])) {
                return (string) $group['selected_option_id'];
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function stored_destination(WC_Order $order): ?array
    {
        $raw = (string) $order->get_meta(self::META_DESTINATION);
        if ($raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string, mixed> $dest
     * @return array<string, mixed>
     */
    private static function shipping_destination_payload(array $dest, WC_Order $order, string $dest_id): array
    {
        return [
            'id' => $dest_id,
            'street_address' => (string) ($dest['street_address'] ?? $order->get_shipping_address_1()),
            'address_locality' => (string) ($dest['address_locality'] ?? $order->get_shipping_city()),
            'address_region' => (string) ($dest['address_region'] ?? $order->get_shipping_state()),
            'postal_code' => (string) ($dest['postal_code'] ?? $order->get_shipping_postcode()),
            'address_country' => (string) ($dest['address_country'] ?? $order->get_shipping_country()),
        ];
    }

    /**
     * @param list<string> $line_ids
     * @param list<WC_Shipping_Rate> $rates
     * @return array<string, mixed>
     */
    private static function option_group(
        string $id,
        array $line_ids,
        array $rates,
        string $selected,
        bool $apply_selection
    ): array {
        $options = [];
        foreach ($rates as $rate) {
            $cost = Ax402_WC_Ucp_Money::usd_to_cents_ceil((string) $rate->get_cost()) ?? 0;
            $options[] = [
                'id' => self::rate_id($rate),
                'title' => $rate->get_label(),
                'totals' => [
                    ['type' => 'total', 'amount' => $cost],
                ],
            ];
        }
        $group = [
            'id' => $id,
            'line_item_ids' => $line_ids,
            'options' => $options,
        ];
        if ($apply_selection && $selected !== '') {
            $group['selected_option_id'] = $selected;
        }

        return $group;
    }

    /**
     * @return list<string>
     */
    public static function line_item_ids(WC_Order $order, ?bool $needs_shipping = null): array
    {
        $ids = [];
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            if ($needs_shipping !== null) {
                $product = $item->get_product();
                $ships = $product instanceof WC_Product && $product->needs_shipping();
                if ($ships !== $needs_shipping) {
                    continue;
                }
            }
            $ids[] = 'li_' . (string) $item_id;
        }

        return $ids;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function pickup_locations(): array
    {
        $rows = get_option('pickup_location_pickup_locations', []);
        if (!is_array($rows) || $rows === []) {
            $rows = get_option('woocommerce_pickup_location_pickup_locations', []);
        }
        $out = [];
        if (is_array($rows)) {
            $i = 0;
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $enabled = $row['enabled'] ?? true;
                if ($enabled === false || $enabled === 'no') {
                    continue;
                }
                $i++;
                $address = is_array($row['address'] ?? null) ? $row['address'] : $row;
                $out[] = [
                    'id' => (string) ($row['id'] ?? ('pickup_loc_' . $i)),
                    'name' => (string) ($row['name'] ?? ('Pickup ' . $i)),
                    'address' => [
                        'street_address' => (string) ($address['address_1'] ?? $address['street_address'] ?? ''),
                        'extended_address' => (string) ($address['address_2'] ?? $address['extended_address'] ?? ''),
                        'address_locality' => (string) ($address['city'] ?? $address['address_locality'] ?? ''),
                        'address_region' => (string) ($address['state'] ?? $address['address_region'] ?? ''),
                        'postal_code' => (string) ($address['postcode'] ?? $address['postal_code'] ?? ''),
                        'address_country' => strtoupper((string) ($address['country'] ?? $address['address_country'] ?? '')),
                    ],
                ];
            }
        }
        if ($out !== []) {
            return $out;
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function store_pickup_location(): array
    {
        $address = self::store_address();
        $name = function_exists('get_bloginfo') ? (string) get_bloginfo('name') : 'Store pickup';

        return [
            'id' => 'pickup_store',
            'name' => $name !== '' ? $name : 'Store pickup',
            'address' => $address,
        ];
    }

    /**
     * @return array{street_address:string,extended_address:string,address_locality:string,address_region:string,postal_code:string,address_country:string}
     */
    public static function store_address(): array
    {
        $country_state = (string) get_option('woocommerce_default_country', '');
        $country = $country_state;
        $region = '';
        if (str_contains($country_state, ':')) {
            [$country, $region] = explode(':', $country_state, 2);
        }

        return [
            'street_address' => (string) get_option('woocommerce_store_address', ''),
            'extended_address' => (string) get_option('woocommerce_store_address_2', ''),
            'address_locality' => (string) get_option('woocommerce_store_city', ''),
            'address_region' => $region,
            'postal_code' => (string) get_option('woocommerce_store_postcode', ''),
            'address_country' => strtoupper($country),
        ];
    }

    /**
     * Billing or shop address for order-expectation destination (all fields optional).
     *
     * @return array<string, string>
     */
    public static function postal_from_order(WC_Order $order): array
    {
        $country = $order->get_shipping_country() ?: $order->get_billing_country();
        $out = [
            'street_address' => $order->get_shipping_address_1() ?: $order->get_billing_address_1(),
            'address_locality' => $order->get_shipping_city() ?: $order->get_billing_city(),
            'address_region' => $order->get_shipping_state() ?: $order->get_billing_state(),
            'postal_code' => $order->get_shipping_postcode() ?: $order->get_billing_postcode(),
            'address_country' => $country,
            'first_name' => $order->get_shipping_first_name() ?: $order->get_billing_first_name(),
            'last_name' => $order->get_shipping_last_name() ?: $order->get_billing_last_name(),
        ];
        $filtered = array_filter($out, static fn (string $v): bool => $v !== '');
        if ($filtered !== []) {
            return $filtered;
        }

        return array_filter(self::store_address(), static fn (string $v): bool => $v !== '');
    }

    public static function rate_id(WC_Shipping_Rate $rate): string
    {
        $id = (string) $rate->get_id();
        return $id !== '' ? $id : $rate->get_method_id() . ':' . $rate->get_instance_id();
    }

    /**
     * Woo REST/MCP has no storefront session. Shipping rates call
     * WC()->session->get() and fatals if session is null.
     */
    private static function ensure_shipping_runtime(): bool
    {
        if (!function_exists('WC')) {
            return false;
        }
        $woo = WC();
        if ($woo === null) {
            return false;
        }
        if ($woo->session === null && method_exists($woo, 'initialize_session')) {
            $woo->initialize_session();
        }
        if (($woo->customer === null || $woo->cart === null) && method_exists($woo, 'initialize_cart')) {
            $woo->initialize_cart();
        }

        return $woo->shipping() !== null && $woo->session !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function package_from_order(
        WC_Order $order,
        string $country = '',
        string $state = '',
        string $postcode = '',
        string $city = ''
    ): array {
        $contents = [];
        $cost = 0.0;
        foreach ($order->get_items() as $item_id => $item) {
            if (!$item instanceof WC_Order_Item_Product) {
                continue;
            }
            $product = $item->get_product();
            if (!$product instanceof WC_Product || !$product->needs_shipping()) {
                continue;
            }
            $contents[$item_id] = [
                'data' => $product,
                'quantity' => $item->get_quantity(),
                'line_total' => (float) $item->get_total(),
                'line_tax' => (float) $item->get_total_tax(),
                'line_subtotal' => (float) $item->get_subtotal(),
                'line_subtotal_tax' => (float) $item->get_subtotal_tax(),
            ];
            $cost += (float) $item->get_total();
        }

        return [
            'contents' => $contents,
            'contents_cost' => $cost,
            'applied_coupons' => [],
            'user' => ['ID' => $order->get_customer_id()],
            'destination' => [
                'country' => $country !== '' ? $country : $order->get_shipping_country(),
                'state' => $state !== '' ? $state : $order->get_shipping_state(),
                'postcode' => $postcode !== '' ? $postcode : $order->get_shipping_postcode(),
                'city' => $city !== '' ? $city : $order->get_shipping_city(),
                'address' => $order->get_shipping_address_1(),
                'address_2' => $order->get_shipping_address_2(),
            ],
            'cart_subtotal' => $cost,
        ];
    }
}
