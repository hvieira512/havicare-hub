<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\State\DeviceCommandLog;
use Hub\State\DeviceReportStore;
use Hub\Device\CommercialModelResolver;
use Hub\Device\DeviceDescriptor;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlinkQueue;
use Hub\Device\TelemetryEnvelope;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\DispatchesQueued;
use Hub\Ingress\Mqtt\Gateway\GatewayTopic;
use Hub\Ingress\Mqtt\Gateway\ObservationStateStore;
use Hub\Ingress\Mqtt\MqttBridgeBase;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use Hub\Support\Values;
use PhpMqtt\Client\MqttClient;

/**
 * Ingestão das pulseiras Veepoo entregues por um gateway BLE, que traz da sessão GATT o que a
 * pulseira lhe deu já estruturado pelo SDK do fabricante.
 */
final class VeepooBridge extends MqttBridgeBase implements DispatchesQueued
{
    /** Estados que o aparelho reporta durante uma medição, e o que significam para o pedido. */
    private const DETECTION_FAILURES = [
        'atLowVoltage' => 'low_battery',
        'wrongfulValue' => 'sensor_fault',
    ];

    private const PROTOCOL = 'veepoo-ble';

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

    private readonly BraceletPresence $presence;

    private readonly MeasurementFailureReporter $failures;

    private readonly SettlingReadings $readings;

