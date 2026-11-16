<?php

namespace Tests\Feature\Permissions;

use Tests\TestCase;

/**
 * Regression: ByteForge Phase 1 permission keys must remain in the catalog.
 */
class ByteForgePermissionCatalogTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function catalogPermissionKeys(): array
    {
        $keys = [];

        foreach (config('permissions') as $entries) {
            if (! is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                if (is_array($entry) && isset($entry['permission'])) {
                    $keys[] = $entry['permission'];
                }
            }
        }

        return $keys;
    }

    public function test_byteforge_permission_keys_remain_in_catalog(): void
    {
        $keys = $this->catalogPermissionKeys();

        foreach ([
            'purchase_requests.view',
            'purchase_requests.create',
            'purchase_requests.edit',
            'purchase_requests.approve',
            'maintenances.view',
            'maintenances.create',
            'maintenances.edit',
            'maintenances.delete',
            'maintenances.complete',
        ] as $required) {
            $this->assertContains(
                $required,
                $keys,
                "Expected permission [{$required}] to remain in config/permissions.php"
            );
        }
    }
}
