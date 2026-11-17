<?php

namespace App\Services\Access;

/**
 * Read access to the permission catalogue in config/avantpos/permissions.php.
 */
class PermissionCatalog
{
    public function guard(): string
    {
        return config('avantpos.permissions.guard', 'web');
    }

    /**
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public function groups(): array
    {
        return config('avantpos.permissions.groups', []);
    }

    /**
     * @return array<string, array{group: string, label: string}>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->groups() as $group => $definition) {
            foreach ($definition['permissions'] as $name => $label) {
                $all[$name] = ['group' => $group, 'label' => $label];
            }
        }

        return $all;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->all());
    }

    /**
     * @return array<string, array{name: string, description: string, locked: bool, permissions: string|list<string>}>
     */
    public function defaultRoles(): array
    {
        return config('avantpos.permissions.roles', []);
    }

    /**
     * @return list<string>
     */
    public function permissionsForRole(string $key): array
    {
        $permissions = $this->defaultRoles()[$key]['permissions'] ?? [];

        return $permissions === '*' ? $this->names() : array_values($permissions);
    }
}
