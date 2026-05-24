<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Enums\ProviderCallbackStatus;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Requests\Api\WebhookRequest;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\Support\CreatesNotificationFixtures;
use Tests\TestCase;

class WebhookControllerTest extends TestCase
{
    use CreatesNotificationFixtures;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_422_when_status_enum_is_missing(): void
    {
        $request = Mockery::mock(WebhookRequest::class);
        $request->shouldReceive('validated')->andReturn(['provider_id' => 'sms_test']);
        $request->shouldReceive('enum')->with('status', ProviderCallbackStatus::class)->andReturn(null);

        $response = (new WebhookController)->handleProviderCallback($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame('Invalid status', $response->getData(true)['message']);
    }

    public function test_returns_404_when_provider_mapping_not_found(): void
    {
        $response = $this->postJson('/api/webhooks/gateway/callback', [
            'provider_id' => 'missing_provider',
            'status' => 'delivered',
        ]);

        $response->assertNotFound()
            ->assertJson(['message' => 'Transaction not found']);
    }

    public function test_returns_404_when_delivery_record_missing(): void
    {
        Cache::put('provider_delivery:orphan_provider', 99999, 60);

        $response = $this->postJson('/api/webhooks/gateway/callback', [
            'provider_id' => 'orphan_provider',
            'status' => 'delivered',
        ]);

        $response->assertNotFound()
            ->assertJson(['message' => 'Transaction not found or invalid']);
    }

    public function test_marks_delivery_as_delivered(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());
        Cache::put("provider_delivery:sms_ok", $delivery->id, 60);

        $response = $this->postJson('/api/webhooks/gateway/callback', [
            'provider_id' => 'sms_ok',
            'status' => 'delivered',
        ]);

        $response->assertOk()->assertJson(['status' => 'delivered']);
        $this->assertSame('delivered', $delivery->fresh()->status->value);
        $this->assertNull(Cache::get('provider_delivery:sms_ok'));
    }

    public function test_marks_delivery_as_dropped_on_failed_callback(): void
    {
        $delivery = $this->createDeliveryWithUser($this->createUser());
        Cache::put('provider_delivery:sms_fail', $delivery->id, 60);

        $response = $this->postJson('/api/webhooks/gateway/callback', [
            'provider_id' => 'sms_fail',
            'status' => 'failed',
        ]);

        $response->assertOk()->assertJson(['status' => 'dropped']);
        $this->assertSame('dropped', $delivery->fresh()->status->value);
    }
}
