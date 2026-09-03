<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Api\Auth\LoginThrottle;
use Tests\Support\DashboardHttpTestCase;
use Tests\Support\Doubles\InMemoryRedisClient;

/**
 * O tempo de resposta do login não pode dizer se uma conta existe.
 *
 * Medido contra a instância de desenvolvimento antes desta correcção: uma tentativa com
 * utilizador real custava 172 a 187 ms, e uma com utilizador inexistente 0,5 ms. A diferença
 * de ~350× era um oráculo -- descobria-se que contas existem só pelo relógio, sem acertar em
 * nenhuma password. A causa é o `&&` a fazer curto-circuito antes do `password_verify` quando
 * não há hash para comparar.
 *
 * Fechar isto torna **todas** as tentativas caras, e por isso só era seguro depois de os tetos
 * do `LoginThrottle` existirem: sem eles, seria trocar um oráculo por um caminho mais barato
 * para parar o event loop.
 */
final class DashboardLoginTimingTest extends DashboardHttpTestCase
{
    /**
     * O limite é inferior de propósito: afirma que houve trabalho de hash, e não que ele coube
     * numa janela. Uma máquina lenta torna a asserção mais fácil, não mais frágil -- que é o
     * sentido certo para um teste que envolve o relógio.
     */
    private const BCRYPT_FLOOR_SECONDS = 0.03;

    public function testAnUnknownUsernameCostsTheSameHashWorkAsARealOne(): void
    {
        $server = $this->serverWithoutThrottling();

        $unknown = $this->timeLogin($server, 'nao-existe-em-lado-nenhum', 'seja-o-que-for');
        $known = $this->timeLogin($server, 'admin', 'password-errada');

        self::assertSame(401, $unknown['status']);
        self::assertSame(401, $known['status']);

        self::assertGreaterThan(
            self::BCRYPT_FLOOR_SECONDS,
            $unknown['seconds'],
            sprintf(
                'um utilizador inexistente respondeu em %.1f ms, e portanto não verificou hash '
                . 'nenhum -- o tempo de resposta denuncia que a conta não existe',
                $unknown['seconds'] * 1000
            )
        );

        // E o caso real continua a custar o que sempre custou, para a comparação ter sentido.
        self::assertGreaterThan(self::BCRYPT_FLOOR_SECONDS, $known['seconds']);
    }

    /** Uma password vazia não pode ser o atalho que salta a verificação. */
    public function testAnEmptyPasswordAgainstAKnownUserIsStillRejectedAsCredentials(): void
    {
        $server = $this->serverWithoutThrottling();

        $response = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => 'admin', 'password' => ' '], JSON_THROW_ON_ERROR)
        ));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringContainsString('invalid_credentials', (string)$response->getBody());
    }

    /** E a conta certa continua a entrar: constante no tempo não pode significar sempre a recusar. */
    public function testTheRightCredentialsStillAuthenticate(): void
    {
        $server = $this->serverWithoutThrottling();

        $payload = $this->loginPayload($server, 'tenant', 'tenant-secret');

        self::assertSame('hitcare', $payload['token']['company'] ?? null);
        self::assertSame(1001, $payload['token']['license_id'] ?? null);
    }

    /**
     * Sem tetos, porque este teste faz várias tentativas seguidas contra a mesma conta e o
     * mesmo endereço, e o que aqui se mede é o tempo do hash e não o do travão.
     */
    private function serverWithoutThrottling(): callable
    {
        return $this->makeServerWithDatabase(
            loginThrottle: new LoginThrottle(
                new InMemoryRedisClient(),
                maxPerAddress: 1000,
                maxPerUsername: 1000,
                maxGlobal: 1000,
            )
        )[0];
    }

    /** @return array{status: int, seconds: float} */
    private function timeLogin(callable $server, string $username, string $password): array
    {
        $startedAt = microtime(true);
        $response = $server(new ServerRequest(
            'POST',
            '/api/auth/login',
            ['Content-Type' => 'application/json'],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR)
        ));

        return [
            'status' => $response->getStatusCode(),
            'seconds' => microtime(true) - $startedAt,
        ];
    }
}
