<?php

declare(strict_types=1);

namespace Hub\Dashboard;

/**
 * Os números com que a dashboard se afina, num sítio só.
 *
 * Soltos no construtor ficavam seis parâmetros de tipos repetidos entre os colaboradores, e
 * dois inteiros a ladear o fan-out: chamar com um deles trocado compila e só se vê a correr.
 */
final class DashboardHttpOptions
{
    public function __construct(
        public readonly bool $apiAuthRequired = true,
        public readonly int $apiTokenTtlSeconds = 3600,
        public readonly int $apiRefreshTokenTtlSeconds = 2592000,
        public readonly int $maxOpenStreams = 200,
        public readonly int $maxOpenStreamsPerUser = 5,
        public readonly string $amchartsLicense = '',
    ) {
    }

    /** @param array<string, mixed> $dashboard a secção `dashboard` da configuração do hub */
    public static function fromConfig(array $dashboard): self
    {
        return new self(
            apiAuthRequired: (bool)$dashboard['api_auth_required'],
            apiTokenTtlSeconds: (int)$dashboard['api_token_ttl_seconds'],
            apiRefreshTokenTtlSeconds: (int)$dashboard['api_refresh_token_ttl_seconds'],
            maxOpenStreams: (int)$dashboard['max_open_streams'],
            maxOpenStreamsPerUser: (int)$dashboard['max_open_streams_per_user'],
            amchartsLicense: (string)($dashboard['amcharts_license'] ?? ''),
        );
    }
}
