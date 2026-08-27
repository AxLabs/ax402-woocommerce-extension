<?php
declare(strict_types=1);

namespace Ax402\WC\Tests\Unit;

use Ax402_WC_Ucp_Mcp;
use Ax402_WC_Ucp_Response;
use PHPUnit\Framework\TestCase;

final class UcpPaymentGuidanceTest extends TestCase
{
    public function test_complete_url_uses_session_id(): void
    {
        $url = Ax402_WC_Ucp_Response::checkout_complete_url('wc_order_abc');
        $this->assertStringContainsString('checkout-sessions/wc_order_abc/complete', $url);
    }

    public function test_payment_required_message_is_wallet_agnostic(): void
    {
        $message = Ax402_WC_Ucp_Response::payment_required_message('wc_order_abc');
        $this->assertSame('error', $message['type']);
        $this->assertSame('payment_required', $message['code']);
        $this->assertSame('recoverable', $message['severity']);
        $this->assertStringContainsString('checkout-sessions/wc_order_abc/complete', $message['content']);
        $this->assertStringContainsString('PAYMENT-SIGNATURE', $message['content']);
        $this->assertStringContainsString('x402/payment', $message['content']);
        $this->assertStringContainsString('resource.url', $message['content']);
        $this->assertStringContainsString('bazaar', $message['content']);
        $this->assertStringContainsString('reconcile', $message['content']);
        $this->assertStringContainsString('payment.instruments', $message['content']);
        $this->assertStringContainsString('x402.assets', $message['content']);
        $this->assertStringContainsString('payment_required.accepts', $message['content']);
        $this->assertStringContainsString('structuredContent', $message['content']);
        $this->assertStringContainsString('payment.payment_signature', $message['content']);
        $this->assertStringContainsString(Ax402_WC_Ucp_Response::HANDLER_SPEC, $message['content']);
        $this->assertStringNotContainsString('not payment_required.resource.url', $message['content']);
        $this->assertStringNotContainsString('saw', strtolower($message['content']));
        $this->assertStringNotContainsString('simple agent wallet', strtolower($message['content']));
    }

    public function test_payment_complete_link(): void
    {
        $link = Ax402_WC_Ucp_Response::payment_complete_link('wc_order_abc');
        $this->assertSame(Ax402_WC_Ucp_Response::LINK_X402_COMPLETE, $link['type']);
        $this->assertStringContainsString('checkout-sessions/wc_order_abc/complete', $link['url']);
        $this->assertNotSame('', $link['title']);
    }

    public function test_complete_checkout_tool_describes_x402_hop(): void
    {
        $tools = Ax402_WC_Ucp_Mcp::tool_descriptors();
        $complete = null;
        foreach ($tools as $tool) {
            if ($tool['name'] === 'complete_checkout') {
                $complete = $tool;
                break;
            }
        }
        $this->assertIsArray($complete);
        $this->assertStringContainsString('PAYMENT-SIGNATURE', $complete['description']);
        $this->assertStringContainsString('payment.payment_signature', $complete['description']);
        $this->assertStringContainsString('structuredContent', $complete['description']);
        $this->assertStringContainsString('org.x402.complete', $complete['description']);
        $this->assertStringContainsString('resource.url', $complete['description']);
        $this->assertStringContainsString('x402/payment', $complete['description']);
        $this->assertStringContainsString('bazaar', $complete['description']);
        $this->assertStringContainsString('reconcile', $complete['description']);
        $this->assertStringContainsString('payment.instruments', $complete['description']);
        $this->assertStringContainsString('github.com/AxLabs/ucp-x402-binding', $complete['description']);
        $this->assertStringNotContainsString('Do not POST payment_required.resource.url', $complete['description']);
        $this->assertStringNotContainsString('saw', strtolower($complete['description']));
    }
}
