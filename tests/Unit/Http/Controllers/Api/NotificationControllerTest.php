<?php

namespace Tests\Unit\Http\Controllers\Api;

use App\Http\Controllers\Api\NotificationController;
use App\Models\User;
use App\Services\Notification\NotificationService;
use Exception;
use Illuminate\Http\Request;
use Tests\Support\CreatesNotificationFixtures;
use Mockery;
use Tests\TestCase;

class NotificationControllerTest extends TestCase
{
    use CreatesNotificationFixtures;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_index_returns_error_response_with_exception_code(): void
    {
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('getUserNotifications')->andThrow(new Exception('Cache failure', 503));

        $controller = new NotificationController($service);
        $response = $controller->index(User::factory()->make());

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Cache failure', $response->getData(true)['message']);
    }

    public function test_index_defaults_to_500_for_invalid_exception_code(): void
    {
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('getUserNotifications')->andThrow(new Exception('Unexpected', 0));

        $controller = new NotificationController($service);
        $response = $controller->index(User::factory()->make());

        $this->assertSame(500, $response->getStatusCode());
    }

    public function test_store_returns_error_response_when_processing_fails(): void
    {
        $service = Mockery::mock(NotificationService::class);
        $service->shouldReceive('processNotification')->andThrow(new Exception('Broker unavailable', 503));

        $controller = new NotificationController($service);

        $user = $this->createUser();

        $request = Request::create('/api/notification', 'POST', [
            'channel' => 'sms',
            'text' => 'Hello',
            'user_ids' => [$user->id],
            'uuid' => '550e8400-e29b-41d4-a716-446655440000',
        ]);
        $request->headers->set('Idempotency-Key', '550e8400-e29b-41d4-a716-446655440000');
        $request->setRouteResolver(fn () => null);

        $formRequest = \App\Http\Requests\Api\NotificationStoreRequest::createFrom($request);
        $formRequest->setContainer($this->app);
        $formRequest->setRedirector($this->app->make('redirect'));
        $formRequest->validateResolved();

        $response = $controller->store($formRequest);

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('Broker unavailable', $response->getData(true)['message']);
    }
}
