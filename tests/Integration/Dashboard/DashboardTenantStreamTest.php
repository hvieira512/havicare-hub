<?php

namespace Tests\Integration\Dashboard;

use GuzzleHttp\Psr7\ServerRequest;
use Hub\Device\MessageFanout;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\Loop;
use Tests\Support\DashboardHttpTestCase;

/**
 * O stream de um inquilino inteiro: tudo o que o MQTT leva da sua empresa e licença, e nada
 * fora dela. Corre o event loop a sério, e por isso vive à parte do resto da suite.
 */
final class DashboardTenantStreamTest extends DashboardHttpTestCase
{
    /**
     * O teste que prende o isolamento. O arnês tem de propósito uma licença `otherCare/1001`
     * com o mesmo número da `hitcare/1001`: se a chave de encaminhamento fosse a licença
     * sozinha, este inquilino recebia dados de outro cliente.
     */
    public function testATenantOnlyReceivesItsOwnCompanyAndLicence(): void
    {
        [$server, , , $messages] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $this->openStream($server, $token);
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());
        self::assertSame('text/event-stream', $response->getHeaderLine('Content-Type'));

        $frames = $this->collect($response, static function () use ($messages): void {
            $messages->dispatch(
                MessageFanout::scope('hitcare', 1001, 'telemetry'),
                'havicare-hub/hitcare/1001/watch/861265061009822/telemetry',
                '{"type":"heart_rate","data":{"bpm":74}}',
            );
            // Mesmo número de licença, outra empresa.
            $messages->dispatch(
                MessageFanout::scope('otherCare', 1001, 'telemetry'),
                'havicare-hub/othercare/1001/watch/999999999999999/telemetry',
                '{"type":"heart_rate","data":{"bpm":41}}',
            );
            $messages->dispatch(
                MessageFanout::scope('otherCare', 2002, 'telemetry'),
                'havicare-hub/othercare/2002/watch/861265061009833/telemetry',
                '{"type":"heart_rate","data":{"bpm":42}}',
            );
            // Sem dono: um âmbito que nenhum token de inquilino consegue produzir.
            $messages->dispatch(
                MessageFanout::scope('null', 0, 'telemetry'),
                'havicare-hub/null/0/watch/861265061009844/telemetry',
                '{"type":"heart_rate","data":{"bpm":43}}',
            );
        });

        self::assertStringContainsString('"bpm":74', $frames, 'o inquilino tem de receber o que é dele');
        self::assertStringNotContainsString('"bpm":41', $frames, 'a mesma licença noutra empresa é outro cliente');
        self::assertStringNotContainsString('"bpm":42', $frames);
        self::assertStringNotContainsString('"bpm":43', $frames, 'um dispositivo sem dono não pertence a ninguém');

