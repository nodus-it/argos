<?php

declare(strict_types=1);

namespace App\Workers\Agents;

use App\Enums\AgentName;
use InvalidArgumentException;

/**
 * Code-side registry of every available agent runner.
 *
 * Bound as a singleton in AppServiceProvider. The registry is the
 * single point where the manager learns which agents exist; UI lists,
 * worker spawn paths, and credential validation all read from here.
 *
 * Registration is by class name — each class self-describes via spec()
 * so there is no parallel name-to-class map to keep in sync.
 */
class AgentRegistry
{
    /**
     * @var array<string, class-string<AgentRunner>>
     */
    private array $runners = [];

    /**
     * @param  class-string<AgentRunner>  $runnerClass
     */
    public function register(string $runnerClass): void
    {
        $name = $runnerClass::spec()->name->value;
        $this->runners[$name] = $runnerClass;
    }

    public function has(AgentName $name): bool
    {
        return isset($this->runners[$name->value]);
    }

    public function get(AgentName $name): AgentRunner
    {
        $class = $this->runners[$name->value] ?? null;
        if ($class === null) {
            throw new InvalidArgumentException(
                "Agent '{$name->value}' is not registered."
            );
        }

        return new $class;
    }

    /**
     * @return list<AgentSpec>
     */
    public function specs(): array
    {
        return array_values(array_map(
            fn (string $class) => $class::spec(),
            $this->runners,
        ));
    }

    /**
     * @return list<AgentName>
     */
    public function names(): array
    {
        return array_values(array_map(
            fn (string $value) => AgentName::from($value),
            array_keys($this->runners),
        ));
    }
}
