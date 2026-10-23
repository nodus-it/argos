<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where "Send feedback" leads: ARGOS_FEEDBACK_URL (an internal tracker or a
 * chat channel) or, without it, the upstream issue chooser. A GitHub or
 * GitLab "new issue" link is prefilled with what helps triage — version,
 * page and browser — so testers only write what went wrong.
 */
final class FeedbackLink
{
    public static function url(?string $page = null): ?string
    {
        $configured = trim((string) config('argos.feedback_url'));
        if ($configured === '') {
            $source = rtrim((string) config('argos.source_url'), '/');

            return $source !== '' ? $source.'/issues/new/choose' : null;
        }

        $path = (string) parse_url($configured, PHP_URL_PATH);
        if (! str_ends_with(rtrim($path, '/'), '/issues/new') || parse_url($configured, PHP_URL_QUERY) !== null) {
            return $configured;
        }

        $body = __('common.feedback_button.prefill', [
            'version' => (string) config('argos.version'),
            'page' => $page ?? '',
        ]);
        $gitlab = str_contains($path, '/-/issues/new');

        return $configured.'?'.http_build_query($gitlab
            ? ['issue[title]' => '[Beta] ', 'issue[description]' => $body]
            : ['title' => '[Beta] ', 'body' => $body]);
    }
}
