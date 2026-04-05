<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AjaxConflictTest extends TestCase
{
    public function testRowHasRevisionConflictOnlyWhenTokensDiffer(): void
    {
        self::assertTrue($this->invokePrivate('row_has_revision_conflict', ['client-a', 'server-b']));
        self::assertFalse($this->invokePrivate('row_has_revision_conflict', ['same-token', 'same-token']));
        self::assertFalse($this->invokePrivate('row_has_revision_conflict', ['', 'server-b']));
    }

    public function testBuildSaveErrorMessageMentionsConflictedProducts(): void
    {
        $message = $this->invokePrivate('build_save_error_message', [
            [],
            [
                ['product_id' => 21, 'product_name' => 'Red Shirt'],
                ['product_id' => 22, 'product_name' => 'Blue Hat'],
            ],
        ]);

        self::assertStringContainsString('21', $message);
        self::assertStringContainsString('Red Shirt', $message);
        self::assertStringContainsString('22', $message);
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
