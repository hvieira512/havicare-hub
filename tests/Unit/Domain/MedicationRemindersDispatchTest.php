<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use Hub\Domain\Capability\Medication\MedicationRemindersCapability;
use Hub\Domain\Capability\Medication\MedicationRemindersHandler;
use PHPUnit\Framework\TestCase;

/**
 * Cada protocolo é servido pelo seu tratador, e o contrato não escolhe por ele.
 *
 * Os lembretes de medicação são a capacidade com mais protocolos e a que tinha o despacho mais
 * espalhado: cinco `match ($protocol)` paralelos sobre o mesmo conjunto, cada um a repetir a
 * mesma tabela. Acrescentar um fornecedor obrigava a lembrar-se dos cinco, e esquecer um não
 * dava erro em lado nenhum -- dava o tratador errado ou uma recusa a meio do caminho.
 *
 * Estes testes prendem o encaminhamento com tratadores falsos que dizem quem são, para não
 * dependerem do que cada fornecedor real faz com os dados.
 */
final class MedicationRemindersDispatchTest extends TestCase
{
    private function capability(): MedicationRemindersCapability
    {
        return new MedicationRemindersCapability(
            new NamedRemindersHandler('wonlex'),
            new NamedRemindersHandler('4p'),
            new NamedRemindersHandler('dispensador'),
        );
    }

    /** @return list<array{0: string, 1: string}> */
    public static function protocolos(): array
    {
        return [
            ['wonlex-json', 'wonlex'],
            ['four-p-touch', '4p'],
            ['zayata-m228', 'dispensador'],
        ];
    }

    /**
     * @dataProvider protocolos
     */
    public function testEachProtocolIsServedByItsOwnHandler(string $protocol, string $esperado): void
    {
        $capability = $this->capability();

        self::assertSame(['quem' => $esperado], $capability->toNative($protocol, []), 'toNative');
        self::assertSame($esperado, $capability->fromNative($protocol, '', []), 'fromNative');
        self::assertSame($esperado, $capability->defaultValue($protocol), 'defaultValue');
        self::assertSame(['quem' => $esperado], $capability->meta($protocol), 'meta');
        self::assertSame(
            ['quem' => $esperado],
            $capability->responseEntry($protocol, '', null, []),
            'responseEntry',
        );
    }

    /**
     * A lista de protocolos suportados tem de sair do mesmo sítio que o despacho.
     *
     * Declarada à parte, podia dizer que suporta um protocolo que o despacho recusa -- e o
     * contrário, servir um que não anuncia. São duas afirmações sobre a mesma coisa e só uma
     * delas pode ser a fonte.
     */
    public function testTheAdvertisedProtocolsAreExactlyTheOnesItServes(): void
    {
        $capability = $this->capability();

        foreach ($capability->supportedProtocols() as $protocol) {
            $capability->toNative($protocol, []);
        }

        self::assertSame(['wonlex-json', 'four-p-touch', 'zayata-m228'], $capability->supportedProtocols());
    }

    public function testAnUnknownProtocolIsRefusedOnTheWayIn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported protocol vivistar-iw for medication_reminders');

        $this->capability()->toNative('vivistar-iw', []);
    }

    /**
     * E na leitura devolve o que lá está, em vez de o descodificar com um tratador ao calhas.
     *
     * O `default` do `fromNative` caía no tratador do 4P Touch. Hoje está certo por acidente,
     * porque o 4P Touch é o único caso que sobra depois dos dois nomeados; um quarto protocolo
     * levava descodificação de 4P Touch em silêncio. A leitura não pode rebentar -- é o
     * caminho que desenha o ecrã --, mas também não pode inventar.
     */
    public function testAnUnknownProtocolIsNotDecodedBySomebodyElsesHandler(): void
    {
        $desired = ['plans' => [['hour' => 8, 'minute' => 30]]];

        self::assertSame($desired, $this->capability()->fromNative('vivistar-iw', '', $desired));
    }
}

/** Um tratador que só diz o seu nome, para o teste ver por onde a chamada passou. */
final class NamedRemindersHandler implements MedicationRemindersHandler
{
    public function __construct(private readonly string $nome)
    {
    }

    public function nativeKey(): string
    {
        return $this->nome;
    }

    public function toNative(mixed $value): array
    {
        return ['quem' => $this->nome];
    }

    public function fromNative(array $desired): mixed
    {
        return $this->nome;
    }

    public function defaultValue(): mixed
    {
        return $this->nome;
    }

    public function meta(array $accumulatedMeta = []): array
    {
        return ['quem' => $this->nome];
    }

    public function merge(mixed $existing, mixed $incoming): mixed
    {
        return $this->nome;
    }

    public function responseEntry(string $protocol, string $nativeKey, mixed $value, array $meta): array
    {
        return ['quem' => $this->nome];
    }
}
