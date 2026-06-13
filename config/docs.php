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

    // Locales that ship a FULL translation under docs/<locale>/. English is
    // always the reference source; translations are kept in sync from it (never
    // the other way around). A missing translation falls back to English.
    // DocTranslationFreshnessTest enforces that each listed locale has an
    // up-to-date copy of every manifest page.
    'translations' => ['de'],

    'sections' => [
        [
            'title' => 'Introduction',
            'pages' => [
                ['slug' => 'overview', 'title' => 'How Argos Works', 'file' => 'OVERVIEW.md'],
            ],
        ],
        [
            'title' => 'Getting Started',
            'pages' => [
                ['slug' => 'setup', 'title' => 'Setup', 'file' => 'SETUP.md'],
                ['slug' => 'prepare-project', 'title' => 'Preparing a Project', 'file' => 'PREPARE-PROJECT.md'],
            ],
        ],
        [
            'title' => 'Concepts',
            'pages' => [
                ['slug' => 'projects', 'title' => 'Projects', 'file' => 'PROJECTS.md'],
                ['slug' => 'tasks', 'title' => 'Tasks & Workflow', 'file' => 'TASKS.md'],
                ['slug' => 'worker-stacks', 'title' => 'Worker Stacks', 'file' => 'WORKER-STACKS.md'],
                ['slug' => 'agents', 'title' => 'Agents', 'file' => 'AGENTS.md'],
                ['slug' => 'live-demos', 'title' => 'Live Demos', 'file' => 'LIVE-DEMOS.md'],
                ['slug' => 'byoi', 'title' => 'Custom Worker Image (BYOI)', 'file' => 'BYOI.md'],
            ],
        ],
        [
            'title' => 'Integrations & Credentials',
            'pages' => [
                ['slug' => 'oauth', 'title' => 'OAuth', 'file' => 'OAUTH.md'],
                ['slug' => 'credentials', 'title' => 'Access Tokens (PAT)', 'file' => 'CREDENTIALS.md'],
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
