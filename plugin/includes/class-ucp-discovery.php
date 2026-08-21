<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Serves GET /.well-known/ucp (outside wp-json).
 */
final class Ax402_WC_Ucp_Discovery
{
    public const QUERY_VAR = 'ax402_ucp_discovery';
    public const REWRITE_FLAG = 'ax402_wc_ucp_rewrite';

    public function register(): void
    {
        add_rewrite_rule('^\.well-known/ucp/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top');
        add_filter('query_vars', [$this, 'query_vars']);
        add_action('template_redirect', [$this, 'maybe_serve'], 0);
        $this->maybe_flush_rewrites();
    }

    /**
     * @param list<string> $vars
     * @return list<string>
     */
    public function query_vars(array $vars): array
    {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    public function maybe_serve(): void
    {
        if ((string) get_query_var(self::QUERY_VAR) !== '1') {
            return;
        }

        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');

        if (!Ax402_WC_Settings::ucp_enabled()) {
            status_header(404);
            echo wp_json_encode([
                'ucp' => [
                    'version' => Ax402_WC_Ucp_Profile_Builder::UCP_VERSION,
                    'status' => 'error',
                ],
                'messages' => [
                    Ax402_WC_Ucp_Response::message(
                        'error',
                        'not_found',
                        'UCP is disabled on this store.',
                        'unrecoverable'
                    ),
                ],
            ]);
            exit;
        }

        $profile = Ax402_WC_Ucp_Profile_Builder::cached();
        status_header(200);
        echo wp_json_encode($profile);
        exit;
    }

    private function maybe_flush_rewrites(): void
    {
        if (get_option(self::REWRITE_FLAG) === '1') {
            return;
        }
        flush_rewrite_rules(false);
        update_option(self::REWRITE_FLAG, '1', false);
    }
}
