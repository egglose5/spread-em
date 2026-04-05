<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AjaxScopeTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['spread_em_test_state']['caps'] = [];
        $GLOBALS['spread_em_test_state']['user_meta'] = [];
        $GLOBALS['spread_em_test_state']['current_user_id'] = 11;
    }

    public function testCurrentUserCanAccessProductWithinContributorScope(): void
    {
        $GLOBALS['spread_em_test_state']['user_meta'][11][SpreadEm_Ajax::LIVE_SCOPE_META_KEY] = [
            'scope_mode' => 'individual_contributor',
            'product_ids' => [21, 22],
        ];

        self::assertTrue($this->invokePrivate('current_user_can_access_product', [21]));
        self::assertFalse($this->invokePrivate('current_user_can_access_product', [99]));
    }

    public function testCurrentUserCanAccessAnyProductAsGlobalOperator(): void
    {
        $GLOBALS['spread_em_test_state']['caps'][SpreadEm_Permissions::CAP_LIVE_GLOBAL] = true;
        $GLOBALS['spread_em_test_state']['user_meta'][11][SpreadEm_Ajax::LIVE_SCOPE_META_KEY] = [
            'scope_mode' => 'global_operator',
            'product_ids' => [],
        ];

        self::assertTrue($this->invokePrivate('current_user_can_access_product', [999]));
    }

    public function testFilterDirectMessagesReturnsOnlyCurrentUserThread(): void
    {
        $messages = [
            ['id' => 'a', 'from_user_id' => 11, 'to_user_id' => 30, 'message' => 'one', 'from_name' => 'Me', 'ts' => time()],
            ['id' => 'b', 'from_user_id' => 30, 'to_user_id' => 11, 'message' => 'two', 'from_name' => 'Them', 'ts' => time()],
            ['id' => 'c', 'from_user_id' => 40, 'to_user_id' => 41, 'message' => 'skip', 'from_name' => 'Other', 'ts' => time()],
        ];

        $filtered = $this->invokePrivate('filter_live_direct_messages_for_current_user', [$messages]);

        self::assertCount(2, $filtered);
        self::assertSame('a', $filtered[0]['id']);
        self::assertSame('b', $filtered[1]['id']);
    }

    public function testPruneLivePresenceDropsStaleRowsAndPreservesScopeMode(): void
    {
        $presence = [
            11 => ['name' => 'Current', 'ts' => time(), 'scope_mode' => 'global_operator'],
            12 => ['name' => 'Stale', 'ts' => time() - 31],
        ];

        $pruned = $this->invokePrivate('prune_live_presence', [$presence]);

        self::assertCount(1, $pruned);
        self::assertArrayHasKey(11, $pruned);
        self::assertSame('global_operator', $pruned[11]['scope_mode']);
    }

    /**
     * @param string $methodName
     * @param array<int,mixed> $args
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $args)
    {
        $ref = new ReflectionClass(SpreadEm_Ajax::class);
        $method = $ref->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs(null, $args);
    }
}
