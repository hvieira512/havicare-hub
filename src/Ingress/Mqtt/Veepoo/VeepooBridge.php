<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Device\CommercialModelResolver;
use Hub\Device\DeviceDescriptor;
use Hub\Device\HubMqttBridge;
use Hub\Device\LowBatteryTransitions;
use Hub\State\ActiveAlarms;
use Hub\Device\RedisPendingDownlinkQueue;
use Hub\Device\TelemetryEnvelope;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Gateway\GattBridge;
use Hub\Ingress\Mqtt\Gateway\GattDeviceContext;
use Hub\Ingress\Mqtt\Gateway\ObservationStateStore;
use Hub\Log\Logger;
use Hub\Registry\Denylist;
use Hub\Registry\Whitelist;
use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;
use Hub\Support\Values;

/**
 * Ingestão das pulseiras Veepoo entregues por um gateway BLE, que traz da sessão GATT o que a
 * pulseira lhe deu já estruturado pelo SDK do fabricante.
 */
final class VeepooBridge extends GattBridge
{
    /** Estados que o aparelho reporta durante uma medição, e o que significam para o pedido. */
    private const DETECTION_FAILURES = [
        'atLowVoltage' => 'low_battery',
        'wrongfulValue' => 'sensor_fault',
    ];

    private const PROTOCOL = 'veepoo-ble';

    private const DEVICE_TYPE = 'bracelet';

    /** O tipo com que o firmware fala do ECG, tanto no estado ao vivo como na onda. */
    private const ECG_SDK_TYPE = 42;

    /** O que o gateway diz quando a medição correu e a pulseira não respondeu nada. */
    private const SILENT_OUTCOME = 'no_response';

    /**
     * Excede a janela que o gateway reproduz (`RETENTION_DAYS`, três dias por omissão, mais o
     * dia corrente).
     */
    private const REPLAY_TTL_SECONDS = 5 * 86400;

    /**
     * Por que gateway está cada pulseira com sessão aberta, para lhe entregar o que chegar
     * à fila entretanto.
     *
     * @var array<string, string>
     */
    private array $sessionGateway = [];

    /**
     * Espécies de mensagem já relatadas como não normalizadas, por aparelho.
     *
     * @var array<string, true>
     */
    private array $unhandledKinds = [];

    /**
     * A última versão de firmware no histórico de cada aparelho; em memória, que um reinício
     * custa só uma entrada repetida.
     *
     * @var array<string, string>
     */
    private array $lastFirmwareShown = [];

    private readonly DailyBlockNormalizer $normalizer;

    private readonly DownlinkDispatcher $downlinkDispatcher;

    private readonly MeasurementFailureReporter $failures;

    private readonly SettlingReadings $readings;

    private readonly LowBatteryTransitions $lowBattery;

