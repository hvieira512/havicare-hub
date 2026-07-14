<?php

namespace Hub\Domain;

final class DeviceProtocol
{
    public static function forSupplier(string $supplierName): string
    {
        return match (trim($supplierName)) {
            'Wonlex' => 'wonlex-json',
            'Vivistar' => 'vivistar-iw',
            '4P Touch' => 'four-p-touch',
            'Voerka' => 'voerka-ncs',
            'Qinglanst' => 'qinglanst-radar',
            default => '',
        };
    }
}
