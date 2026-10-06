<?php

declare(strict_types=1);

namespace Hub\Configuration;

final class HubConfigurationValidator
{
    /** O sufixo que distingue o directório da instância de desenvolvimento. */
    private const DEVELOPMENT_SUFFIX = '-dev';

    /**
     * @param array<string, mixed> $config
     * @param string $projectRoot o directório de onde a instância arranca, vazio quando não se sabe
     */
    public function validate(array $config, string $projectRoot = ''): void
    {
        $this->assertRedisPrefixMatchesInstance($config, $projectRoot);

        $qinglanst = $config['qinglanst'] ?? [];
        if (($qinglanst['enabled'] ?? false) === true) {
            $required = [
                'QINGLANST_MQTT_HOST' => $qinglanst['host'] ?? '',
                'QINGLANST_MQTT_USERNAME' => $qinglanst['username'] ?? '',
                'QINGLANST_MQTT_PASSWORD' => $qinglanst['password'] ?? '',
                'QINGLANST_TOPIC_FILTER' => $qinglanst['topic_filter'] ?? '',
            ];
            foreach ($required as $environmentName => $value) {
                if (trim((string)$value) === '') {
                    throw new \InvalidArgumentException("{$environmentName} is required when QINGLANST_ENABLED=true");
                }
            }
        }
    }

    /**
     * O prefixo vazio é da produção e é o que o `.env.example` distribui: uma instância de
     * desenvolvimento com ele escreveria em `hub:*` sem sinal, por isso recusa-se o arranque.
     *
     * @param array<string, mixed> $config
     */
    private function assertRedisPrefixMatchesInstance(array $config, string $projectRoot): void
    {
        if ($projectRoot === '') {
            return;
        }

        $directory = $this->instanceDirectory($projectRoot);
        if (!str_ends_with($directory, self::DEVELOPMENT_SUFFIX)) {
            return;
        }

        $redis = $config['redis'] ?? [];
        if (trim((string)($redis['prefix'] ?? '')) !== '') {
            return;
        }

        throw new \InvalidArgumentException(
            "REDIS_PREFIX is required in {$directory}: an empty prefix is production's, "
            . 'and this instance would write over its keys'
        );
    }

    /**
     * O nome do directório da instância, resolvido lexicalmente: o ponto de entrada passa
     * `__DIR__ . '/..'`, e o `realpath` falharia num caminho que não existe.
     */
    private function instanceDirectory(string $projectRoot): string
    {
        $segments = [];
        foreach (explode('/', $projectRoot) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $segments === [] ? '' : (string)end($segments);
    }
}
