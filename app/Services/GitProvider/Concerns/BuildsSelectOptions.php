<?php

declare(strict_types=1);

namespace App\Services\GitProvider\Concerns;

/**
 * Repository and branch pickers (value = label) from a provider's lists.
 * The provider names the field that holds a repository's path.
 */
trait BuildsSelectOptions
{
    /** The repository field with "owner/repo" (GitLab: path_with_namespace). */
    abstract protected function repositoryPathField(): string;

    /** @return array<string, string> */
    public function getRepoOptions(): array
    {
        return $this->optionsFrom($this->listRepositories(), $this->repositoryPathField());
    }

    /** @return array<string, string> */
    public function getBranchOptions(string $ownerRepo): array
    {
        [$owner, $repo] = explode('/', $ownerRepo, 2) + ['', ''];
        if ($owner === '' || $repo === '') {
            return [];
        }

        return $this->optionsFrom($this->listBranches($owner, $repo), 'name');
    }

    /**
     * @param  iterable<array<string, mixed>>  $items
     * @return array<string, string>
     */
    private function optionsFrom(iterable $items, string $field): array
    {
        $options = [];
        foreach ($items as $item) {
            $value = $item[$field] ?? null;
            if (is_string($value) && $value !== '') {
                $options[$value] = $value;
            }
        }

        return $options;
    }
}
