<?php

namespace Hub\Ingress\Mqtt\Ncs;

final class Topic
{
    public function __construct(
        public readonly string $original,
        public readonly string $scope,
        public readonly string $sourceId,
        public readonly string $kind,
        public readonly ?string $statusName = null,
    ) {
    }

    public static function parse(string $topic): ?self
    {
        $trimmed = trim($topic, '/');
        if ($trimmed === '') {
            return null;
        }

        $parts = explode('/', $trimmed);
        if (count($parts) < 5 || $parts[0] !== 'voerka' || $parts[2] !== 'devices') {
            return null;
        }

        $scope = trim($parts[1]);
        $sourceId = trim($parts[3]);
        $kind = trim($parts[4]);
        if ($scope === '' || $sourceId === '' || $kind === '') {
            return null;
        }

        if ($kind === 'status') {
            $statusName = trim($parts[5] ?? '');
            if ($statusName === '') {
                return null;
            }

            return new self($topic, $scope, $sourceId, $kind, $statusName);
        }

        if (!in_array($kind, ['events', 'attrs', 'answer'], true)) {
            return null;
        }

        return new self($topic, $scope, $sourceId, $kind);
    }
}
