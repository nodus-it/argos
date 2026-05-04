<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Admin\Resources\TaskResource\Pages\ViewTaskDiff;
use ReflectionClass;
use Tests\TestCase;

class ViewTaskDiffParserTest extends TestCase
{
    /** @return array<int, array{from_path: string, to_path: string, is_new: bool, is_deleted: bool, additions: int, deletions: int, hunks: list<mixed>}> */
    private function parse(string $content): array
    {
        $page = new ViewTaskDiff;
        $ref = new ReflectionClass($page);
        $method = $ref->getMethod('parseDiffStructured');
        $method->setAccessible(true);

        return $method->invoke($page, $content);
    }

    public function test_empty_content_returns_empty_array(): void
    {
        $this->assertSame([], $this->parse(''));
        $this->assertSame([], $this->parse('   '));
    }

    public function test_regular_tracked_modification_is_parsed(): void
    {
        $diff = <<<'DIFF'
diff --git a/app/Foo.php b/app/Foo.php
index abc1234..def5678 100644
--- a/app/Foo.php
+++ b/app/Foo.php
@@ -1,3 +1,4 @@
 <?php
+declare(strict_types=1);
 class Foo {}
DIFF;

        $files = $this->parse($diff);

        $this->assertCount(1, $files);
        $this->assertSame('app/Foo.php', $files[0]['from_path']);
        $this->assertSame('app/Foo.php', $files[0]['to_path']);
        $this->assertFalse($files[0]['is_new']);
        $this->assertFalse($files[0]['is_deleted']);
        $this->assertSame(1, $files[0]['additions']);
        $this->assertSame(0, $files[0]['deletions']);
    }

    public function test_no_index_new_file_is_parsed_as_new(): void
    {
        $diff = <<<'DIFF'
diff --git a/dev/null b/app/NewFile.php
new file mode 100644
index 0000000..abc1234
--- /dev/null
+++ b/app/NewFile.php
@@ -0,0 +1,3 @@
+<?php
+
+class NewFile {}
DIFF;

        $files = $this->parse($diff);

        $this->assertCount(1, $files);
        $this->assertTrue($files[0]['is_new']);
        $this->assertSame('app/NewFile.php', $files[0]['to_path']);
        $this->assertSame(3, $files[0]['additions']);
        $this->assertSame(0, $files[0]['deletions']);
    }

    public function test_no_index_new_file_hunk_lines_are_all_additions(): void
    {
        $diff = <<<'DIFF'
diff --git a/dev/null b/src/Service.php
new file mode 100644
index 0000000..abc1234
--- /dev/null
+++ b/src/Service.php
@@ -0,0 +1,2 @@
+<?php
+class Service {}
DIFF;

        $files = $this->parse($diff);

        $this->assertCount(1, $files);
        $this->assertCount(1, $files[0]['hunks']);
        $hunk = $files[0]['hunks'][0];
        $this->assertCount(2, $hunk['lines']);
        foreach ($hunk['lines'] as $line) {
            $this->assertSame('add', $line['type']);
        }
    }

    public function test_combined_tracked_change_and_untracked_new_file(): void
    {
        $diff = <<<'DIFF'
diff --git a/app/Existing.php b/app/Existing.php
index abc1234..def5678 100644
--- a/app/Existing.php
+++ b/app/Existing.php
@@ -1,2 +1,3 @@
 <?php
+// changed
 class Existing {}
diff --git a/dev/null b/app/NewFile.php
new file mode 100644
index 0000000..abc1234
--- /dev/null
+++ b/app/NewFile.php
@@ -0,0 +1,2 @@
+<?php
+class NewFile {}
DIFF;

        $files = $this->parse($diff);

        $this->assertCount(2, $files);

        $this->assertFalse($files[0]['is_new']);
        $this->assertSame('app/Existing.php', $files[0]['to_path']);
        $this->assertSame(1, $files[0]['additions']);

        $this->assertTrue($files[1]['is_new']);
        $this->assertSame('app/NewFile.php', $files[1]['to_path']);
        $this->assertSame(2, $files[1]['additions']);
    }

    public function test_deleted_file_is_parsed(): void
    {
        $diff = <<<'DIFF'
diff --git a/app/OldFile.php b/app/OldFile.php
deleted file mode 100644
index abc1234..0000000
--- a/app/OldFile.php
+++ /dev/null
@@ -1,2 +0,0 @@
-<?php
-class OldFile {}
DIFF;

        $files = $this->parse($diff);

        $this->assertCount(1, $files);
        $this->assertTrue($files[0]['is_deleted']);
        $this->assertSame(0, $files[0]['additions']);
        $this->assertSame(2, $files[0]['deletions']);
    }

    public function test_ansi_escape_codes_are_stripped(): void
    {
        $diff = "\033[1mdiff --git a/app/Foo.php b/app/Foo.php\033[0m\n"
            ."index abc..def 100644\n"
            ."--- a/app/Foo.php\n"
            ."+++ b/app/Foo.php\n"
            ."@@ -1 +1,2 @@\n"
            ."\033[32m+line\033[0m\n";

        $files = $this->parse($diff);

        $this->assertCount(1, $files);
        $this->assertSame('app/Foo.php', $files[0]['to_path']);
        $this->assertSame(1, $files[0]['additions']);
    }
}
