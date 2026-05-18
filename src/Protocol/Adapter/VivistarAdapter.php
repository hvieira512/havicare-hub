<?php

namespace App\Protocol\Adapter;

class VivistarAdapter implements DeviceAdapterInterface
{
    public function protocol(): string
    {
        return 'vivistar-iw';
    }

    public function canDecode(string $raw): bool
    {
        $message = trim($raw);
        return str_starts_with($message, 'IW') && str_ends_with($message, '#');
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        $message = trim($raw);
        if (!$this->canDecode($message)) {
            return null;
        }

        $body = substr($message, 2, -1);

        if (preg_match('/^AP00(\d{15})$/', $body, $m) === 1) {
            return [
                'type' => 'login',
                'ident' => '',
                'ref' => 'w:update',
                'imei' => $m[1],
                'data' => [],
                'timestamp' => $this->now(),
            ];
        }

        if (preg_match('/^(AP[A-Z0-9]{2})(.*)$/', $body, $m) !== 1) {
            return null;
        }

        $type = $m[1];
        $tail = $m[2] ?? '';
        if (str_starts_with($tail, ',')) {
            $tail = substr($tail, 1);
        }
        $rawData = $tail;
        $fields = $rawData === '' ? [] : explode(',', $rawData);
        $session = $context['session'] ?? [];
        $imei = $session['imei'] ?? '';

        if ($imei === '' && isset($fields[0]) && preg_match('/^\d{15}$/', $fields[0]) === 1) {
            $imei = $fields[0];
        }

        $data = [
            'raw' => $rawData,
            'fields' => $fields,
        ];

        $this->enrichMeasurements($type, $fields, $data);

        return [
            'type' => $type,
            'ident' => $this->resolveIdent($fields),
            'ref' => 'w:update',
            'imei' => $imei,
            'data' => $data,
            'timestamp' => $this->now(),
        ];
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        $type = $payload['type'] ?? '';
        $timezoneHours = isset($context['timezoneHours']) && is_numeric($context['timezoneHours'])
            ? (int)$context['timezoneHours']
            : $this->timezoneHours();

        if ($type === 'login_ok' || $type === 'login_error') {
            return $this->formatLine('BP00', [gmdate('YmdHis'), $timezoneHours]);
        }

        if ($type === 'error') {
            return $this->formatLine('BP03');
        }

        if (preg_match('/^AP([A-Z0-9]{2})$/', $type, $match) === 1) {
            $replyData = $payload['data'] ?? [];
            $fields = is_array($replyData) ? ($replyData['fields'] ?? []) : [];
            $command = 'BP' . $match[1];
            if (
                in_array($command, ['BP02', 'BP10'], true)
                && is_array($replyData)
                && isset($replyData['unicodeHex'])
                && is_string($replyData['unicodeHex'])
            ) {
                return $this->formatLineWithTail($command, $replyData['unicodeHex']);
            }

            return $this->formatLine($command, $fields);
        }

        if (preg_match('/^BP([A-Z0-9]{2})$/', $type) === 1) {
            $imei = $payload['imei'] ?? '';
            $ident = $payload['ident'] ?? '';
            $data = $payload['data'] ?? [];
            $fields = [];

            if ($imei !== '') {
                $fields[] = $imei;
            }
            if ($ident !== '') {
                $fields[] = $ident;
            }

            if (isset($data['fields']) && is_array($data['fields'])) {
                $fields = array_merge($fields, $data['fields']);
            } elseif (!empty($data)) {
                foreach ($data as $value) {
                    if (is_scalar($value) || $value === null) {
                        $fields[] = (string)$value;
                    }
                }
            }

            return $this->formatLine($type, $fields);
        }

        return $this->formatLine('BP03');
    }

    private function formatLine(string $command, array $fields = []): string
    {
        $serialized = empty($fields) ? '' : ',' . implode(',', array_map(
            static fn (mixed $value): string => (string)$value,
            $fields
        ));

        return "IW{$command}{$serialized}#";
    }

    private function formatLineWithTail(string $command, string $tail): string
    {
        return "IW{$command}{$tail}#";
    }

    private function resolveIdent(array $fields): string
    {
        $candidate = $fields[0] ?? '';
        if (!is_string($candidate)) {
            return '';
        }

        if (preg_match('/^\d{6,14}$/', $candidate) === 1) {
            return $candidate;
        }

        return '';
    }

    private function enrichMeasurements(string $type, array $fields, array &$data): void
    {
        if ($type === 'AP49') {
            $data['heartRate'] = $this->num($fields[0] ?? null);
            return;
        }

        if ($type === 'APHT') {
            $data['heartRate'] = $this->num($fields[0] ?? null);
            $data['systolic'] = $this->num($fields[1] ?? null);
            $data['diastolic'] = $this->num($fields[2] ?? null);
            return;
        }

        if ($type === 'APHP') {
            $data['heartRate'] = $this->num($fields[0] ?? null);
            $data['systolic'] = $this->num($fields[1] ?? null);
            $data['diastolic'] = $this->num($fields[2] ?? null);
            $data['spo2'] = $this->num($fields[3] ?? null);
            $data['bloodSugar'] = $this->num($fields[4] ?? null);
            return;
        }

        if ($type === 'AP50') {
            $data['temperature'] = $this->num($fields[0] ?? null);
            $data['battery'] = $this->num($fields[1] ?? null);
            return;
        }

        if ($type === 'AP03') {
            $status = (string)($fields[0] ?? '');
            if (preg_match('/^\d{11,14}$/', $status) === 1) {
                $data['gsmSignal'] = $this->num(substr($status, 0, 3));
                $data['satelliteCount'] = $this->num(substr($status, 3, 3));
                $data['battery'] = $this->num(substr($status, 6, 3));
                $data['remainingSpace'] = $this->num(substr($status, 9, 1));
                $data['fortificationState'] = $this->num(substr($status, 10, 2));
                if (strlen($status) >= 14) {
                    $data['workMode'] = $this->num(substr($status, 12, 2));
                }
            }
            $data['steps'] = $this->num($fields[1] ?? null);
            $data['rollFrequency'] = $this->num($fields[2] ?? null);
        }
    }

    private function num(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric((string)$value)) {
            return null;
        }

        if (str_contains((string)$value, '.')) {
            return (float)$value;
        }

        return (int)$value;
    }

    private function now(): int
    {
        return (int)round(microtime(true) * 1000);
    }

    private function timezoneHours(): int
    {
        return (int)floor((int)date('Z') / 3600);
    }
}
