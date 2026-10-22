<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Hub\Ingress\Mqtt\DeviceBridge;
use Hub\Ingress\Mqtt\Gateway\GatewayRelay;
use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Device\TelemetryEnvelope;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Gateway\GattDeviceContext;
use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Ingress\Mqtt\Veepoo\BraceletPresence;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;

/**
 * Ingestão dos termómetros de testa JoyTech entregues por um gateway GATT. O aparelho pede a hora
 * ao ligar e, depois de medir, manda as últimas 30 medições numa trama.
 */
final class JoyTechThermometerBridge extends DeviceBridge implements GatewayRelay
{
    private const PROTOCOL = 'joytech-et';

    private const DEVICE_TYPE = 'thermometer';

    /** Os pedidos de hora vêm em rajada, 4 por segundo. */
    private const CLOCK_REQUEST_BURST_SECONDS = 2.0;

    /** A mais recente da memória é a que se acabou de medir, se for desta janela. */
    private const LIVE_SECONDS = 300;

    /** 2025-01-01: o reset das pilhas data as medições de 2024-01-01. */
    private const HISTORY_EPOCH = 1735689600;

    private const FUTURE_TOLERANCE_SECONDS = 300;

    private const SITES = [1 => 'ear', 2 => 'forehead'];

    private readonly GattDownlinks $downlinks;

    private readonly BraceletPresence $presence;

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        private readonly GatewayDeviceLinkLookup $links,
        string $topicFilter,
        null|(DeviceReportStore&DeviceCommandLog) $deviceStore = null,
        ?callable $clock = null,
        ?CommercialModelResolver $commercialModelResolver = null,
        private readonly RecentMeasurementKeys $seen = new RecentMeasurementKeys(),
        ?Denylist $denylist = null,
    ) {
        parent::__construct($whitelist, $mqttBridge, $deviceStore, $denylist, $clock, $commercialModelResolver);
        // Não há fila: as duas escritas são respostas ao que o aparelho manda.
        $this->downlinks = new GattDownlinks(null, $mqttBridge, $deviceStore, $topicFilter, (new JoyTechCommandEncoder())->writes(...));
        $this->presence = new BraceletPresence($mqttBridge, $deviceStore, self::PROTOCOL);
    }

    /** A fonte é a de todos os JoyTech: o nome do aparelho distingue-os. */
    public static function claims(array $message): bool
    {
        return ($message['source'] ?? null) === 'joytech-node'
            && str_starts_with(trim((string)($message['device']['model'] ?? '')), 'DET');
    }

    public function handle(string $topic, array $message): void
    {
        $context = $this->resolveGattDevice($topic, $message, $this->links, self::PROTOCOL, self::DEVICE_TYPE);
        if ($context === null) {
            return;
        }

        $this->mqttBridge->publishRaw($context->deviceKey, $message, self::DEVICE_TYPE, $context->licenseId, $context->company);

        $data = is_array($message['payload'] ?? null) ? $message['payload'] : [];
        $offset = is_int($message['tzOffsetMinutes'] ?? null) ? $message['tzOffsetMinutes'] : 0;
        match ($message['kind'] ?? null) {
            'session' => ($data['connected'] ?? false) === true
                ? $this->presence->markOnline($context->deviceKey, $context->device)
                : $this->presence->markOffline($context->deviceKey, $context->device),
            'frame' => $this->frame($context, (string)($data['hex'] ?? ''), $offset),
            default => null,
        };
    }

    private function frame(GattDeviceContext $context, string $hex, int $offsetMinutes): void
    {
        $frame = JoyTechFrame::decode($hex);
        if ($frame === null) {
            return;
        }

        $now = $this->clockNow();
        if ($frame['function'] === 0x06) {
            // Sem resposta o aparelho não mede, e desliga-se.
            if ($this->allow('clock:' . $context->deviceKey, self::CLOCK_REQUEST_BURST_SECONDS)) {
                $this->downlinks->sendDirect($context->deviceKey, $context->gatewayKey, 'answerClock', [], 0, $offsetMinutes);
            }
            return;
        }
        if ($frame['function'] !== 0x03) {
            return;
        }

        $this->downlinks->sendDirect($context->deviceKey, $context->gatewayKey, 'acknowledgeMeasurements', [], 0, $offsetMinutes);
        $this->measurements($context, $frame['data'], $offsetMinutes, $now);
    }

    /** @param string $data 8 B por medição, a mais recente primeiro: sítio, valor, ano, mês, dia, hora, minuto */
    private function measurements(GattDeviceContext $context, string $data, int $offsetMinutes, float $now): void
    {
        $records = str_split($data, 8);
        $seen = array_flip($this->seen->all($context->deviceKey));
        // Da mais antiga para a mais recente, pela ordem em que foram medidas.
        foreach (array_reverse($records, true) as $index => $record) {
            if (strlen($record) < 8) {
                continue;
            }
            $fields = unpack('Csite/nvalue/Cyear/Cmonth/Cday/Chour/Cminute', $record);
            $site = self::SITES[$fields['site'] ?? 0] ?? null;
            $at = $fields === false ? null : JoyTechFrame::unixTime((int)$fields['year'], (int)$fields['month'], (int)$fields['day'], (int)$fields['hour'], (int)$fields['minute'], $offsetMinutes);
            if ($site === null || $at === null || $at < self::HISTORY_EPOCH || $at > $now + self::FUTURE_TOLERANCE_SECONDS) {
                continue;
            }

            // Há medições diferentes no mesmo minuto: a chave leva o valor e o sítio.
            $key = bin2hex($record);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $this->seen->add($context->deviceKey, $key);

            $live = $index === 0 && $now - $at < self::LIVE_SECONDS;
            $this->emit($context, [
                'bodyCelsius' => self::celsius((int)$fields['value']),
                'site' => $site,
            ] + ($live ? [] : ['fromHistory' => true]), gmdate('Y-m-d\TH:i:s\Z', $at));
        }
    }

    /** O bit 15 diz °F; o resto são centésimas. Uma casa decimal, como o ecrã. */
    private static function celsius(int $value): float
    {
        $degrees = ($value & 0x7fff) / 100;

        return round(($value & 0x8000) !== 0 ? ($degrees - 32) * 5 / 9 : $degrees, 1);
    }

    /** @param array<string, mixed> $data */
    private function emit(GattDeviceContext $context, array $data, string $occurredAt): void
    {
        $telemetry = TelemetryEnvelope::for(
            'temperature',
            $context->deviceKey,
            $context->device,
            self::PROTOCOL,
            'fff1:03',
            $data,
            $context->gatewayKey,
        );
        $telemetry['occurredAt'] = $occurredAt;
        $this->mqttBridge->publishTelemetry($context->deviceKey, $telemetry, self::DEVICE_TYPE, $context->licenseId, $context->company);
        $this->deviceStore?->append($context->deviceKey, 'telemetry', $telemetry + [
            'deviceType' => self::DEVICE_TYPE,
            'licenseId' => $context->licenseId,
        ]);
    }
}
