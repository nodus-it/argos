<?php

declare(strict_types=1);

namespace App\Services\Demo;

/**
 * Where a repository keeps its Live-Demo contract: `.argos/demo.yml` (the
 * settings) and `.argos/demo.compose.yml` (the services). Setup and the
 * demo deployer read both paths from here.
 */
final class DemoConfigLocator
{
    public const SETTINGS_PATH = '.argos/demo.yml';

    public const COMPOSE_PATH = '.argos/demo.compose.yml';
}
