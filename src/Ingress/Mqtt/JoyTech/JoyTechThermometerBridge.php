<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\JoyTech;

use Hub\Device\CommercialModelResolver;
use Hub\Device\HubMqttBridge;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Gateway\GattBridge;
use Hub\Ingress\Mqtt\Gateway\GattDeviceContext;
use Hub\Ingress\Mqtt\Gateway\GattDownlinks;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;

/**
 * Ingestão dos termómetros de testa JoyTech entregues por um gateway GATT. O aparelho pede a hora
 * ao ligar e, depois de medir, manda as últimas 30 medições numa trama.
 */
final class JoyTechThermometerBridge extends GattBridge
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

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        GatewayDeviceLinkLookup $links,
        string $topicFilter,
        null|(DeviceReportStore&DeviceCommandLog) $deviceStore = null,
        ?callable $clock = null,
        ?CommercialModelResolver $commercialModelResolver = null,
        private readonly RecentMeasurementKeys $seen = new RecentMeasurementKeys(),
        ?Denylist $denylist = null,
    ) {
        parent::__construct(
            $whitelist,
            $mqttBridge,
            $links,
            self::PROTOCOL,
            self::DEVICE_TYPE,
            $deviceStore,
            $clock,
            $commercialModelResolver,
            $denylist,
            // Não há fila: as duas escritas são respostas ao que o aparelho manda.
            new GattDownlinks(null, $mqttBridge, $deviceStore, $topicFilter, (new JoyTechCommandEncoder())->writes(...)),
        );
    }

    /** O gateway entrega os tensiómetros pela mesma fonte: o nome do aparelho distingue-os. */
    public static function claims(array $message): bool
    {
        return ($message['source'] ?? null) === 'joytech-node'
            && str_starts_with(trim((string)($message['device']['model'] ?? '')), 'DET');
    }

    protected function handleKind(GattDeviceContext $context, string $kind, array $data, int $offsetMinutes, array $message): void
    {
        match ($kind) {
            'session' => ($data['connected'] ?? false) === true
                ? $this->presence($context)->markOnline($context->deviceKey, $context->device)
                : $this->presence($context)->markOffline($context->deviceKey, $context->device),
            'frame' => $this->frame($context, (string)($data['hex'] ?? ''), $offsetMinutes),
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
                $this->downlinks?->sendDirect($context->deviceKey, $context->gatewayKey, 'answerClock', [], 0, $offsetMinutes);
            }
            return;
        }
        if ($frame['function'] !== 0x03) {
            return;
        }

        $this->downlinks?->sendDirect($context->deviceKey, $context->gatewayKey, 'acknowledgeMeasurements', [], 0, $offsetMinutes);
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
            $this->telemetry($context, 'temperature', 'fff1:03', [
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
}
