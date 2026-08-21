<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Mcp;
use Ax402_WC_Ucp_Mcp_Payment;
use PHPUnit\Framework\TestCase;

final class UcpMcpPaymentTest extends TestCase
{
    public function test_round_trip_header_payload(): void
    {
        $payload = [
            'x402Version' => 2,
            'resource' => ['url' => 'https://shop.dev.x.ax402.io/pay'],
            'accepts' => [['network' => 'eip155:8453', 'amount' => '10000']],
        ];
        $header = Ax402_WC_Ucp_Mcp_Payment::encode_header_payload($payload);
        $this->assertNotSame('', $header);
        $this->assertSame($payload, Ax402_WC_Ucp_Mcp_Payment::decode_header($header));
    }

    public function test_encode_passes_through_header_string(): void
    {
        $header = Ax402_WC_Ucp_Mcp_Payment::encode_header_payload(['a' => 1]);
        $this->assertSame($header, Ax402_WC_Ucp_Mcp_Payment::encode_header_payload($header));
    }

    public function test_signature_from_x402_payment_meta(): void
    {
        $payment = ['x402Version' => 2, 'payload' => ['signature' => '0xabc']];
        $encoded = Ax402_WC_Ucp_Mcp_Payment::signature_from_call(
            ['_meta' => ['x402/payment' => $payment]],
            ['id' => 'wc_order_1', 'checkout' => []]
        );
        $this->assertSame($payment, Ax402_WC_Ucp_Mcp_Payment::decode_header($encoded));
    }

    public function test_signature_from_checkout_payment_field(): void
    {
        $header = Ax402_WC_Ucp_Mcp_Payment::encode_header_payload(['payload' => ['signature' => '0xdef']]);
        $encoded = Ax402_WC_Ucp_Mcp_Payment::signature_from_body([
            'payment' => [
                'instruments' => [[
                    'id' => 'instr_x402_1',
                    'handler_id' => 'org.x402.payment',
                    'type' => 'x402',
                    'selected' => true,
                ]],
                'payment_signature' => $header,
            ],
        ]);
        $this->assertSame($header, $encoded);
    }

    public function test_inject_signature_survives_call_parts(): void
    {
        $injected = Ax402_WC_Ucp_Mcp_Payment::inject_signature(
            [
                'meta' => [],
                'id' => 'wc_order_abc',
                'checkout' => ['payment' => ['instruments' => [['type' => 'x402']]]],
            ],
            'sig-value',
            'sig-data'
        );
        $parts = Ax402_WC_Ucp_Mcp::call_parts('complete_checkout', $injected);
        $this->assertSame('wc_order_abc', $parts['id']);
        $this->assertSame('sig-value', $parts['body']['payment']['payment_signature']);
        $this->assertSame('sig-data', $parts['body']['payment']['payment_signature_data']);
        $this->assertSame('x402', $parts['body']['payment']['instruments'][0]['type']);
    }

    public function test_attach_payment_required_to_tool_result(): void
    {
        $required = ['maxAmountRequired' => '10000', 'network' => 'eip155:8453'];
        $header = Ax402_WC_Ucp_Mcp_Payment::encode_header_payload($required);
        $attached = Ax402_WC_Ucp_Mcp_Payment::attach_to_tool_result(
            [
                'id' => 'wc_order_1',
                'status' => 'ready_for_complete',
                'messages' => [['code' => 'payment_required']],
            ],
            ['PAYMENT-REQUIRED' => $header]
        );
        $this->assertSame($required, $attached['data']['payment_required']);
        $this->assertSame($required, $attached['meta']['x402/payment-required']);
        $this->assertSame('ready_for_complete', $attached['data']['status']);
    }

    public function test_attach_payment_response_meta(): void
    {
        $receipt = ['success' => true, 'transaction' => '0x1'];
        $header = Ax402_WC_Ucp_Mcp_Payment::encode_header_payload($receipt);
        $attached = Ax402_WC_Ucp_Mcp_Payment::attach_to_tool_result(
            ['status' => 'completed'],
            ['payment-response' => $header]
        );
        $this->assertSame($receipt, $attached['meta']['x402/payment-response']);
        $this->assertArrayNotHasKey('payment_required', $attached['data']);
    }

    public function test_tool_result_keeps_402_as_success_jsonrpc(): void
    {
        $payload = Ax402_WC_Ucp_Mcp::tool_result_payload(
            ['payment_required' => ['x402Version' => 2]],
            402,
            ['x402/payment-required' => ['x402Version' => 2]]
        );
        $this->assertFalse($payload['isError']);
        $this->assertSame(2, $payload['structuredContent']['payment_required']['x402Version']);
        $this->assertSame(2, $payload['_meta']['x402/payment-required']['x402Version']);
        $decoded = json_decode($payload['content'][0]['text'], true);
        $this->assertSame(2, $decoded['payment_required']['x402Version']);
    }

    public function test_tool_result_marks_other_http_errors(): void
    {
        $payload = Ax402_WC_Ucp_Mcp::tool_result_payload(['messages' => []], 500);
        $this->assertTrue($payload['isError']);
        $this->assertArrayNotHasKey('_meta', $payload);
    }
}
