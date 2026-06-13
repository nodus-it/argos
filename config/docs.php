<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| In-app documentation manifest (feature D)
|--------------------------------------------------------------------------
|
| One source, two surfaces: the MD files under docs/ are readable on GitHub
| AND rendered in-app by the Documentation page (DocsRenderer). This manifest
| is the curated set + ordering shown in-app — operator/user docs only.
| Contributor/architecture docs (CONTRIBUTING.md, PROVIDER-TEST-SETUP.md, the
| docs/backlog/ tree, …) stay repo-only and are deliberately NOT listed here.
|
| Adding a user-facing feature → add a page entry (see CLAUDE.md). Every
| `file` is asserted to exist by DocManifestIntegrityTest.
|
*/

return [
    // Directory the MD files live under, relative to the application base path.
    'path' => 'docs',

    'sections' => [
        [
            'title' => 'Getting Started',
            'pages' => [
                ['slug' => 'setup', 'title' => 'Setup', 'file' => 'SETUP.md'],
                ['slug' => 'prepare-project', 'title' => 'Preparing a Project', 'file' => 'PREPARE-PROJECT.md'],
                ['slug' => 'byoi', 'title' => 'Custom Worker Image (BYOI)', 'file' => 'BYOI.md'],
            ],
        ],
        [
            'title' => 'Integrations',
            'pages' => [
                ['slug' => 'oauth', 'title' => 'OAuth', 'file' => 'OAUTH.md'],
                ['slug' => 'github', 'title' => 'GitHub', 'file' => 'SETUP-GITHUB.md'],
                ['slug' => 'gitlab', 'title' => 'GitLab', 'file' => 'SETUP-GITLAB.md'],
                ['slug' => 'bitbucket', 'title' => 'Bitbucket', 'file' => 'SETUP-BITBUCKET.md'],
                ['slug' => 'linear', 'title' => 'Linear', 'file' => 'SETUP-LINEAR.md'],
                ['slug' => 'task-providers', 'title' => 'Task Providers', 'file' => 'SETUP-TASK-PROVIDERS.md'],
            ],
        ],
        [
            'title' => 'Automation',
            'pages' => [
                ['slug' => 'mcp', 'title' => 'MCP Server', 'file' => 'SETUP-MCP.md'],
                ['slug' => 'rest-api', 'title' => 'REST API', 'file' => 'REST-API.md'],
            ],
        ],
        [
            'title' => 'Operations',
            'pages' => [
                ['slug' => 'configuration', 'title' => 'Configuration', 'file' => 'CONFIGURATION.md'],
                ['slug' => 'execution-commands', 'title' => 'Worker & Demo Commands', 'file' => 'EXECUTION-COMMANDS.md'],
                ['slug' => 'media-library', 'title' => 'Media Library', 'file' => 'SETUP-MEDIA-LIBRARY.md'],
            ],
        ],
    ],
];
