<?php
declare(strict_types=1);

defined('ABSPATH') || exit;

/**
 * Pure UCP checkout status machine (no WooCommerce types).
 *
 * @phpstan-type StatusResult array{status:string, messages:list<array<string,string>>}
 */
final class Ax402_WC_Ucp_Status
{
    public const INCOMPLETE = 'incomplete';
    public const REQUIRES_ESCALATION = 'requires_escalation';
    public const READY = 'ready_for_complete';
    public const IN_PROGRESS = 'complete_in_progress';
    public const COMPLETED = 'completed';
    public const CANCELED = 'canceled';

    /**
     * @return array{status:string, messages:list<array<string,string>>}
     */
    public static function compute(
        bool $paid,
        bool $canceled,
        bool $needs_shipping,
        bool $has_destination,
        bool $has_shipping_selection,
        bool $has_rates,
        bool $total_positive,
        bool $in_stock
    ): array {
        if ($paid) {
            return ['status' => self::COMPLETED, 'messages' => []];
        }
        if ($canceled) {
            return ['status' => self::CANCELED, 'messages' => []];
        }

        $messages = [];

        if (!$in_stock) {
            $messages[] = Ax402_WC_Ucp_Response::message(
                'error',
                'out_of_stock',
                'One or more items are not available.',
                'recoverable'
            );
        }

        if (!$total_positive) {
            $messages[] = Ax402_WC_Ucp_Response::message(
                'error',
                'invalid',
                'Checkout total must be greater than zero.',
                'recoverable'
            );
        }

        if ($needs_shipping && !$has_destination) {
            $messages[] = Ax402_WC_Ucp_Response::message(
                'error',
                'missing',
                'A fulfillment destination is required.',
                'recoverable',
                '$.fulfillment.methods[0].destinations'
            );
        }

        if ($needs_shipping && $has_destination && !$has_shipping_selection) {
            if ($has_rates) {
                $messages[] = Ax402_WC_Ucp_Response::message(
                    'error',
                    'missing',
                    'Select a shipping option.',
                    'recoverable',
                    '$.fulfillment.methods[0].groups[0].selected_option_id'
                );
            } else {
                $messages[] = Ax402_WC_Ucp_Response::message(
                    'error',
                    'invalid',
                    'No shipping rates are available for this destination.',
                    'requires_buyer_input',
                    '$.fulfillment.methods[0].groups[0].selected_option_id'
                );
            }
        }

        $needs_human = $needs_shipping && $has_destination && !$has_shipping_selection && !$has_rates;
        $blocked = $messages !== [] || !$total_positive || !$in_stock
            || ($needs_shipping && (!$has_destination || !$has_shipping_selection));

        if ($needs_human) {
            return [
                'status' => self::REQUIRES_ESCALATION,
                'messages' => $messages,
            ];
        }

        return [
            'status' => $blocked ? self::INCOMPLETE : self::READY,
            'messages' => $messages,
        ];
    }
}
