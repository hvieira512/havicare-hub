<?php

declare(strict_types=1);

namespace Tests\Unit\Dashboard;

use Hub\Api\Repository\CapabilityDiscoveryRepository;
use PHPUnit\Framework\TestCase;

final class CapabilityDiscoveryRepositoryTest extends TestCase
{
    private string $repoDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repoDir = sys_get_temp_dir() . '/hub-capability-discovery-repo-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->repoDir . '/*.json') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->repoDir);
        parent::tearDown();
    }

    public function testSaveFindAndListDiscoveryRuns(): void
    {
        $repo = new CapabilityDiscoveryRepository($this->repoDir);
        $run = [
            'id' => 'disc_test_1',
            'createdAt' => '2026-07-08T10:00:00Z',
            'status' => 'draft',
        ];

        self::assertSame($run, $repo->save($run));
        self::assertSame($run, $repo->find('disc_test_1'));
        self::assertSame($run, $repo->all()[0] ?? null);
    }
}
