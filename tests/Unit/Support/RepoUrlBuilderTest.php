<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\RepoUrlBuilder;
use PHPUnit\Framework\TestCase;

class RepoUrlBuilderTest extends TestCase
{
    public function test_github_uses_public_host(): void
    {
        $this->assertSame('https://github.com/acme/widget', RepoUrlBuilder::build('github', 'acme/widget'));
    }

    public function test_bitbucket_uses_public_host(): void
    {
        $this->assertSame('https://bitbucket.org/acme/widget', RepoUrlBuilder::build('bitbucket', 'acme/widget'));
    }

    public function test_gitlab_defaults_to_public_instance(): void
    {
        $this->assertSame('https://gitlab.com/acme/widget', RepoUrlBuilder::build('gitlab', 'acme/widget'));
        $this->assertSame('https://gitlab.com/acme/widget', RepoUrlBuilder::build('gitlab', 'acme/widget', ''));
        $this->assertSame('https://gitlab.com/acme/widget', RepoUrlBuilder::build('gitlab', 'acme/widget', null));
    }

    public function test_gitlab_honours_self_hosted_instance(): void
    {
        $this->assertSame(
            'https://gitlab.example.com/grp/sub/widget',
            RepoUrlBuilder::build('gitlab', 'grp/sub/widget', 'https://gitlab.example.com'),
        );
    }

    public function test_unknown_platform_returns_empty_string(): void
    {
        $this->assertSame('', RepoUrlBuilder::build('forgejo', 'acme/widget'));
    }
}
