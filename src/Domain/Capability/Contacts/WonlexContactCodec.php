<?php

namespace Hub\Domain\Capability\Contacts;

final class WonlexContactCodec
{
    private const DIAL_CODES = ['351', '34', '33', '39', '49', '44'];

    /**
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    public static function familyContact(array $contact, bool $sos = false): array
    {
        $publicPhone = self::publicPhone($contact);
        if ($publicPhone === '') {
            throw new \InvalidArgumentException('phone is required');
        }

        [$areaCode, $localPhone] = self::splitPhone($publicPhone, (string)($contact['areaCode'] ?? ''));
        $id = trim((string)($contact['familyNumberId'] ?? ''));
        if ($id === '') {
            $id = substr(sha1($publicPhone), 0, 8);
        }

        return [
            'familyNumberId' => $id,
            'name' => trim((string)($contact['name'] ?? '')),
            'phone' => $localPhone,
            'sosSwitch' => $sos,
            'areaCode' => $areaCode,
            'publicPhone' => $publicPhone,
        ];
    }

    /**
     * @param array<string, mixed> $contact
     * @return array<string, mixed>
     */
    public static function sosContact(array $contact): array
    {
        $family = self::familyContact($contact, true);

        return [
            'sosNumberId' => $family['familyNumberId'],
            'name' => $family['name'],
            'phone' => $family['phone'],
            'publicPhone' => $family['publicPhone'],
        ];
    }

    /**
     * @param array<string, mixed> $contact
     * @return array{name: string, phone: string}|null
     */
    public static function publicContact(array $contact): ?array
    {
        $phone = self::publicPhone($contact);
        if ($phone === '') {
            return null;
        }

        return [
            'name' => trim((string)($contact['name'] ?? '')),
            'phone' => $phone,
        ];
    }

    /**
     * @param array<string, mixed> $contact
     */
    public static function publicPhone(array $contact): string
    {
        $explicit = self::normalizePhone((string)($contact['publicPhone'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $phone = self::normalizePhone((string)($contact['phone'] ?? ''));
        if ($phone === '') {
            return '';
        }
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        $areaCode = preg_replace('/\D+/', '', (string)($contact['areaCode'] ?? '')) ?: '';
        return $areaCode !== '' ? '+' . $areaCode . $phone : $phone;
    }

    public static function normalizePhone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return '';
        }

        $hasPlus = str_starts_with($phone, '+');
        $digits = preg_replace('/\D+/', '', $phone) ?: '';
        return $digits === '' ? '' : ($hasPlus ? '+' : '') . $digits;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function splitPhone(string $phone, string $existingAreaCode): array
    {
        $areaCode = preg_replace('/\D+/', '', $existingAreaCode) ?: '';
        $normalized = self::normalizePhone($phone);
        if (!str_starts_with($normalized, '+')) {
            return [$areaCode, $normalized];
        }

        $digits = substr($normalized, 1);
        if ($areaCode !== '' && str_starts_with($digits, $areaCode)) {
            return [$areaCode, substr($digits, strlen($areaCode))];
        }

        foreach (self::DIAL_CODES as $dialCode) {
            if (str_starts_with($digits, $dialCode)) {
                return [$dialCode, substr($digits, strlen($dialCode))];
            }
        }

        return ['', $normalized];
    }
}
