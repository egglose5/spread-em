<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['spread_em_test_state']['roles'] = [];
        $GLOBALS['spread_em_test_state']['caps'] = [];
        $GLOBALS['spread_em_test_state']['options'] = [];
        $GLOBALS['spread_em_test_state']['user_meta'] = [];
        $GLOBALS['spread_em_test_state']['current_user_id'] = 1;
    }

    public function testDefaultCapabilityMapContainsExpectedRolesAndCaps(): void
    {
        $map = SpreadEm_Permissions::default_capability_map();

        self::assertArrayHasKey('administrator', $map);
        self::assertArrayHasKey('shop_manager', $map);
        self::assertContains(SpreadEm_Permissions::CAP_LIVE_GLOBAL, $map['administrator']);
        self::assertContains(SpreadEm_Permissions::CAP_LIVE_INDIVIDUAL, $map['shop_manager']);
        self::assertNotContains(SpreadEm_Permissions::CAP_LIVE_GLOBAL, $map['shop_manager']);
    }

    public function testGrantDefaultCapabilitiesAddsCapsToRoles(): void
    {
        $adminRole = new class {
            public array $caps = [];
            public function add_cap(string $cap): void { $this->caps[] = $cap; }
        };

        $shopRole = new class {
            public array $caps = [];
            public function add_cap(string $cap): void { $this->caps[] = $cap; }
        };

        $GLOBALS['spread_em_test_state']['roles']['administrator'] = $adminRole;
        $GLOBALS['spread_em_test_state']['roles']['shop_manager'] = $shopRole;

        SpreadEm_Permissions::grant_default_capabilities();

        self::assertContains(SpreadEm_Permissions::CAP_USE_EDITOR, $adminRole->caps);
        self::assertContains(SpreadEm_Permissions::CAP_LIVE_GLOBAL, $adminRole->caps);
        self::assertContains(SpreadEm_Permissions::CAP_USE_EDITOR, $shopRole->caps);
        self::assertNotContains(SpreadEm_Permissions::CAP_LIVE_GLOBAL, $shopRole->caps);
    }

    public function testCurrentUserCanUseEditorRequiresBothCaps(): void
    {
        $GLOBALS['spread_em_test_state']['caps'] = [
            SpreadEm_Permissions::CAP_USE_EDITOR => true,
            'edit_products' => true,
        ];

        self::assertTrue(SpreadEm_Permissions::current_user_can_use_editor());

        $GLOBALS['spread_em_test_state']['caps'] = [
            SpreadEm_Permissions::CAP_USE_EDITOR => true,
            'edit_products' => false,
        ];

        self::assertFalse(SpreadEm_Permissions::current_user_can_use_editor());
    }
}
