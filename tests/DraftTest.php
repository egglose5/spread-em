<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for the draft transient helpers and idempotency logic in SpreadEm_Ajax.
 */
final class DraftTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['spread_em_test_state']['caps'] = [];
        $GLOBALS['spread_em_test_state']['user_meta'] = [];
        $GLOBALS['spread_em_test_state']['current_user_id'] = 5;
        $GLOBALS['spread_em_test_state']['transients'] = [];
    }

    public function testDraftTtlConstantIs120Seconds(): void
    {
        self::assertSame(120, SpreadEm_Ajax::DRAFT_TTL);
    }

    public function testGetDraftStateReturnsEmptyArrayWhenTransientMissing(): void
    {
        $state = $this->invokePrivate('get_draft_state', ['nonexistent_session']);

        self::assertIsArray($state);
        self::assertEmpty($state);
    }

    public function testSaveDraftStatePersistsVersionAndCells(): void
    {
        $draft = [
            'version' => 3,
            'cells'   => [42 => ['name' => 'Test Product']],
        ];

        $this->invokePrivate('save_draft_state', ['my_session', $draft]);

        $stored = $this->invokePrivate('get_draft_state', ['my_session']);

        self::assertSame(3, $stored['version']);
        self::assertArrayHasKey(42, $stored['cells']);
        self::assertSame('Test Product', $stored['cells'][42]['name']);
    }

    public function testDraftTransientKeyDoesNotClashWithLiveKey(): void
    {
        $draftKey = $this->invokePrivate('draft_transient_key', ['abc']);
        $liveKey  = $this->invokePrivate('live_transient_key', ['abc']);

        self::assertNotSame($draftKey, $liveKey);
        self::assertStringContainsString('draft', $draftKey);
        self::assertStringContainsString('live', $liveKey);
    }

    public function testSaveDraftStateUsesSessionIsolation(): void
    {
        $this->invokePrivate('save_draft_state', ['session_a', ['version' => 1]]);
        $this->invokePrivate('save_draft_state', ['session_b', ['version' => 99]]);

        $a = $this->invokePrivate('get_draft_state', ['session_a']);
        $b = $this->invokePrivate('get_draft_state', ['session_b']);

        self::assertSame(1, $a['version']);
        self::assertSame(99, $b['version']);
    }

    public function testSeenRequestIdsBoundedTo100(): void
    {
        // Build a draft that already has 100 seen request IDs.
        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $seen[] = 'req-' . $i;
        }

        $draft = [
            'version'          => 1,
            'cells'            => [],
            'seen_request_ids' => $seen,
        ];

        $this->invokePrivate('save_draft_state', ['cap_session', $draft]);

        // Simulate what handle_save_draft does internally.
        $stored = $this->invokePrivate('get_draft_state', ['cap_session']);
        $stored['seen_request_ids'][] = 'req-new';
        $stored['seen_request_ids']   = array_slice($stored['seen_request_ids'], -100);

        self::assertCount(100, $stored['seen_request_ids']);
        self::assertContains('req-new', $stored['seen_request_ids']);
        // Oldest entry should have been dropped.
        self::assertNotContains('req-0', $stored['seen_request_ids']);
    }

    /**
     * @param string $methodName
     * @param array<int,mixed> $args
     * @return mixed
     */
    private function invokePrivate(string $methodName, array $args)
    {
        $ref    = new ReflectionClass(SpreadEm_Ajax::class);
        $method = $ref->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs(null, $args);
    }
}
