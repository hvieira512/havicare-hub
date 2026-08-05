<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Hub\Configuration\HubConfigurationValidator;
use PHPUnit\Framework\TestCase;

final class HubConfigurationValidatorTest extends TestCase
{
    public function testAllowsEmptyBootstrapCredentialAndDisabledOptionalIngress(): void
    {
        (new HubConfigurationValidator())->validate([
            'dashboard' => ['username' => '', 'password' => ''],
            'qinglanst' => ['enabled' => false],
        ]);

        self::addToAssertionCount(1);
    }

    public function testRejectsPartialBootstrapCredential(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new HubConfigurationValidator())->validate([
            'dashboard' => ['username' => 'admin', 'password' => ''],
            'qinglanst' => ['enabled' => false],
        ]);
    }

    public function testRejectsEnabledQinglanstIngressWithoutCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('QINGLANST_MQTT_HOST');
        (new HubConfigurationValidator())->validate([
            'dashboard' => ['username' => '', 'password' => ''],
            'qinglanst' => ['enabled' => true],
        ]);
    }
}
