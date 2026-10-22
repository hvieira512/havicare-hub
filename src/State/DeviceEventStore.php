<?php

declare(strict_types=1);

namespace Hub\State;

use Predis\ClientInterface;

final class DeviceEventStore
{
    public function __construct(
        private ClientInterface $redis,
        private int $limit = 100,
    ) {
        $this->limit = max(1, $this->limit);
    }

    /** @param array<string, mixed> $payload */
    public function append(string $imei, string $list, array $payload): void
    {
        // O número de ordem deixa o stream mandar só o que é novo: o `recordedAt` tem resolução de um
        // segundo, e um radar publica vinte por segundo.
        $payload['seq'] = (int)$this->redis->incr(RedisKeys::list($imei, $list) . ':seq');
        $payload['recordedAt'] = gmdate('Y-m-d\\TH:i:s\\Z');
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }
        $key = RedisKeys::list($imei, $list);
        $type = (string)($payload['type'] ?? '');
        $this->redis->pipeline(function ($pipe) use ($key, $encoded, $type): void {
            $pipe->lpush($key, [$encoded]);
            $pipe->ltrim($key, 0, $this->limit - 1);
            // A lista guarda as últimas N; a última de cada tipo fica à parte, para não sair dela.
            if ($type !== '') {
                $pipe->hset($key . ':latest', $type, $encoded);
            }
        });
    }

    /**
     * As entradas mais recentes, da mais nova para a mais velha; com `$sinceSeq`, só as posteriores.
     * As gravadas sem `seq` só aparecem no instantâneo inicial.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(string $imei, string $list, int $sinceSeq = 0): array
    {
        $raw = $this->redis->lrange(RedisKeys::list($imei, $list), 0, $this->limit - 1);

        if ($sinceSeq <= 0) {
            return array_values(array_filter(array_map(
                static fn (string $line): ?array => json_decode($line, true) ?: null,
                $raw
            ), 'is_array'));
        }

        // A lista é monótona — a mais nova à cabeça —, por isso na primeira entrada com
        // seq <= cursor tudo o que vem depois já é anterior: pára aí, sem descodificar o resto.
        $entries = [];
        foreach ($raw as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if ((int)($entry['seq'] ?? 0) <= $sinceSeq) {
                break;
            }
            $entries[] = $entry;
        }

        return $entries;
    }

    /** @return list<array<string, mixed>> */
    public function latestOfEachType(string $imei, string $list): array
    {
        $entries = array_map(
            static fn (string $line): mixed => json_decode($line, true),
            array_values($this->redis->hgetall(RedisKeys::list($imei, $list) . ':latest')),
        );

        return array_values(array_filter($entries, 'is_array'));
    }

    /** @return array<string, mixed>|null a entrada mais recente, sem ler a lista inteira */
    public function latest(string $imei, string $list): ?array
    {
        $line = $this->redis->lrange(RedisKeys::list($imei, $list), 0, 0)[0] ?? null;
        $entry = is_string($line) ? json_decode($line, true) : null;

        return is_array($entry) ? $entry : null;
    }

    /** O número de ordem da entrada mais recente, que é o cursor a devolver ao cliente. */
    public function latestSequence(string $imei, string $list): int
    {
        return (int)($this->redis->get(RedisKeys::list($imei, $list) . ':seq') ?? 0);
    }
}
