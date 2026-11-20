<?php

namespace App\Support;

use Illuminate\Support\Str;

class PrivilegeCatalog
{
    public const ADMIN_ROLE = 'administrator';

    /**
     * Flat list of every privilege.
     *
     * @return array<int, array{module: string, name: string, feature: string, action: string, description: string}>
     */
    public static function all(): array
    {
        $rows = [];

        foreach (config('privileges.modules', []) as $moduleKey => $module) {
            foreach ($module['features'] as $featureKey => $feature) {
                foreach (self::actionsOf($featureKey, $feature) as $action => $name) {
                    $rows[] = [
                        'module' => $moduleKey,
                        'name' => $name,
                        'feature' => $featureKey,
                        'action' => $action,
                        'description' => trim($module['label'].' › '.$feature['label'].': '.ucfirst($action)),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * Actions of a feature as [action => privilege name].
     *
     * @return array<string, string>
     */
    public static function actionsOf(string $featureKey, array $feature): array
    {
        $actions = [];

        foreach ($feature['actions'] as $key => $value) {
            if (is_int($key)) {
                $actions[$value] = "{$featureKey}.{$value}";   // ['view'] → view => directory.view
            } else {
                $actions[$key] = $value;                       // ['view' => 'view'] → explicit name
            }
        }

        return $actions;
    }

    /**
     * Does "module:name" match any of the patterns ("module:name", "*" wildcard)?
     *
     * @param  array<int, string>  $patterns
     */
    public static function matches(string $module, string $name, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (Str::is($pattern, "{$module}:{$name}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public static function defaultsFor(string $roleName): array
    {
        return config("privileges.defaults.{$roleName}", []);
    }

    public static function isAdminRole(?string $roleName): bool
    {
        return mb_strtolower(trim((string) $roleName)) === self::ADMIN_ROLE;
    }
}
