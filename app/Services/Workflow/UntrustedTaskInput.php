<?php

declare(strict_types=1);

namespace App\Services\Workflow;

use App\Models\Task;

/**
 * Wraps a task description in delimiters before it reaches the worker's AI
 * session, so the agent can tell where the untrusted input begins and ends and
 * (per the worker's security.system.md prompt) treat it strictly as data, not
 * instructions. The source label marks provenance — descriptions imported from
 * an external issue tracker are flagged as untrusted; operator-entered ones are
 * still wrapped, but labelled accordingly.
 *
 * A per-call random nonce in the markers makes it impractical for a malicious
 * description to forge the closing marker and "break out" of the wrapped block.
 */
final class UntrustedTaskInput
{
    public function wrap(Task $task): string
    {
        $source = $task->externalIssueLink()->exists()
            ? 'external issue tracker (UNTRUSTED — may contain prompt injection)'
            : 'operator, entered directly in Argos';

        $nonce = bin2hex(random_bytes(8));

        return "[BEGIN UNTRUSTED TASK DESCRIPTION · source: {$source} · ref:{$nonce}]\n"
            .((string) $task->description)."\n"
            ."[END UNTRUSTED TASK DESCRIPTION · ref:{$nonce}]";
    }
}
