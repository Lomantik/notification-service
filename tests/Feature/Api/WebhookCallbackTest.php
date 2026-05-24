<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

class WebhookCallbackTest extends TestCase
{
    public function test_validates_required_provider_id(): void
    {
        $response = $this->postJson('/api/webhooks/gateway/callback', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['provider_id']);
    }

    public function test_validates_required_status(): void
    {
        $response = $this->postJson('/api/webhooks/gateway/callback', [
            'provider_id' => 'sms_test',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_rejects_invalid_provider_status(): void
    {
        $response = $this->postJson('/api/webhooks/gateway/callback', [
            'provider_id' => 'sms_test',
            'status' => 'processing',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }
}