    public function __construct(
        MqttClient $subscriber,
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        private readonly GatewayDeviceLinkLookup $links,
        ?PendingDownlinkQueue $downlinks,
        private readonly ObservationStateStore $state,
        string $topicFilter,
        ?callable $reconnectSubscriber = null,
        null|(DeviceReportStore&DeviceCommandLog) $deviceStore = null,
        ?callable $clock = null,
        ?CommercialModelResolver $commercialModelResolver = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            'veepoo',
            $reconnectSubscriber,
            $deviceStore,
            clock: $clock,
            commercialModelResolver: $commercialModelResolver,
        );
        $this->normalizer = new DailyBlockNormalizer();
        $this->downlinkDispatcher = new DownlinkDispatcher(
            $downlinks,
            $mqttBridge,
            $deviceStore,
            $topicFilter,
        );
        $this->presence = new BraceletPresence($mqttBridge, $deviceStore);
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
            $this->emitTelemetry($due['context'], $due['telemetry']);
        }
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $message = json_decode($payload, true);
        // O mesmo tópico serve gateways de outras marcas; só reclamamos o que é nosso.
        if (!is_array($message) || ($message['source'] ?? null) !== 'veepoo-node') {
            return;
        }

        $parsed = GatewayTopic::parse($topic);
        $gateway = $parsed === null ? null : $this->whitelist->resolve($parsed->gatewayMac);
        if ($gateway === null || ($gateway['deviceType'] ?? '') !== 'gateway') {
            return;
        }

        $mac = GatewayTopic::normalizeMac((string)($message['device']['mac'] ?? ''));
        $device = $mac === null ? null : $this->whitelist->resolve($mac);
        if ($device === null || ($device['deviceType'] ?? '') !== 'bracelet') {
            $this->recordUnauthorizedDevice((string)$mac, 'veepoo-ble', (string)($message['device']['model'] ?? ''), ident: (string)$mac);
            return;
        }

        // Como no MOKO: só pulseiras ligadas ao gateway, e do mesmo cliente.
        if (
            !$this->links->isEnabled((string)$gateway['imei'], (string)$device['imei'])
            || !$this->sameTenant($gateway, $device)
        ) {
            $this->warnRepeatedly(
                "unlinked:{$mac}:{$gateway['imei']}",
                "Ignoring unlinked veepoo device={$mac} gateway={$gateway['imei']}"
            );
            return;
        }

        $device = $this->enrichWithCommercialName($device);
        $deviceKey = (string)$device['imei'];
        $licenseId = (int)($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');
        $context = new BraceletContext($deviceKey, (string)$gateway['imei'], $device, $licenseId, $company);

        $this->mqttBridge->publishRaw($deviceKey, $message, 'bracelet', $licenseId, $company);

        // A sessão aberta é quando a pulseira é alcançável, e é aqui que a fila é drenada.
        if (($message['kind'] ?? null) === 'session') {
            // A sessão repete-se enquanto a ligação BLE durar, e a perda chega como não autenticada.
            if (($message['payload']['authenticated'] ?? true) === false) {
                unset($this->sessionGateway[$deviceKey]);
                $this->presence->markOffline($deviceKey, $device, $licenseId, $company);
                return;
            }

            $this->presence->markOnline($deviceKey, $device, $licenseId, $company);
            $this->publishFirmware((string)($message['device']['firmware'] ?? ''), $context);
            $this->sessionGateway[$deviceKey] = (string)$gateway['imei'];
            $this->downlinkDispatcher->dispatchPending($deviceKey, (string)$gateway['imei']);
            return;
        }

        // A bateria vem em toda a sessão, mesmo com a pulseira pousada.
        if (($message['kind'] ?? null) === 'battery') {
            $this->publishBattery($message['payload'] ?? null, $context);
            return;
        }

        // Medição ao vivo, pedida por comando.
        if (($message['kind'] ?? null) === 'measurement') {
            $this->publishMeasurement($message['payload'] ?? null, $context);
            return;
        }

        // O gateway junta os pacotes da onda do ECG e entrega o traçado completo de uma vez.
        if (($message['kind'] ?? null) === 'ecg_wave') {
            $this->publishEcg($message['payload'] ?? null, $context);
            return;
        }

        // O relatório de uma noite, já calculado pelo firmware.
        if (($message['kind'] ?? null) === 'sleep') {
            $identity = DeviceDescriptor::of($deviceKey, $device);
            $payload = $message['payload'] ?? null;
            // Os instantes da noite vêm no relógio da pulseira; é este desvio que os põe em UTC.
            $offset = is_int($message['tzOffsetMinutes'] ?? null) ? $message['tzOffsetMinutes'] : 0;
            foreach (SleepNormalizer::normalize(is_array($payload) ? $payload : [], $identity, (string)$gateway['imei'], $offset) as $telemetry) {
                $this->emitTelemetry($context, $telemetry);
            }

            return;
        }

        if (($message['kind'] ?? null) === 'command_result') {
            // Executar não é medir: sem valor nem razão da pulseira, o pedido falha.
            if ((string)($message['payload']['outcome'] ?? '') === self::SILENT_OUTCOME) {
                $this->fail(
                    $context,
                    self::SILENT_OUTCOME,
                    (string)($message['payload']['operation'] ?? '') ?: null,
                );

                return;
            }

            $operation = (string)($message['payload']['operation'] ?? '');
            // Responder não é medir: fora do pulso responde com zeros, e o que conta como
            // leitura decide-se deste lado.
            if (str_starts_with($operation, 'measure.')) {
                // Já falhou com a razão certa: a confirmação não o falha segunda vez.
                if ($this->readings->discardConfirmation($deviceKey, $operation)) {
                    return;
                }

                $settled = $this->readings->takeSettled($deviceKey, $operation);
                if ($settled === null && $operation !== MeasurementNormalizer::ECG_OPERATION) {
                    $this->fail($context, 'no_reading', $operation);

                    return;
                }
                if ($settled !== null) {
                    $this->emitTelemetry($context, $settled);
                }
            }

            $this->downlinkDispatcher->resolvePending($deviceKey, (string)($message['payload']['dedupeKey'] ?? ''));
            return;
        }

        // Um `kind` sem normalização avisa uma vez por espécie e por aparelho.
        if (($message['kind'] ?? null) !== 'daily_block') {
            $kind = (string)($message['kind'] ?? '');
            $seenKey = $deviceKey . '|' . $kind;
            if ($kind !== '' && !isset($this->unhandledKinds[$seenKey])) {
                $this->unhandledKinds[$seenKey] = true;
                Logger::channel('hub')->warning(
                    "Veepoo kind sem normalização: {$kind} de {$deviceKey}"
                );
            }

            return;
        }

        $identity = [
            'id' => $deviceKey,
            'supplier' => (string)($device['supplier'] ?? ''),
            'model' => (string)($device['model'] ?? ''),
        ];

        foreach ($this->blocks($message['payload'] ?? null) as $block) {
            // O gateway relê o dia corrente de cinco em cinco minutos: um bloco igual já saiu.
            if (!$this->state->acceptObservation($deviceKey, self::blockFingerprint($block), self::REPLAY_TTL_SECONDS)) {
                continue;
            }

            $offset = is_int($message['tzOffsetMinutes'] ?? null) ? $message['tzOffsetMinutes'] : 0;
            foreach ($this->normalizer->normalize($block, $identity, (string)$gateway['imei'], $offset) as $telemetry) {
                $this->emitTelemetry($context, $telemetry);
            }
        }
    }

    /**
     * Publica telemetria no MQTT, para quem integra, e no histórico da dashboard, para quem opera.
     *
     * @param array<string, mixed> $telemetry
     */
    private function emitTelemetry(BraceletContext $context, array $telemetry): void
    {
        $this->mqttBridge->publishTelemetry(
            $context->deviceKey,
            $telemetry,
            'bracelet',
            $context->licenseId,
            $context->company,
        );

        // A dashboard guarda cem entradas para consulta; os 288 blocos diários esgotavam-na.
        if (!$this->worthShowing($context->deviceKey, $telemetry)) {
            return;
        }

        $this->deviceStore?->append($context->deviceKey, 'telemetry', self::forDashboard($telemetry) + [
            'deviceType' => 'bracelet',
            'licenseId' => $context->licenseId,
        ]);
    }

    /**
     * A mesma telemetria, sem o que não cabe num histórico de consulta: o traçado de um ECG,
     * dezasseis mil amostras, só sai pelo MQTT.
     *
     * @param array<string, mixed> $telemetry
     * @return array<string, mixed>
     */
    private static function forDashboard(array $telemetry): array
    {
        $samples = $telemetry['data']['samples'] ?? null;
        if (!is_array($samples)) {
            return $telemetry;
        }

        $telemetry['data']['sampleCount'] = count($samples);
        unset($telemetry['data']['samples']);

        return $telemetry;
    }

    /**
     * Se uma leitura tem valor de consulta: tudo menos um bloco de atividade a zeros e a versão
     * de firmware repetida.
     *
     * @param array<string, mixed> $telemetry
     */
    private function worthShowing(string $deviceKey, array $telemetry): bool
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
     * tensão não se converte.
     *
     * @param array<string, mixed>|null $payload
     */
    private function publishBattery(mixed $payload, BraceletContext $context): void
    {
        if (!is_array($payload) || ($payload['VPDeviceIsPercent'] ?? false) !== true) {
            return;
        }

        $percent = $payload['VPDeviceElectricPercent'] ?? null;
        if (!is_int($percent) || $percent < 0 || $percent > 100) {
            return;
        }

        $this->emitTelemetry($context, TelemetryEnvelope::for(
            'battery',
            $context->deviceKey,
            $context->device,
            self::PROTOCOL,
            'battery',
            Values::withoutNulls([
                'percent' => $percent,
                'lowBattery' => ($payload['VPDeviceElectricTypeIsLowVoltage'] ?? null) === 'lowVoltage' ? true : null,
            ]),
            $context->gatewayKey,
        ));
    }

    /** A versão de firmware, tal como a sessão a traz; sai em cada sessão, mesmo repetida. */
    private function publishFirmware(string $firmware, BraceletContext $context): void
    {
        if ($firmware === '') {
            return;
        }

        $this->emitTelemetry($context, TelemetryEnvelope::for(
            'firmware_version',
            $context->deviceKey,
            $context->device,
            self::PROTOCOL,
            'session',
            ['version' => $firmware],
            $context->gatewayKey,
        ));
    }

    /**
     * Uma medição a pedido traz um valor só e o estado do sensor. Um zero é sinal ainda por
     * fixar, e `notWear` a pulseira sem contacto com a pele: nenhum é leitura.
     *
     * @param array<string, mixed>|null $payload
     */
    private function publishMeasurement(mixed $payload, BraceletContext $context): void
    {
        if (!is_array($payload)) {
            return;
        }

        // Um `beMeasuring*` é ocupação passageira; bateria fraca e sensor anómalo são razões a mostrar.
        $detection = (string)($payload['deviceDetectionInfo'] ?? '');
        if (isset(self::DETECTION_FAILURES[$detection])) {
            $this->fail(
                $context,
                self::DETECTION_FAILURES[$detection],
                MeasurementNormalizer::operationForSdkType((int)($payload['sdkType'] ?? 0)),
            );
            return;
        }

        // O SDK manda verificar por esta ordem: primeiro a ocupação, depois a deteção de uso.
        if (($payload['deviceBusy'] ?? false) === true) {
            return;
        }

        // O ECG tem campo próprio de deteção de uso: exige o dedo no elétrodo.
        if (($payload['notWear'] ?? false) === true || ($payload['wearStatus'] ?? '') === 'wearNotPass') {
            $this->fail(
                $context,
                'not_worn',
                MeasurementNormalizer::operationForSdkType((int)($payload['sdkType'] ?? 0)),
            );
            return;
        }

        $sdkType = (int)($payload['sdkType'] ?? 0);

        // As tramas de estado do ECG, uma por segundo, não são telemetria: o resumo sai com a onda.
        if ($sdkType === self::ECG_SDK_TYPE) {
            return;
        }

        $measurement = MeasurementNormalizer::forSdkType($sdkType, $payload);
        if ($measurement === null) {
            // Como um `kind`, um tipo do SDK sem normalização avisa uma vez por aparelho.
            $seenKey = $context->deviceKey . '|sdk:' . $sdkType;
            if ($sdkType !== 0 && !isset($this->unhandledKinds[$seenKey])) {
                $this->unhandledKinds[$seenKey] = true;
                Logger::channel('hub')->warning(
                    "Veepoo tipo do SDK sem normalização: {$sdkType} de {$context->deviceKey}"
                );
            }

            return;
        }

        [$type, $data] = $measurement;

        $telemetry = TelemetryEnvelope::for(
            $type,
            $context->deviceKey,
            $context->device,
            self::PROTOCOL,
            // O tipo do fabricante, por que se vai à documentação da Veepoo.
            "type-{$sdkType}",
            $data,
            $context->gatewayKey,
        );

        // Os totais do dia e a procura da pulseira respondem numa trama só, sem assentar.
        $operation = MeasurementNormalizer::operationForSdkType($sdkType);
        if ($operation === null) {
            $this->emitTelemetry($context, $telemetry);

            return;
        }

        $this->readings->hold($context, $operation, $telemetry);
    }

    /**
     * O traçado de um ECG. A pulseira grava os trinta segundos haja sinal ou não, e um traçado
     * todo a zero não é exame.
     *
     * @param array<string, mixed>|null $payload
     */
    private function publishEcg(mixed $payload, BraceletContext $context): void
    {
        $samples = is_array($payload) ? ($payload['samples'] ?? null) : null;
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

        $this->emitTelemetry($context, TelemetryEnvelope::for(
            'ecg',
            $context->deviceKey,
            $context->device,
            self::PROTOCOL,
            'ecg_wave',
            Values::withoutNulls(['samples' => $samples, 'frequencyHz' => $frequencyHz]) + $measured,
            $context->gatewayKey,
        ));
    }



    /** Diz porque é que a medição não saiu, e encerra o pedido que a mandou fazer. */
    private function fail(BraceletContext $context, string $reason, ?string $operation): void
    {
        $this->failures->report(
            $context->deviceKey,
            $context->device,
            $context->licenseId,
            $context->company,
            $reason,
        );
        if ($operation !== null) {
            $this->downlinkDispatcher->failPending($context->deviceKey, $operation, $reason);
            $this->readings->close($context->deviceKey, $operation);
        }
    }

    /**
     * Um bloco identifica-se por tudo o que traz, e não só pelo `date`: o do intervalo a
     * decorrer chega incompleto e completa-se na leitura seguinte.
     *
     * @param array<string, mixed> $block
     */
    private static function blockFingerprint(array $block): string
    {
        return hash('sha256', json_encode($block, JSON_THROW_ON_ERROR));
    }

    /**
     * O SDK entrega ora um bloco ora uma lista deles, conforme o pacote.
     *
     * @return list<array<string, mixed>>
     */
    private function blocks(mixed $payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        return array_values(array_filter(
            array_is_list($payload) ? $payload : [$payload],
            static fn(mixed $block): bool => is_array($block),
        ));
    }
}
