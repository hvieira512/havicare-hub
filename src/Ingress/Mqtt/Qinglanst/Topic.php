<?php

namespace Hub\Ingress\Mqtt\Qinglanst;

final class Topic
{
    public function __construct(
        public readonly string $original,
        public readonly string $licenseId,
        public readonly string $deviceUid,
    ) {
    }

    /**
     * Parse a Qinglanst radar topic: radar/{licenseId}/{deviceUid}
     */
    public static function parse(string $topic): ?self
    {
        $trimmed = trim($topic, '/');
        if ($trimmed === '') {
            return null;
        }

        $parts = explode('/', $trimmed);
        if (count($parts) < 3 || $parts[0] !== 'radar') {
            return null;
        }

        $licenseId = trim($parts[1]);
        $deviceUid = trim($parts[2]);
        if ($licenseId === '' || $deviceUid === '') {
            return null;
        }

        return new self($topic, $licenseId, $deviceUid);
    }

    public function deviceKey(): string
    {
        return $this->deviceUid;
    }
}
