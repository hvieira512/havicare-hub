<?php

declare(strict_types=1);

namespace Hub\Configuration;

final class HubConfigurationValidator
{
    public function validate(array $config): void
    {
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
}
