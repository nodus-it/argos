<?php

declare(strict_types=1);

namespace App\Services\Credentials;

use App\Services\Anthropic\AnthropicTokenValidator;
use App\Services\IssueTracker\IssueTrackerRegistry;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Performs a live, cheap authentication probe for a credential so the UI can
 * verify it the moment it is created/edited instead of discovering a bad token
 * later during a poll or task run.
 *
 * The distinction between a definitive rejection (auth/4xx → block the save)
 * and a mere "couldn't reach the provider" (network/5xx → allow but don't mark
 * validated) is the whole point — see {@see CredentialVerification}.
 */
class CredentialVerifier
{
    /** Provider statuses that mean "the provider definitively rejected this token". */
    private const REJECTION_STATUSES = [400, 401, 403];

    public function __construct(
        private readonly IssueTrackerRegistry $trackers,
        private readonly AnthropicTokenValidator $anthropic,
    ) {}

    /**
     * Probe a git/issue provider PAT or OAuth token with the cheapest
     * authenticated call we have (listing references).
     */
    public function verifyProvider(string $provider, string $token, string $instanceUrl = ''): CredentialVerification
    {
        try {
            $this->trackers->makeRaw($provider, $token, $instanceUrl)->listReferences();

            return CredentialVerification::valid();
        } catch (Throwable $e) {
            return $this->classify($e);
        }
    }

    /**
     * Probe a Claude OAuth token against the Anthropic API.
     */
    public function verifyClaudeToken(string $token): CredentialVerification
    {
        // null = unreachable, false = rejected, true = valid.
        return match ($this->anthropic->validate($token)) {
            true => CredentialVerification::valid(),
            false => CredentialVerification::rejected(__('credentials.verify.token_rejected')),
            default => CredentialVerification::unreachable(__('credentials.verify.provider_unreachable')),
        };
    }

    private function classify(Throwable $e): CredentialVerification
    {
        $status = $this->httpStatusOf($e);
        $message = $this->shortMessage($e);

        return in_array($status, self::REJECTION_STATUSES, true)
            ? CredentialVerification::rejected($message)
            : CredentialVerification::unreachable($message);
    }

    /**
     * Best-effort extraction of the HTTP status from whichever request
     * exception surfaced — the issue trackers may run on the Laravel HTTP
     * client (Illuminate RequestException, `->response`) or Saloon
     * (`->getResponse()`), so we cover both shapes.
     */
    private function httpStatusOf(Throwable $e): ?int
    {
        if ($e instanceof RequestException) {
            return $e->response->status();
        }

        if (method_exists($e, 'getResponse')) {
            $response = $e->getResponse();
            if (is_object($response) && method_exists($response, 'status')) {
                $status = $response->status();

                return is_int($status) ? $status : null;
            }
        }

        return null;
    }

    /**
     * The provider's error message, capped. Provider 4xx bodies carry the
     * reason (e.g. "remove the Bearer prefix", "Bad credentials") but never the
     * token, so this is safe to surface.
     */
    private function shortMessage(Throwable $e): string
    {
        $message = trim($e->getMessage());

        return mb_strlen($message) > 300 ? mb_substr($message, 0, 300).'…' : $message;
    }
}