    public function __construct(
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        GatewayDeviceLinkLookup $links,
        ?RedisPendingDownlinkQueue $downlinks,
        private readonly ObservationStateStore $state,
        string $topicFilter,
        null|(DeviceReportStore&DeviceCommandLog) $deviceStore = null,
        ?callable $clock = null,
        ?CommercialModelResolver $commercialModelResolver = null,
        ?Denylist $denylist = null,
        ActiveAlarms $alarms = new ActiveAlarms(),
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
        );
        $this->normalizer = new DailyBlockNormalizer();
        $this->lowBattery = new LowBatteryTransitions($alarms);
        $this->downlinkDispatcher = new DownlinkDispatcher(
            $downlinks,
            $mqttBridge,
            $deviceStore,
            $topicFilter,
        );
        $this->failures = new MeasurementFailureReporter($mqttBridge, $deviceStore);
        $this->readings = new SettlingReadings(fn(): float => $this->clockNow());
    }

    /**
     * Entrega o que esteja em fila às pulseiras com sessão aberta: o gateway fica subscrito
     * aos comandos entre sessões.
     */
    public function dispatchQueued(): void
    {
        foreach ($this->sessionGateway as $deviceKey => $gatewayKey) {
            $this->downlinkDispatcher->dispatchPending($deviceKey, $gatewayKey);
        }

        foreach ($this->readings->release() as $due) {
            $this->emit($due['context'], $due['telemetry']);
        }
    }

    public static function claims(array $message): bool
    {
        return ($message['source'] ?? null) === 'veepoo-node';
    }

    protected function handleKind(GattDeviceContext $context, string $kind, array $data, int $offsetMinutes, array $message): void
    {
        match ($kind) {
            'session' => $this->session($context, $data, (string)($message['device']['firmware'] ?? '')),
            // A bateria vem em toda a sessão, mesmo com a pulseira pousada.
            'battery' => $this->publishBattery($data, $context),
            // Medição ao vivo, pedida por comando.
            'measurement' => $this->publishMeasurement($data, $context),
            // O gateway junta os pacotes da onda do ECG e entrega o traçado completo de uma vez.
            'ecg_wave' => $this->publishEcg($data, $context),
            // O relatório de uma noite, já calculado pelo firmware; os instantes vêm no relógio da pulseira.
            'sleep' => $this->emitAll($context, SleepNormalizer::normalize($data, DeviceDescriptor::of($context->deviceKey, $context->device), $context->gatewayKey, $offsetMinutes)),
            'command_result' => $this->commandResult($context, $data),
            'daily_block' => $this->publishBlocks($context, $data, $offsetMinutes),
            default => $this->warnUnhandled($context->deviceKey, $kind),
        };
    }

    /**
     * A sessão aberta é quando a pulseira é alcançável, e é aqui que a fila é drenada. Repete-se
     * enquanto a ligação BLE durar; um gateway antigo só manda o `authenticated`.
     *
     * @param array<string, mixed> $data
     */
    private function session(GattDeviceContext $context, array $data, string $firmware): void
    {
        if (($data['connected'] ?? true) === false || ($data['authenticated'] ?? true) === false) {
            unset($this->sessionGateway[$context->deviceKey]);
            $this->presence($context)->markOffline($context->deviceKey, $context->device, $context->gatewayKey);
            return;
        }

        $this->presence($context)->markOnline($context->deviceKey, $context->device, $context->gatewayKey);
        $this->publishFirmware($firmware, $context);
        $this->sessionGateway[$context->deviceKey] = $context->gatewayKey;
        $this->downlinkDispatcher->dispatchPending($context->deviceKey, $context->gatewayKey);
    }

    /** @param array<string, mixed> $data */
    private function commandResult(GattDeviceContext $context, array $data): void
    {
        $operation = (string)($data['operation'] ?? '');
        // Executar não é medir: sem valor nem razão da pulseira, o pedido falha. Um gateway
        // antigo só manda o `outcome`.
        $outcome = (string)($data['outcome'] ?? '');
        if (($data['ok'] ?? null) === false || $outcome === self::SILENT_OUTCOME) {
            $reason = (string)($data['error'] ?? '') ?: $outcome ?: 'write_failed';
            $dedupeKey = (string)($data['dedupeKey'] ?? '');
            if (str_starts_with($operation, 'measure.')) {
                $this->fail($context, $reason, $operation, $dedupeKey);
            } else {
                $this->downlinkDispatcher->failPending($context->deviceKey, $operation, $reason, $dedupeKey);
            }

            return;
        }

        // Responder não é medir: fora do pulso responde com zeros, e o que conta como
        // leitura decide-se deste lado.
        if (str_starts_with($operation, 'measure.')) {
            // Já falhou com a razão certa: a confirmação não o falha segunda vez.
            if ($this->readings->discardConfirmation($context->deviceKey, $operation)) {
                return;
            }

            $settled = $this->readings->takeSettled($context->deviceKey, $operation);
            if ($settled === null && $operation !== MeasurementNormalizer::ECG_OPERATION) {
                $this->fail($context, 'no_reading', $operation);

                return;
            }
            if ($settled !== null) {
                $this->emit($context, $settled);
            }
        }

        $this->downlinkDispatcher->resolvePending($context->deviceKey, (string)($data['dedupeKey'] ?? ''));
    }

    /** Um `kind` sem normalização avisa uma vez por espécie e por aparelho. */
    private function warnUnhandled(string $deviceKey, string $kind): void
    {
        $seenKey = $deviceKey . '|' . $kind;
        if ($kind !== '' && !isset($this->unhandledKinds[$seenKey])) {
            $this->unhandledKinds[$seenKey] = true;
            Logger::channel('hub')->warning("Veepoo kind sem normalização: {$kind} de {$deviceKey}");
        }
    }

    /**
     * O SDK entrega ora um bloco ora uma lista deles, conforme o pacote.
     *
     * @param array<mixed> $payload
     */
    private function publishBlocks(GattDeviceContext $context, array $payload, int $offsetMinutes): void
    {
        $identity = DeviceDescriptor::of($context->deviceKey, $context->device);
        foreach (array_is_list($payload) ? $payload : [$payload] as $block) {
            // O gateway relê o dia corrente de cinco em cinco minutos: um bloco igual já saiu.
            if (!is_array($block) || !$this->state->acceptObservation($context->deviceKey, self::blockFingerprint($block), self::REPLAY_TTL_SECONDS)) {
                continue;
            }

            $this->emitAll($context, $this->normalizer->normalize($block, $identity, $context->gatewayKey, $offsetMinutes));
        }
    }

    /** @param list<array<string, mixed>> $telemetry */
    private function emitAll(GattDeviceContext $context, array $telemetry): void
    {
        foreach ($telemetry as $entry) {
            $this->emit($context, $entry);
        }
    }

    /**
     * A dashboard guarda cem entradas para consulta, e os 288 blocos diários esgotavam-na: fica de
     * fora um bloco de atividade a zeros e a versão de firmware repetida.
     *
     * @param array<string, mixed> $telemetry
     */
    protected function keepsInHistory(string $deviceKey, array $telemetry): bool
    {
        $type = (string)($telemetry['type'] ?? '');

        if ($type === 'firmware_version') {
            $version = (string)($telemetry['data']['version'] ?? '');
            if (($this->lastFirmwareShown[$deviceKey] ?? null) === $version) {
                return false;
            }
            $this->lastFirmwareShown[$deviceKey] = $version;

            return true;
        }

        if ($type !== 'activity') {
            return true;
        }

        foreach ($telemetry['data'] ?? [] as $value) {
            if (is_int($value) && $value > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Percentagem de bateria, só quando `VPDeviceIsPercent` o diz: sem a curva da célula, a
     * tensão não se converte. A bateria fraca vem à parte, e sai nos dois modos.
     *
     * @param array<string, mixed> $payload
     */
    private function publishBattery(array $payload, GattDeviceContext $context): void
    {
        $percent = $payload['VPDeviceElectricPercent'] ?? null;
        $percent = ($payload['VPDeviceIsPercent'] ?? false) === true && is_int($percent) && $percent >= 0 && $percent <= 100
            ? $percent
            : null;
        $data = Values::withoutNulls([
            'percent' => $percent,
            'lowBattery' => match ($payload['VPDeviceElectricTypeIsLowVoltage'] ?? null) {
                'lowVoltage' => true,
                'normal' => false,
                default => null,
            },
        ]);
        if ($data === []) {
            return;
        }

        $battery = TelemetryEnvelope::for(
            'battery',
            $context->deviceKey,
            $context->device,
            self::PROTOCOL,
            'battery',
            $data,
            $context->gatewayKey,
        );
        $this->emit($context, $battery);

        $lowBattery = $this->lowBattery->observe($context->deviceKey, $battery);
        if ($lowBattery !== null) {
            $this->mqttBridge->publishAndStore($this->deviceStore, 'events', $context->deviceKey, $lowBattery, self::DEVICE_TYPE, $context->licenseId, $context->company);
        }
    }

    /** A versão de firmware, tal como a sessão a traz; sai em cada sessão, mesmo repetida. */
    private function publishFirmware(string $firmware, GattDeviceContext $context): void
    {
        if ($firmware !== '') {
            $this->telemetry($context, 'firmware_version', 'session', ['version' => $firmware]);
        }
    }

    /**
     * Uma medição a pedido traz um valor só e o estado do sensor. Um zero é sinal ainda por
     * fixar, e `notWear` a pulseira sem contacto com a pele: nenhum é leitura.
     *
     * @param array<string, mixed> $payload
     */
    private function publishMeasurement(array $payload, GattDeviceContext $context): void
    {
        $sdkType = (int)($payload['sdkType'] ?? 0);

        // Um `beMeasuring*` é ocupação passageira; bateria fraca e sensor anómalo são razões a mostrar.
        $detection = (string)($payload['deviceDetectionInfo'] ?? '');
        if (isset(self::DETECTION_FAILURES[$detection])) {
            $this->fail($context, self::DETECTION_FAILURES[$detection], MeasurementNormalizer::operationForSdkType($sdkType));
            return;
        }

        // O SDK manda verificar por esta ordem: primeiro a ocupação, depois a deteção de uso.
        if (($payload['deviceBusy'] ?? false) === true) {
            return;
        }

        // O ECG tem campo próprio de deteção de uso: exige o dedo no elétrodo.
        if (($payload['notWear'] ?? false) === true || ($payload['wearStatus'] ?? '') === 'wearNotPass') {
            $this->fail($context, 'not_worn', MeasurementNormalizer::operationForSdkType($sdkType));
            return;
        }

        // As tramas de estado do ECG, uma por segundo, não são telemetria: o resumo sai com a onda.
        if ($sdkType === self::ECG_SDK_TYPE) {
            return;
        }

        $measurement = MeasurementNormalizer::forSdkType($sdkType, $payload);
        if ($measurement === null) {
            // Um tipo conhecido sem leitura ainda está a medir; um desconhecido avisa uma vez
            // por aparelho, como um `kind`.
            $seenKey = $context->deviceKey . '|sdk:' . $sdkType;
            if (!MeasurementNormalizer::knowsSdkType($sdkType) && $sdkType !== 0 && !isset($this->unhandledKinds[$seenKey])) {
                $this->unhandledKinds[$seenKey] = true;
                Logger::channel('hub')->warning(
                    "Veepoo tipo do SDK sem normalização: {$sdkType} de {$context->deviceKey}"
                );
            }

            return;
        }

        [$type, $data] = $measurement;

        // O tipo do fabricante, por que se vai à documentação da Veepoo.
        $telemetry = TelemetryEnvelope::for($type, $context->deviceKey, $context->device, self::PROTOCOL, "type-{$sdkType}", $data, $context->gatewayKey);

        // Os totais do dia e a procura da pulseira respondem numa trama só, sem assentar.
        $operation = MeasurementNormalizer::operationForSdkType($sdkType);
        if ($operation === null) {
            $this->emit($context, $telemetry);

            return;
        }

        $this->readings->hold($context, $operation, $telemetry);
    }

    /**
     * O traçado de um ECG. A pulseira grava os trinta segundos haja sinal ou não, e um traçado
     * todo a zero não é exame.
     *
     * @param array<string, mixed> $payload
     */
    private function publishEcg(array $payload, GattDeviceContext $context): void
    {
        $samples = $payload['samples'] ?? null;
        if (!is_array($samples) || $samples === []) {
            return;
        }

        $samples = array_values(array_filter($samples, static fn(mixed $v): bool => is_int($v) || is_float($v)));
        if ($samples === []) {
            return;
        }

        if (count(array_filter($samples, static fn(int|float $v): bool => $v !== 0)) === 0) {
            $this->fail($context, 'no_signal', MeasurementNormalizer::ECG_OPERATION);
            return;
        }

        $frequencyHz = is_int($payload['samplingHz'] ?? null) ? $payload['samplingHz'] : null;

        // O que o exame mediu vai com ele, e não como telemetria solta do sensor ótico.
        $status = is_array($payload['status'] ?? null) ? $payload['status'] : [];
        $measured = MeasurementNormalizer::ecgSummary(array_values(array_filter(
            $status,
            static fn(mixed $frame): bool => is_array($frame),
        )));

        $this->telemetry($context, 'ecg', 'ecg_wave', Values::withoutNulls(['samples' => $samples, 'frequencyHz' => $frequencyHz]) + $measured);
    }

    /** Diz porque é que a medição não saiu, e encerra o pedido que a mandou fazer. */
    private function fail(GattDeviceContext $context, string $reason, ?string $operation, string $dedupeKey = ''): void
    {
        $this->failures->report(
            $context->deviceKey,
            $context->device,
            $context->licenseId,
            $context->company,
            $reason,
        );
        if ($operation !== null) {
            $this->downlinkDispatcher->failPending($context->deviceKey, $operation, $reason, $dedupeKey);
            $this->readings->close($context->deviceKey, $operation);
        }
    }

    /**
     * Um bloco identifica-se por tudo o que traz, e não só pelo `date`: o do intervalo a
     * decorrer chega incompleto e completa-se na leitura seguinte.
     *
     * @param array<mixed> $block
     */
    private static function blockFingerprint(array $block): string
    {
        return hash('sha256', json_encode($block, JSON_THROW_ON_ERROR));
    }
}
