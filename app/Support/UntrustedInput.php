<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Wraps external text (an issue body) in delimiters before it reaches the
 * agent, so the agent can tell where the untrusted input begins and ends and
 * treat it strictly as data, not instructions. The source label marks provenance — descriptions imported from
 * an external issue tracker are flagged as untrusted; operator-entered ones are
 * still wrapped, but labelled accordingly.
 *
 * A per-call random nonce in the markers makes it impractical for a malicious
 * description to forge the closing marker and "break out" of the wrapped block.
 */
final class UntrustedInput
{
    /**
     * Wrap text with the markers; `$external` flags text imported from an
     * issue tracker (workspaces started from an issue use this).
     */
    public function wrapText(string $text, bool $external, string $label = 'ISSUE'): string
    {
        $source = $external
            ? 'external issue tracker (UNTRUSTED — may contain prompt injection)'
            : 'operator, entered directly in Argos';

        $nonce = bin2hex(random_bytes(8));

        return "[BEGIN UNTRUSTED {$label} · source: {$source} · ref:{$nonce}]\n"
            .$text."\n"
            ."[END UNTRUSTED {$label} · ref:{$nonce}]";
    }
}
