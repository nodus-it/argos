<?php

declare(strict_types=1);

namespace Tests\Feature\GitProvider;

use App\Integrations\GitHub\Requests\GetFileContents;
use App\Services\GitProvider\GitServiceFactory;
use Illuminate\Support\Facades\Http;
use Saloon\Http\Faking\MockResponse;
use Saloon\Laravel\Facades\Saloon;
use Tests\TestCase;

class GetFileContentsTest extends TestCase
{
    private function factory(): GitServiceFactory
    {
        return app(GitServiceFactory::class);
    }

    public function test_github_decodes_base64_file_contents(): void
    {
        Saloon::fake([
            GetFileContents::class => MockResponse::make([
                'content' => base64_encode("FROM php:8.4\n"),
                'encoding' => 'base64',
            ]),
        ]);

        $body = $this->factory()->forPlatform('github', 'tok')
            ->getFileContents('acme/widget', '.argos/worker.dockerfile', 'main');

        $this->assertSame("FROM php:8.4\n", $body);
    }

    public function test_github_returns_null_on_404(): void
    {
        Saloon::fake([
            GetFileContents::class => MockResponse::make('', 404),
        ]);

        $body = $this->factory()->forPlatform('github', 'tok')
            ->getFileContents('acme/widget', '.argos/worker.dockerfile', 'main');

        $this->assertNull($body);
    }

    public function test_gitlab_returns_raw_file_body(): void
    {
        Http::fake([
            'gitlab.com/api/v4/projects/*/repository/files/*/raw*' => Http::response("FROM node:20\n"),
        ]);

        $body = $this->factory()->forPlatform('gitlab', 'tok', 'https://gitlab.com')
            ->getFileContents('group/widget', '.argos/worker.dockerfile', 'main');

        $this->assertSame("FROM node:20\n", $body);
    }

    public function test_bitbucket_returns_raw_file_body(): void
    {
        Http::fake([
            'api.bitbucket.org/2.0/repositories/acme/widget/src/main/*' => Http::response("FROM alpine\n"),
        ]);

        $body = $this->factory()->forPlatform('bitbucket', 'tok')
            ->getFileContents('acme/widget', '.argos/worker.dockerfile', 'main');

        $this->assertSame("FROM alpine\n", $body);
    }
}
