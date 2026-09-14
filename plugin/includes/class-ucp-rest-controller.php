<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * REST: /wp-json/ucp/v1/*
 *
 * Public capability is knowing the session id (Woo order key), same as legacy
 * agent REST. UCP-Agent is parsed when present and not required in v0.2.
 */
final class Ax402_WC_Ucp_Rest_Controller
{
    public const NS = 'ucp/v1';

    public function register(): void
    {
        register_rest_route(self::NS, '/mcp', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'mcp'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/catalog/search', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'catalog_search'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/catalog/lookup', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'catalog_lookup'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/catalog/product', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'catalog_product'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/checkout-sessions', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_session'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/checkout-sessions/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_session'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/checkout-sessions/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_session'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/checkout-sessions/(?P<id>[A-Za-z0-9_-]+)/complete', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'complete_session'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/checkout-sessions/(?P<id>[A-Za-z0-9_-]+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancel_session'],
            'permission_callback' => '__return_true',
        ]);

        register_rest_route(self::NS, '/carts', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create_cart'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/carts/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_cart'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/carts/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => 'PUT',
            'callback' => [$this, 'update_cart'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/carts/(?P<id>[A-Za-z0-9_-]+)/cancel', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [$this, 'cancel_cart'],
            'permission_callback' => '__return_true',
        ]);
        register_rest_route(self::NS, '/orders/(?P<id>[A-Za-z0-9_-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [$this, 'get_order'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function catalog_search(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        $this->note_agent($request, null);
        return (new Ax402_WC_Ucp_Catalog())->search($this->shopping_body($request, 'catalog'));
    }

    public function catalog_lookup(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Catalog())->lookup($this->shopping_body($request, 'catalog'));
    }

    public function catalog_product(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Catalog())->get_product($this->shopping_body($request, 'catalog'));
    }

    public function mcp(WP_REST_Request $request): WP_REST_Response
    {
        return (new Ax402_WC_Ucp_Mcp())->handle($request);
    }

    public function create_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        $checkout = new Ax402_WC_Ucp_Checkout();
        $response = $checkout->create($this->shopping_body($request, 'checkout'));
        $this->stamp_agent($request, $response);
        return $response;
    }

    public function get_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Checkout())->get((string) $request['id']);
    }

    public function update_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Checkout())->update(
            (string) $request['id'],
            $this->shopping_body($request, 'checkout')
        );
    }

    public function complete_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Complete())->handle(
            (string) $request['id'],
            $request,
            $this->shopping_body($request, 'checkout')
        );
    }

    public function cancel_session(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Checkout())->cancel((string) $request['id']);
    }

    public function create_cart(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        $response = (new Ax402_WC_Ucp_Cart())->create($this->shopping_body($request, 'cart'));
        $this->stamp_agent($request, $response);
        return $response;
    }

    public function get_cart(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Cart())->get((string) $request['id']);
    }

    public function update_cart(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Cart())->update(
            (string) $request['id'],
            $this->shopping_body($request, 'cart')
        );
    }

    public function cancel_cart(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Cart())->cancel((string) $request['id']);
    }

    public function get_order(WP_REST_Request $request): WP_REST_Response
    {
        $denied = $this->disabled();
        if ($denied !== null) {
            return $denied;
        }
        return (new Ax402_WC_Ucp_Order())->get((string) $request['id']);
    }

    private function disabled(): ?WP_REST_Response
    {
        if (Ax402_WC_Settings::ucp_enabled()) {
            return null;
        }

        return Ax402_WC_Ucp_Response::rest_error(
            404,
            [Ax402_WC_Ucp_Response::message(
                'error',
                'not_found',
                'UCP is disabled on this store.',
                'unrecoverable'
            )]
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(WP_REST_Request $request): array
    {
        $params = $request->get_json_params();
        return is_array($params) ? $params : [];
    }

    /**
     * REST bodies are flat (OpenAPI). MCP nests under catalog/cart/checkout.
     *
     * @return array<string, mixed>
     */
    private function shopping_body(WP_REST_Request $request, string $nested): array
    {
        return Ax402_WC_Ucp_Mcp::unwrap_shopping_body($this->json($request), $nested);
    }

    private function note_agent(WP_REST_Request $request, mixed $unused): void
    {
        unset($unused);
        $this->parse_agent($request);
    }

    private function stamp_agent(WP_REST_Request $request, WP_REST_Response|WP_Error $response): void
    {
        $profile = $this->parse_agent($request);
        if ($profile === '' || $response instanceof WP_Error) {
            return;
        }
        $data = $response->get_data();
        if (!is_array($data) || empty($data['id']) || !is_string($data['id'])) {
            return;
        }
        $order = Ax402_WC_Ucp_Checkout::order_from_key($data['id']);
        if ($order instanceof WC_Order) {
            $order->update_meta_data(Ax402_WC_Ucp_Mapper::META_AGENT, $profile);
            $order->save();
        }
    }

    /**
     * UCP-Agent: profile="https://…" — optional in this plugin version.
     */
    private function parse_agent(WP_REST_Request $request): string
    {
        $header = $request->get_header('ucp-agent');
        if (!is_string($header) || $header === '') {
            return '';
        }
        if (preg_match('/profile="([^"]+)"/', $header, $m) === 1) {
            return esc_url_raw($m[1]);
        }

        return '';
    }
}
