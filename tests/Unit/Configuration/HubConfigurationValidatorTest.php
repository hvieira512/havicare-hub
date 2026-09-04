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

    /**
     * O prefixo vazio é o da produção, e é o valor distribuído no `.env.example`. Uma
     * instância de desenvolvimento que arranque assim escreve por cima das chaves da
     * produção sem se queixar -- e auditar sítios de construção é um instantâneo, ao passo
     * que recusar o arranque não é.
     */
    public function testRejectsDevelopmentInstanceWithoutRedisPrefix(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_PREFIX');
        (new HubConfigurationValidator())->validate(
            ['redis' => ['prefix' => '']],
            '/opt/havicare-hub-dev'
        );
    }

    public function testAllowsDevelopmentInstanceWithItsOwnPrefix(): void
    {
        (new HubConfigurationValidator())->validate(
            ['redis' => ['prefix' => 'dev:']],
            '/opt/havicare-hub-dev'
        );

        self::addToAssertionCount(1);
    }

    /** Na produção o prefixo vazio é o correcto, e não pode ser confundido com um esquecimento. */
    public function testAllowsProductionInstanceWithoutPrefix(): void
    {
        (new HubConfigurationValidator())->validate(
            ['redis' => ['prefix' => '']],
            '/opt/havicare-hub'
        );

        self::addToAssertionCount(1);
    }

    /** Uma barra no fim não faz do directório outra instância. */
    public function testRecognisesTheDevelopmentDirectoryWithATrailingSlash(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_PREFIX');
        (new HubConfigurationValidator())->validate(
            ['redis' => ['prefix' => '']],
            '/opt/havicare-hub-dev/'
        );
    }

    /**
     * A forma que o arranque real usa. O `bin/server-hub.php` passa `__DIR__ . '/..'`, e o
     * `basename` disso é `..` -- sem resolver os segmentos, o guarda calava-se exactamente
     * na única chamada que interessa.
     */
    public function testResolvesTheParentSegmentThatTheEntryPointPasses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('REDIS_PREFIX');
        (new HubConfigurationValidator())->validate(
            ['redis' => ['prefix' => '']],
            '/opt/havicare-hub-dev/bin/..'
        );
    }

    /** E o mesmo caminho, quando a instância é a de produção, continua a passar. */
    public function testResolvesTheParentSegmentForProductionToo(): void
    {
        (new HubConfigurationValidator())->validate(
            ['redis' => ['prefix' => '']],
            '/opt/havicare-hub/bin/..'
        );

        self::addToAssertionCount(1);
    }

    /** Sem directório declarado não há nada a inferir, e os outros chamadores não partem. */
    public function testSkipsTheCheckWhenNoProjectRootIsGiven(): void
    {
        (new HubConfigurationValidator())->validate(['redis' => ['prefix' => '']]);

        self::addToAssertionCount(1);
    }
}
