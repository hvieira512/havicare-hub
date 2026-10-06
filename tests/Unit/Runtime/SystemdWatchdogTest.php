<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use Hub\Runtime\SystemdWatchdog;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;

/**
 * O sinal de vida sai de dentro do loop, e por isso prova que ele gira e não só que o
 * processo existe.
 */
final class SystemdWatchdogTest extends TestCase
{
    private string $socketPath = '';

    /** @var \Socket|null */
    private $receiver = null;

    protected function tearDown(): void
    {
        if ($this->receiver !== null) {
            socket_close($this->receiver);
            $this->receiver = null;
        }
        if ($this->socketPath !== '' && file_exists($this->socketPath)) {
            unlink($this->socketPath);
        }
        parent::tearDown();
    }

    /** Fora do systemd não há socket, e o watchdog tem de ser inerte. */
    public function testItIsInertWhenThereIsNoSystemdSocket(): void
    {
        self::assertNull(SystemdWatchdog::fromEnvironment(['WATCHDOG_USEC' => '30000000']));
        self::assertNull(SystemdWatchdog::fromEnvironment(['NOTIFY_SOCKET' => '/run/systemd/notify']));
        self::assertNull(SystemdWatchdog::fromEnvironment([]));
    }

    /** Sem `WATCHDOG_USEC` o systemd não está a vigiar, e mandar pings seria ruído. */
    public function testItIsInertWhenTheWatchdogIsNotArmed(): void
    {
        self::assertNull(SystemdWatchdog::fromEnvironment([
            'NOTIFY_SOCKET' => '/run/systemd/notify',
            'WATCHDOG_USEC' => '0',
        ]));
    }

    /**
     * O intervalo é metade do que o systemd pede, que é a convenção: dá margem para um ping
     * se perder sem o serviço ser declarado morto.
     */
    public function testThePingIntervalIsHalfOfWhatSystemdAsksFor(): void
    {
        $watchdog = SystemdWatchdog::fromEnvironment([
            'NOTIFY_SOCKET' => '/run/systemd/notify',
            'WATCHDOG_USEC' => '60000000',
        ]);

        self::assertNotNull($watchdog);
        self::assertSame(30.0, $watchdog->pingIntervalSeconds());
    }

    public function testItSendsWatchdogPingsWhileTheLoopTurns(): void
    {
        $watchdog = SystemdWatchdog::fromEnvironment([
            'NOTIFY_SOCKET' => $this->listeningSocket(),
            'WATCHDOG_USEC' => '200000',
        ]);
        self::assertNotNull($watchdog);
        self::assertSame(0.1, $watchdog->pingIntervalSeconds());

        $loop = Loop::get();
        $timer = $watchdog->attach($loop);
        self::assertNotNull($timer);

        $loop->addTimer(0.35, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);

        $pings = $this->drain();
        self::assertGreaterThanOrEqual(2, count($pings), 'o loop girou 0,35s com ping a cada 0,1s');
        foreach ($pings as $ping) {
            self::assertSame("WATCHDOG=1", $ping);
        }
    }

    /**
     * Um loop que não gira não manda ping, e é isso que faz o systemd reiniciar um processo
     * pendurado.
     */
    public function testAStalledLoopSendsNothing(): void
    {
        $watchdog = SystemdWatchdog::fromEnvironment([
            'NOTIFY_SOCKET' => $this->listeningSocket(),
            'WATCHDOG_USEC' => '200000',
        ]);
        self::assertNotNull($watchdog);

        $loop = Loop::get();
        $timer = $watchdog->attach($loop);

        // O loop nunca corre, que é o que um loop encalhado faz.
        usleep(300_000);

        self::assertSame([], $this->drain(), 'sem loop a girar não pode sair um único ping');
        self::assertNotNull($timer);
        $loop->cancelTimer($timer);
    }

    /** Um socket que desapareceu não pode derrubar o hub: o ping falha e a vida continua. */
    public function testAMissingSocketDoesNotThrow(): void
    {
        $watchdog = SystemdWatchdog::fromEnvironment([
            'NOTIFY_SOCKET' => sys_get_temp_dir() . '/hub-watchdog-does-not-exist-' . bin2hex(random_bytes(4)),
            'WATCHDOG_USEC' => '200000',
        ]);

        self::assertNotNull($watchdog);
        $watchdog->ping();
        self::assertTrue(true, 'chegar aqui é o teste');
    }

    private function listeningSocket(): string
    {
        $this->socketPath = sys_get_temp_dir() . '/hub-watchdog-' . bin2hex(random_bytes(6)) . '.sock';
        $socket = socket_create(AF_UNIX, SOCK_DGRAM, 0);
        self::assertNotFalse($socket);
        self::assertTrue(socket_bind($socket, $this->socketPath));
        socket_set_nonblock($socket);
        $this->receiver = $socket;

        return $this->socketPath;
    }

    /** @return list<string> */
    private function drain(): array
    {
        $messages = [];
        while (true) {
            $buffer = '';
            $from = '';
            $read = @socket_recvfrom($this->receiver, $buffer, 256, 0, $from);
            if ($read === false || $read === 0) {
                break;
            }
            $messages[] = $buffer;
        }

        return $messages;
    }
}
