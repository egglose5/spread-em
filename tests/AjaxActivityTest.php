<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AjaxActivityTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['spread_em_test_state']['caps'] = [];
        $GLOBALS['spread_em_test_state']['user_meta'] = [];
        $GLOBALS['spread_em_test_state']['current_user_id'] = 7;
    }

    public function testAppendActivityKeepsFeedBounded(): void
    {
        $activity = [];

        for ($i = 0; $i < 140; $i++) {
            $activity = $this->invokePrivate('append_live_activity_event', [
                $activity,
                [
                    'type' => 'edit',
                    'user_id' => 7,
                    'user_name' => 'User 7',
                    'product_id' => 100 + $i,
                    'field' => 'name',
                    'ts' => time(),
                ],
            ]);
        }

        self::assertCount(SpreadEm_Ajax::LIVE_ACTIVITY_MAX, $activity);
    }

    public function testPruneActivityDropsStaleEvents(): void
    {
        $oldTs = time() - (31 * MINUTE_IN_SECONDS);
        $newTs = time() - 30;

        $activity = [
            ['id' => 'old', 'type' => 'edit', 'user_id' => 1, 'user_name' => 'A', 'product_id' => 1, 'field' => 'name', 'save_state_id' => '', 'rows' => 0, 'to_user_id' => 0, 'to_user_name' => '', 'ts' => $oldTs],
            ['id' => 'new', 'type' => 'edit', 'user_id' => 2, 'user_name' => 'B', 'product_id' => 2, 'field' => 'name', 'save_state_id' => '', 'rows' => 0, 'to_user_id' => 0, 'to_user_name' => '', 'ts' => $newTs],
        ];

        $pruned = $this->invokePrivate('prune_live_activity', [$activity]);

        self::assertCount(1, $pruned);
        self::assertSame(2, $pruned[0]['product_id']);
    }

    public function testFilterActivityRespectsContributorScope(): void
    {
        $GLOBALS['spread_em_test_state']['user_meta'][7][SpreadEm_Ajax::LIVE_SCOPE_META_KEY] = [
            'scope_mode' => 'individual_contributor',
            'product_ids' => [11, 12],
        ];

        $activity = [
            ['id' => 'a', 'type' => 'edit', 'user_id' => 2, 'user_name' => 'A', 'product_id' => 11, 'field' => 'name', 'save_state_id' => '', 'rows' => 0, 'to_user_id' => 0, 'to_user_name' => '', 'ts' => time()],
            ['id' => 'b', 'type' => 'edit', 'user_id' => 2, 'user_name' => 'B', 'product_id' => 99, 'field' => 'name', 'save_state_id' => '', 'rows' => 0, 'to_user_id' => 0, 'to_user_name' => '', 'ts' => time()],
            ['id' => 'c', 'type' => 'im', 'user_id' => 8, 'user_name' => 'C', 'product_id' => 0, 'field' => '', 'save_state_id' => '', 'rows' => 0, 'to_user_id' => 7, 'to_user_name' => 'Me', 'ts' => time()],
        ];

        $filtered = $this->invokePrivate('filter_live_activity_for_current_user', [$activity]);

        self::assertCount(2, $filtered);
        self::assertSame('a', $filtered[0]['id']);
        self::assertSame('c', $filtered[1]['id']);
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
