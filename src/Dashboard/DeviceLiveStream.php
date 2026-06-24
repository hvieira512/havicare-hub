<?php

namespace Hub\Dashboard;

use Evenement\EventEmitter;
use React\Stream\ReadableStreamInterface;
use React\Stream\Util;
use React\Stream\WritableStreamInterface;

final class DeviceLiveStream extends EventEmitter implements ReadableStreamInterface
{
    /** @var array<int, string> */
    private array $buffer = [];
    private bool $readable = true;
    private bool $paused = false;
    private bool $piped = false;
    private bool $closed = false;
    /** @var callable(): void */
    private $unsubscribe;

    /**
     * @param array<string, mixed> $snapshot
     */
    public function __construct(
        private DashboardStore $store,
        private string $imei,
        array $snapshot
    ) {
        $this->unsubscribe = $this->store->subscribe($this->imei, function (array $event): void {
            $this->enqueue($event);
        });

        $this->enqueue(array_merge(['kind' => 'snapshot'], $snapshot));
    }

    public function isReadable()
    {
        return $this->readable;
    }

    public function pause()
    {
        $this->paused = true;
    }

    public function resume()
    {
        if ($this->closed) {
            return;
        }

        $this->paused = false;
        $this->flush();
    }

    public function pipe(WritableStreamInterface $dest, array $options = array())
    {
        $this->piped = true;
        $result = Util::pipe($this, $dest, $options);
        $this->flush();

        return $result;
    }

    public function close()
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->readable = false;
        $this->paused = false;
        $this->buffer = [];

        $unsubscribe = $this->unsubscribe;
        $this->unsubscribe = static function (): void {
        };
        $unsubscribe();

        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * @param array<string, mixed> $event
     */
    private function enqueue(array $event): void
    {
        if ($this->closed) {
            return;
        }

        $encoded = json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        $this->buffer[] = $encoded . "\n";
        $this->flush();
    }

    private function flush(): void
    {
        if ($this->closed || !$this->piped || $this->paused) {
            return;
        }

        while ($this->buffer !== [] && !$this->paused && !$this->closed) {
            $chunk = array_shift($this->buffer);
            if ($chunk === null) {
                break;
            }
            $this->emit('data', [$chunk]);
        }
    }
}
