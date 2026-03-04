<?php

use PHPUnit\Framework\TestCase;

final class WCStancerLoggerTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['wc_stancer_test_options'] = [];
    }

    public function test_masks_sensitive_identifiers_in_context(): void
    {
        WC_Stancer_Logger::log(
            'info',
            'Test event',
            [
                'order_id' => 123,
                'intent_id' => 'pi_abcdefghijklmnopqrstuvwxyz',
                'refund_id' => 'refd_abcdefghijklmnopqrstuvwxyz',
                'signature' => 'should_be_dropped',
            ]
        );

        $events = WC_Stancer_Logger::get_events(1);

        self::assertCount(1, $events);
        self::assertSame('123', $events[0]['context']['order_id']);
        self::assertArrayHasKey('intent_id', $events[0]['context']);
        self::assertArrayHasKey('refund_id', $events[0]['context']);
        self::assertStringContainsString('***', $events[0]['context']['intent_id']);
        self::assertStringContainsString('***', $events[0]['context']['refund_id']);
        self::assertArrayNotHasKey('signature', $events[0]['context']);
    }

    public function test_filters_unknown_context_keys(): void
    {
        WC_Stancer_Logger::log(
            'info',
            'Context filtering',
            [
                'order_id' => '789',
                'raw_payload' => '{"secret":"x"}',
                'error_code' => 'stancer_http_4xx',
            ]
        );

        $events = WC_Stancer_Logger::get_events(1);

        self::assertCount(1, $events);
        self::assertSame('789', $events[0]['context']['order_id']);
        self::assertSame('stancer_http_4xx', $events[0]['context']['error_code']);
        self::assertArrayNotHasKey('raw_payload', $events[0]['context']);
    }
}