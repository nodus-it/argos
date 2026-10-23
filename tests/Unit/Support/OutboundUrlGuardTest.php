<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Integrations\TicketWebhook\Requests\PostTicketEvent;
use App\Integrations\TicketWebhook\TicketWebhookConnector;
use App\Support\OutboundUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class OutboundUrlGuardTest extends TestCase
{
    /** @return array<string, array{string}> */
    public static function refused(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:6379/'],
            'localhost' => ['http://localhost/'],
            'cloud metadata' => ['http://169.254.169.254/latest/meta-data/'],
            'ipv6 loopback' => ['http://[::1]/'],
            'mapped loopback' => ['http://[::ffff:127.0.0.1]/'],
            'database' => ['http://db:3306/'],
            'redis' => ['http://redis:6379/'],
            'app container' => ['http://nginx/internal/workspaces/x/repo-credential'],
            'workspace container' => ['http://argos-ws-shop:7777/health'],
            'other scheme' => ['file:///etc/passwd'],
            'gopher' => ['gopher://example.com/'],
        ];
    }

    #[DataProvider('refused')]
    public function test_internal_targets_are_refused(string $url): void
    {
        $this->assertNotNull(OutboundUrlGuard::refusal($url));
    }

    public function test_public_and_private_company_hosts_are_allowed(): void
    {
        $this->assertNull(OutboundUrlGuard::refusal('https://gitlab.com/api/v4'));
        // A company GitLab on a private network stays reachable.
        $this->assertNull(OutboundUrlGuard::refusal('https://10.20.30.40/api/v4'));
        $this->assertNull(OutboundUrlGuard::refusal('https://192.168.1.10/hooks/argos'));
    }

    public function test_a_ticket_webhook_never_reaches_an_internal_service(): void
    {
        Saloon::fake([MockResponse::make([], 200)]);

        $this->expectException(RuntimeException::class);
        (new TicketWebhookConnector('http://redis:6379'))->send(new PostTicketEvent('{}', 'x', 'sig'));
    }
}
