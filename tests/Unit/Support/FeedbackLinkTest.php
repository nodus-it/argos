<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\FeedbackLink;
use Tests\TestCase;

class FeedbackLinkTest extends TestCase
{
    public function test_without_a_feedback_url_it_is_the_upstream_issue_chooser(): void
    {
        config(['argos.feedback_url' => '', 'argos.source_url' => 'https://github.com/nodus-it/argos']);

        $this->assertSame('https://github.com/nodus-it/argos/issues/new/choose', FeedbackLink::url('https://argos.test/shop'));
    }

    public function test_a_github_new_issue_link_is_prefilled_with_version_and_page(): void
    {
        config(['argos.feedback_url' => 'https://github.com/acme/argos-beta/issues/new', 'argos.version' => '0.3.0']);

        $url = FeedbackLink::url('https://argos.test/shop/login');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertStringStartsWith('https://github.com/acme/argos-beta/issues/new?', $url);
        $this->assertStringContainsString('Argos 0.3.0 · https://argos.test/shop/login', $query['body']);
    }

    public function test_a_gitlab_link_uses_gitlab_fields_and_other_links_stay_as_they_are(): void
    {
        config(['argos.feedback_url' => 'https://gitlab.com/acme/beta/-/issues/new']);
        parse_str((string) parse_url((string) FeedbackLink::url('p'), PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('issue', $query);

        config(['argos.feedback_url' => 'https://chat.example.com/channels/argos-beta']);
        $this->assertSame('https://chat.example.com/channels/argos-beta', FeedbackLink::url('p'));
    }
}
