<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Cleans concept markdown coming back from the agent before it lands in
 * persistence or the UI. Today this is one rule (strip an outer ```markdown
 * wrapper), but the namespace gives us a single seam for future cleanups —
 * normalising whitespace, fixing heading levels, etc.
 */
final class ConceptMarkdown
{
    /**
     * Strip an outer fence wrapper that the agent sometimes places around its
     * entire reply (e.g. ```markdown … ``` or ````markdown … ```` when the
     * concept itself contains 3-backtick code blocks).
     *
     * Handles:
     *  - 3+ backticks (the agent picks 4 when the body contains 3 itself)
     *  - optional language tag (markdown, md, …)
     *  - unclosed wrapper: when the agent forgets the trailing fence (seen
     *    in production), we still strip the opening one so the UI renders
     *    the body instead of a black code block.
     *
     * A matching closing fence is required to use the SAME backtick count
     * (regex backreference) — inner 3-backtick code blocks inside a
     * 4-backtick wrapper are therefore preserved.
     */
    public static function stripOuterCodeFence(string $md): string
    {
        $trimmed = trim($md);
        if (preg_match('/\A(`{3,})(?:[a-zA-Z0-9_-]+)?\s*\n(.*?)(?:\n\1\s*)?\z/s', $trimmed, $matches) === 1) {
            return $matches[2];
        }

        return $md;
    }
}
