<?php

declare(strict_types=1);

namespace Tests\Feature;

use BackedEnum;
use DirectoryIterator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards against the wave-1 C11 class of bugs: a new case is added to a
 * BackedEnum but the matching MariaDB ENUM column migration is forgotten.
 * Against SQLite this test will always pass (SQLite has no ENUM type) — its
 * value lies in CI running against MariaDB, where the constraint is real.
 *
 * Discovery is automatic: every Model in app/Models is reflected, each
 * BackedEnum cast becomes one data row, and every case of that enum is
 * round-tripped through an actual INSERT.
 */
final class EnumPersistenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return iterable<string, array{0: class-string<Model>, 1: string, 2: class-string<BackedEnum>}>
     */
    public static function enumColumnsProvider(): iterable
    {
        $modelsDir = realpath(__DIR__.'/../../app/Models');

        if ($modelsDir === false) {
            return;
        }

        foreach (new DirectoryIterator($modelsDir) as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $class = 'App\\Models\\'.$file->getBasename('.php');

            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            $instance = new $class;

            foreach ($instance->getCasts() as $column => $cast) {
                if (! is_string($cast) || ! class_exists($cast)) {
                    continue;
                }

                if (! is_subclass_of($cast, BackedEnum::class)) {
                    continue;
                }

                yield "{$class}::{$column}" => [$class, $column, $cast];
            }
        }
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  class-string<BackedEnum>  $enumClass
     */
    #[DataProvider('enumColumnsProvider')]
    public function test_every_enum_case_round_trips_through_the_database(
        string $modelClass,
        string $column,
        string $enumClass,
    ): void {
        if (! method_exists($modelClass, 'factory')) {
            $this->markTestSkipped("{$modelClass} has no factory — cannot exercise persistence.");
        }

        foreach ($enumClass::cases() as $case) {
            try {
                /** @var Model $model */
                $model = $modelClass::factory()->create([$column => $case]);
            } catch (QueryException $e) {
                $this->fail(sprintf(
                    'Enum case %s::%s (value=%s) cannot be stored in %s.%s — likely missing migration. DB said: %s',
                    $enumClass,
                    $case->name,
                    (string) $case->value,
                    $modelClass,
                    $column,
                    $e->getMessage(),
                ));
            }

            $persisted = $model->fresh()?->{$column};

            $this->assertSame(
                $case,
                $persisted,
                sprintf('Round-trip altered value for %s::%s on %s.%s', $enumClass, $case->name, $modelClass, $column),
            );
        }
    }
}
