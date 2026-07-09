<?php

namespace Hub\Api\Repository;

final class CapabilityDiscoveryRepository
{
    public function __construct(private string $directory)
    {
        $this->directory = rtrim($this->directory, DIRECTORY_SEPARATOR);
        if ($this->directory === '') {
            $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hub-capability-discovery';
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0775, true);
        }
    }

    public function save(array $run): array
    {
        $id = (string)($run['id'] ?? '');
        if ($id === '') {
            throw new \InvalidArgumentException('Discovery run id is required');
        }

        $path = $this->pathFor($id);
        $encoded = json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode discovery run');
        }

        file_put_contents($path, $encoded);

        return $run;
    }

    public function find(string $id): ?array
    {
        $path = $this->pathFor($id);
        if (!is_file($path)) {
            return null;
        }

        $decoded = json_decode((string)file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        $items = [];
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $decoded = json_decode((string)file_get_contents($file), true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }

        usort($items, static function (array $a, array $b): int {
            return strcmp((string)($b['createdAt'] ?? ''), (string)($a['createdAt'] ?? ''));
        });

        return $items;
    }

    private function pathFor(string $id): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . preg_replace('/[^a-zA-Z0-9_-]/', '_', $id) . '.json';
    }
}
