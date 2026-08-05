<?php

declare(strict_types=1);

namespace Tests\Unit\Configuration;

use Hub\Configuration\HubConfigurationValidator;
use PHPUnit\Framework\TestCase;

final class HubConfigurationValidatorTest extends TestCase
{
    public function testAllowsDisabledOptionalIngress(): void
    {
        (new HubConfigurationValidator())->validate([
            'qinglanst' => ['enabled' => false],
        ]);

        self::addToAssertionCount(1);
    }

    public function testRejectsEnabledQinglanstIngressWithoutCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('QINGLANST_MQTT_HOST');
        (new HubConfigurationValidator())->validate([
            'qinglanst' => ['enabled' => true],
        ]);
    }
}
