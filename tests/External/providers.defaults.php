<?php

declare(strict_types=1);

/**
 * Default coordinates for the external provider contract suite.
 *
 * These are the test repositories on each provider that the suite writes to.
 * They are intentionally hard-coded here so CI only needs to inject the PATs
 * via secrets, not the rest of the configuration.
 *
 * Local overrides are still possible: if the matching `*_TEST_REPO_OWNER`,
 * `*_TEST_REPO`, etc. environment variables are set (e.g. via
 * `.env.testing.external`), they take precedence — useful when contributors
 * want to run the suite against their own sandbox repos.
 *
 * The `linear` entry is not a git repo and not used by the contract suite; it
 * carries the demo Linear team key for ProviderMatrixBuilder (overridable via
 * SEED_LINEAR_TEAM). GitLab's demo repo lives on gitlab.com (the entry below),
 * since we don't run a second self-hosted GitLab for tests.
 */
return [
    'github' => [
        'instanceUrl' => 'https://github.com',
        'testRepoOwner' => 'nodus-it',
        'testRepo' => 'argos-test',
        'defaultBranch' => 'main',
        'repoCloneUrl' => 'https://github.com/nodus-it/argos-test.git',
    ],
    'gitlab' => [
        'instanceUrl' => 'https://gitlab.com',
        'testRepoOwner' => 'bastian-schur',
        'testRepo' => 'argos-test',
        'defaultBranch' => 'main',
        'repoCloneUrl' => 'https://gitlab.com/bastian-schur/argos-test.git',
    ],
    'bitbucket' => [
        'instanceUrl' => 'https://bitbucket.org',
        'testRepoOwner' => 'nodus-it',
        'testRepo' => 'argos-provider-contract',
        'defaultBranch' => 'main',
        'repoCloneUrl' => 'https://bitbucket.org/nodus-it/argos-provider-contract.git',
    ],
    'linear' => [
        'team' => 'BAS',
    ],
];