        $response->getBody()->close();
    }

    /**
     * O envelope do frame devolve o que no MQTT vive no tópico -- a empresa, a licença, o tipo
     * e o dispositivo --, e mantém o `payload` idêntico ao que vai para o fio, para quem já
     * tem código escrito contra o MQTT reutilizar a desserialização.
     */
    public function testTheFrameCarriesTheTopicMetadataAndAnUntouchedPayload(): void
    {
        [$server, , , $messages] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');
        $response = $this->openStream($server, $token);

        $payload = '{"type":"heart_rate","occurredAt":"2026-09-01T10:35:10Z","data":{"bpm":74}}';
        $frames = $this->collect($response, static function () use ($messages, $payload): void {
            $messages->dispatch(
                MessageFanout::scope('hitcare', 1001, 'telemetry'),
                'havicare-hub/hitcare/1001/watch/861265061009822/telemetry',
                $payload,
            );
        });

        self::assertStringContainsString("event: telemetry\n", $frames);

        $frame = $this->decode($frames);
        self::assertSame('havicare-hub/hitcare/1001/watch/861265061009822/telemetry', $frame['topic']);
        self::assertSame('hitcare', $frame['company']);
        self::assertSame(1001, $frame['licenseId']);
        self::assertSame('watch', $frame['deviceType']);
        self::assertSame('861265061009822', $frame['deviceId']);
        self::assertSame('telemetry', $frame['channel']);
        self::assertSame(json_decode($payload, true), $frame['payload']);

        $response->getBody()->close();
    }

    /** Os canais pedidos são chaves distintas: quem só quer eventos não paga a telemetria. */
    public function testTheClientChoosesItsChannels(): void
    {
        [$server, , , $messages] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $this->openStream($server, $token, '&channels=events');
        self::assertSame(200, $response->getStatusCode());

        $frames = $this->collect($response, static function () use ($messages): void {
            $messages->dispatch(
                MessageFanout::scope('hitcare', 1001, 'telemetry'),
                'havicare-hub/hitcare/1001/watch/861265061009822/telemetry',
                '{"type":"heart_rate","data":{"bpm":74}}',
            );
            $messages->dispatch(
                MessageFanout::scope('hitcare', 1001, 'events'),
                'havicare-hub/hitcare/1001/watch/861265061009822/events',
                '{"type":"sos"}',
            );
        });

        self::assertStringContainsString('"sos"', $frames);
        self::assertStringNotContainsString('"bpm":74', $frames);

        $response->getBody()->close();
    }

    /**
     * O `raw` é o canal de depuração e é 98% dos bytes publicados. Uma mangueira de inquilino
     * é o pior sítio para o servir, e por isso não está entre os canais servidos.
     */
    public function testRawIsNotAChannelThisStreamServes(): void
    {
        [$server] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $this->openStream($server, $token, '&channels=raw');

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('raw', (string)$response->getBody());
    }

    /** Fechar a ligação tem de largar todos os ouvintes, um por canal servido. */
    public function testClosingTheStreamReleasesEveryListener(): void
    {
        [$server, , , $messages] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $this->openStream($server, $token);
        self::assertSame(3, $messages->listenerCount(), 'um ouvinte por canal servido');

        $response->getBody()->close();
        self::assertSame(0, $messages->listenerCount());
    }

    /**
     * Nada limitava o número de ligações abertas, e o teto real eram os descritores de
     * ficheiro do processo -- descobertos em produção.
     */
    public function testTooManyStreamsForOneUserAreRefusedWithAReadableReason(): void
    {
        [$server] = $this->makeServerWithDatabase(maxOpenStreamsPerUser: 2);
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $first = $this->openStream($server, $token);
        $second = $this->openStream($server, $token);
        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());

        $third = $this->openStream($server, $token);
        self::assertSame(503, $third->getStatusCode());
        self::assertStringContainsString('too_many_streams', (string)$third->getBody());

        // E ao fechar uma, abre-se lugar para a seguinte.
        $first->getBody()->close();
        $fourth = $this->openStream($server, $token);
        self::assertSame(200, $fourth->getStatusCode(), 'fechar uma ligação liberta o seu lugar');

        $second->getBody()->close();
        $fourth->getBody()->close();
    }

    /** O teto global protege o processo mesmo quando os inquilinos são muitos. */
    public function testTheGlobalCapIsEnforcedAcrossUsers(): void
    {
        [$server] = $this->makeServerWithDatabase(maxOpenStreams: 1, maxOpenStreamsPerUser: 10);
        $tenant = $this->loginToken($server, 'tenant', 'tenant-secret');
        $admin = $this->loginToken($server, 'admin', 'secret');

        $first = $this->openStream($server, $tenant);
        self::assertSame(200, $first->getStatusCode());

        $second = $this->openStream($server, $admin);
        self::assertSame(503, $second->getStatusCode());

        $first->getBody()->close();
    }

    /**
     * Um administrador abre o stream de um inquilino nomeando-o.
     *
     * O âmbito de um `hub_admin` seria o sistema inteiro, e disso não há implementação: o
     * fanout é indexado por âmbito e não tem wildcard. Mas recusar o admin por completo era
     * uma restrição sem contrapartida -- ele já pode ler os dispositivos desse inquilino por
     * todas as outras rotas. Nomeando a empresa e a licença, o âmbito fica tão limitado como
     * o de um cliente, e é uma subscrição só.
     */
    public function testAnAdminOpensTheStreamOfANamedTenant(): void
    {
        [$server, , , $messages] = $this->makeServerWithDatabase();
        $admin = $this->loginToken($server, 'admin', 'secret');

        $response = $this->openStream($server, $admin, '&company=hitcare&licenseId=1001');
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $frames = $this->collect($response, static function () use ($messages): void {
            $messages->dispatch(
                MessageFanout::scope('hitcare', 1001, 'telemetry'),
                'havicare-hub/hitcare/1001/diaper_sensor/eec5000202f9/telemetry',
                '{"type":"diaper_moisture_level","data":{"levelPercent":12}}',
            );
            // A empresa ao lado não entra, mesmo com o admin a abrir.
            $messages->dispatch(
                MessageFanout::scope('otherCare', 1001, 'telemetry'),
                'havicare-hub/othercare/1001/watch/999999999999999/telemetry',
                '{"type":"heart_rate","data":{"bpm":41}}',
            );
        });

        self::assertStringContainsString('diaper_moisture_level', $frames);
        self::assertStringNotContainsString('999999999999999', $frames);
        $response->getBody()->close();
    }

    /** Sem nomear o inquilino, o admin continua a ser recusado: não há stream do sistema todo. */
    public function testAnAdminWithoutATenantIsStillRefused(): void
    {
        [$server] = $this->makeServerWithDatabase();
        $admin = $this->loginToken($server, 'admin', 'secret');

        $response = $this->openStream($server, $admin);

        self::assertSame(400, $response->getStatusCode());
        self::assertStringContainsString('license client', (string)$response->getBody());
    }

    /** O cliente de licença não pode nomear outro inquilino: o parâmetro não alarga nada. */
    public function testALicenseClientCannotNameAnotherTenant(): void
    {
        [$server, , , $messages] = $this->makeServerWithDatabase();
        $token = $this->loginToken($server, 'tenant', 'tenant-secret');

        $response = $this->openStream($server, $token, '&company=otherCare&licenseId=2002');
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $frames = $this->collect($response, static function () use ($messages): void {
            $messages->dispatch(
                MessageFanout::scope('otherCare', 2002, 'telemetry'),
                'havicare-hub/othercare/2002/watch/861265061009833/telemetry',
                '{"type":"heart_rate","data":{"bpm":42}}',
            );
            $messages->dispatch(
                MessageFanout::scope('hitcare', 1001, 'telemetry'),
                'havicare-hub/hitcare/1001/watch/861265061009822/telemetry',
                '{"type":"heart_rate","data":{"bpm":74}}',
            );
        });

        self::assertStringContainsString('"bpm":74', $frames, 'o cliente continua a ver o seu');
        self::assertStringNotContainsString('"bpm":42', $frames, 'e o parâmetro não lhe deu o alheio');
        $response->getBody()->close();
    }

    /** Sem credencial não abre: o stream de inquilino não é uma porta lateral. */
    public function testAStreamWithoutACredentialIsRejected(): void
    {
        [$server] = $this->makeServerWithDatabase();

        self::assertSame(401, $server(new ServerRequest('GET', '/api/stream'))->getStatusCode());
    }

    private function openStream(callable $server, string $token, string $extra = ''): ResponseInterface
    {
        return $server(new ServerRequest(
            'GET',
            '/api/stream?ticket=' . rawurlencode($this->streamTicket($server, $token)) . $extra
        ));
    }

    private function streamTicket(callable $server, string $token): string
    {
        $response = $server(
            (new ServerRequest('POST', '/api/auth/stream-ticket'))
                ->withHeader('Authorization', 'Bearer ' . $token)
        );
        self::assertSame(200, $response->getStatusCode(), (string)$response->getBody());

        $ticket = (string)(json_decode((string)$response->getBody(), true)['data']['ticket'] ?? '');
        self::assertNotSame('', $ticket);

        return $ticket;
    }

    /**
     * Corre o `$publish` e deixa o loop andar. A dispersão é diferida para fora do tique da
     * ingestão, e por isso um frame só aparece depois de o loop girar -- é isso que este
     * método espera.
     */
    private function collect(ResponseInterface $response, callable $publish): string
    {
        $body = $response->getBody();
        $frames = '';
        $loop = Loop::get();

        $body->on('data', static function (string $chunk) use (&$frames): void {
            $frames .= $chunk;
        });

        $loop->addTimer(0.02, static function () use ($publish): void {
            $publish();
        });
        $loop->addTimer(0.3, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();

        return $frames;
    }

    /** @return array<string, mixed> */
    private function decode(string $frames): array
    {
        foreach (explode("\n", trim($frames)) as $line) {
            if (str_starts_with($line, 'data: ')) {
                return json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        self::fail('Nenhum frame trouxe uma linha de dados.');
    }
}
