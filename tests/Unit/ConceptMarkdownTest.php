<?php

declare(strict_types=1);

use App\Support\ConceptMarkdown;

it('strips a 3-backtick wrapper with markdown language tag', function (): void {
    $wrapped = "```markdown\n# Konzept\n\nBody.\n```";
    expect(ConceptMarkdown::stripOuterCodeFence($wrapped))->toBe("# Konzept\n\nBody.");
});

it('strips a 3-backtick wrapper without language tag', function (): void {
    $wrapped = "```\n# Konzept\n\nBody.\n```";
    expect(ConceptMarkdown::stripOuterCodeFence($wrapped))->toBe("# Konzept\n\nBody.");
});

it('strips a 4-backtick wrapper, preserving inner 3-backtick code blocks', function (): void {
    // Real production pattern: agent picks 4 backticks when the concept body
    // itself contains 3-backtick code fences.
    $wrapped = "````markdown\n# Konzept\n\n```bash\nls\n```\n\nDone.\n````";
    $expected = "# Konzept\n\n```bash\nls\n```\n\nDone.";
    expect(ConceptMarkdown::stripOuterCodeFence($wrapped))->toBe($expected);
});

it('strips an unclosed 4-backtick wrapper (production bug 01krgv0rb4as)', function (): void {
    // Captured from production: agent opened ````markdown but never wrote a
    // closing fence. The body ends with regular text + newline.
    $wrapped = "````markdown\n# Konzept: Foo\n\nBody mit ```bash\nls\n``` Snippet.\n";
    $expected = "# Konzept: Foo\n\nBody mit ```bash\nls\n``` Snippet.";
    expect(ConceptMarkdown::stripOuterCodeFence($wrapped))->toBe($expected);
});

it('strips an unclosed 3-backtick wrapper', function (): void {
    $wrapped = "```markdown\n# Konzept\n\nBody no closing fence\n";
    $expected = "# Konzept\n\nBody no closing fence";
    expect(ConceptMarkdown::stripOuterCodeFence($wrapped))->toBe($expected);
});

it('leaves plain markdown without an outer wrapper untouched', function (): void {
    $plain = "# Konzept\n\nNo wrapper here.\n";
    expect(ConceptMarkdown::stripOuterCodeFence($plain))->toBe($plain);
});

it('leaves markdown with only inner code blocks untouched', function (): void {
    $md = "# Konzept\n\nSome text.\n\n```bash\nls\n```\n\nMore text.\n";
    expect(ConceptMarkdown::stripOuterCodeFence($md))->toBe($md);
});

it('returns empty string unchanged', function (): void {
    expect(ConceptMarkdown::stripOuterCodeFence(''))->toBe('');
});
