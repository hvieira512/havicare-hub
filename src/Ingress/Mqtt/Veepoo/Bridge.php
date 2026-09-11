<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlinkQueue;
use Hub\Device\RawPayload;
use Hub\Domain\GatewayDeviceLinkLookup;
use Hub\Ingress\Mqtt\Gateway\ObservationStateStore;
use Hub\Ingress\Mqtt\Gateway\Topic;
use Hub\Log\Logger;
use Hub\Registry\Whitelist;
use PhpMqtt\Client\MqttClient;

/**
 * Ingestão das pulseiras Veepoo entregues por um gateway BLE.
 *
 * O gateway publica no espaço de tópicos do hub, tal como o MOKO, mas ao contrário deste não
 * repete um anúncio: conduziu uma sessão GATT autenticada e traz o que a pulseira lhe deu já
 * estruturado pelo SDK do fabricante. O que falta é dar-lhe os nomes do hub.
 */
final class Bridge extends \Hub\Ingress\Mqtt\Bridge
{
    /** Intervalo válido documentado pelo fabricante; fora dele o firmware devolve sentinelas. */
    private const HEART_RATE_MIN = 30;
    private const HEART_RATE_MAX = 250;

    /**
     * Estados que o aparelho reporta durante uma medição, e o que significam para o pedido.
     *
     * O firmware não se limita a devolver valores: diz em que estado está. Sem isto um pedido
     * que morreu por bateria fraca ou por sensor avariado ficava em fila até expirar, sem
     * ninguém saber porquê -- que era o que acontecia antes.
     */
    private const DETECTION_FAILURES = [
        'atLowVoltage' => 'low_battery',
        'wrongfulValue' => 'sensor_fault',
    ];

    /** Quanto tempo a mesma queixa do mesmo aparelho fica calada depois de relatada. */
    private const FAILURE_REPEAT_SECONDS = 60;

    /**
     * Quanto tempo um comando entregue e não confirmado espera antes de ser repetido.
     *
     * O gateway ignora a mesma chave durante minutos para não executar duas vezes a mesma
     * entrega, por isso repeti-la a cada volta do temporizador não o acordaria -- só encheria
     * o tópico. A repetição serve para o caso de a entrega se ter perdido.
     */
    private const RESEND_SECONDS = 30;

    /**
     * Quanto tempo um bloco fica reconhecido como já publicado.
     *
     * Tem de exceder a janela que o gateway consegue reproduzir: o `RETENTION_DAYS` dele, três
     * dias por omissão, mais o dia corrente. Cinco dias dá folga sem a memória pesar -- são
     * 288 blocos por dia e por pulseira, e cada um é uma chave curta com prazo.
     */
    private const REPLAY_TTL_SECONDS = 5 * 86400;

    /**
     * Quando cada par aparelho/motivo foi relatado pela última vez.
     *
     * @var array<string, int>
     */
    private array $lastFailureAt = [];

    /**
     * Se cada pulseira estava alcançável da última vez que o gateway falou dela.
     *
     * @var array<string, bool>
     */
    private array $online = [];

    /**
     * Por que gateway está cada pulseira com sessão aberta, para lhe entregar o que chegar
     * à fila entretanto.
     *
     * @var array<string, string>
     */
    private array $sessionGateway = [];

    /**
     * Quando cada comando em fila foi entregue pela última vez.
     *
     * @var array<string, int>
     */
    private array $sentAt = [];

    /**
     * Espécies de mensagem já relatadas como não normalizadas, por aparelho.
     *
     * @var array<string, true>
     */
    private array $unhandledKinds = [];

    /**
     * A última versão de firmware publicada por aparelho.
     *
     * @var array<string, string>
     */
    private array $firmware = [];

    private readonly DailyBlockNormalizer $normalizer;

    public function __construct(
        MqttClient $subscriber,
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        private readonly GatewayDeviceLinkLookup $links,
        private readonly ?PendingDownlinkQueue $downlinks,
        private readonly ObservationStateStore $state,
        string $topicFilter,
        ?callable $reconnectSubscriber = null,
        ?DashboardStoreContract $dashboardStore = null,
        ?DailyBlockNormalizer $normalizer = null,
    ) {
        parent::__construct(
            $subscriber,
            $whitelist,
            $mqttBridge,
            $topicFilter,
            'veepoo',
            $reconnectSubscriber,
            $dashboardStore,
        );
        $this->normalizer = $normalizer ?? new DailyBlockNormalizer();
    }

    protected function handleMessage(string $topic, string $payload): void
    {
        $message = json_decode($payload, true);
        // O mesmo tópico serve gateways de outras marcas; só reclamamos o que é nosso.
        if (!is_array($message) || ($message['source'] ?? null) !== 'veepoo-node') {
            return;
        }

        $parsed = Topic::parse($topic);
        $gateway = $parsed === null ? null : $this->whitelist->resolve($parsed->gatewayMac);
        if ($gateway === null || ($gateway['deviceType'] ?? '') !== 'gateway') {
            return;
        }

        $mac = Topic::normalizeMac((string)($message['device']['mac'] ?? ''));
        $device = $mac === null ? null : $this->whitelist->resolve($mac);
        if ($device === null || ($device['deviceType'] ?? '') !== 'bracelet') {
            $this->recordUnauthorizedDevice((string)$mac, 'veepoo-ble', (string)($message['device']['model'] ?? ''), ident: (string)$mac);
            return;
        }

        // A mesma condição do caminho MOKO: um gateway não fala por pulseiras que não são dele,
        // nem atravessa a fronteira entre clientes.
        if (
            !$this->links->isEnabled((string)$gateway['imei'], (string)$device['imei'])
            || !$this->sameTenant($gateway, $device)
        ) {
            Logger::channel('hub')->warning(
                "Ignoring unlinked veepoo device={$mac} gateway={$gateway['imei']}"
            );
            return;
        }

        $deviceKey = (string)$device['imei'];
        $licenseId = (int)($device['licenseId'] ?? 0);
        $company = (string)($device['company'] ?? 'null');

        $this->mqttBridge->publishRaw($deviceKey, $message, 'bracelet', $licenseId, $company);

        // O gateway avisa quando abre sessão com a pulseira, e esse é o único instante em que
        // ela é alcançável. É aqui que a fila é drenada -- não quando o comando é criado.
        if (($message['kind'] ?? null) === 'session') {
            // A sessão repete-se enquanto a ligação BLE durar, e deixa de chegar quando ela
            // cai. É por isso que o campo diz se está autenticada em vez de se limitar a
            // existir: o gateway avisa da perda em vez de emudecer.
            if (($message['payload']['authenticated'] ?? true) === false) {
                unset($this->sessionGateway[$deviceKey]);
                $this->markOffline($deviceKey, $device, $licenseId, $company);
                return;
            }

            $this->markOnline($deviceKey, $device, $licenseId, $company);
            $this->publishFirmware(
                (string)($message['device']['firmware'] ?? ''),
                $deviceKey,
                (string)$gateway['imei'],
                $device,
                $licenseId,
                $company,
            );
            $this->sessionGateway[$deviceKey] = (string)$gateway['imei'];
            $this->dispatchPending($deviceKey, (string)$gateway['imei']);
            return;
        }

        // A bateria vem em toda a sessão e é o único valor que não depende do sensor ótico:
        // chega mesmo com a pulseira pousada. Sem isto ficava só no `raw`.
        if (($message['kind'] ?? null) === 'battery') {
            $this->publishBattery($message['payload'] ?? null, $deviceKey, (string)$gateway['imei'], $device, $licenseId, $company);
            return;
        }

        // Medição ao vivo, pedida por comando. Fecha o ciclo: sem a telemetria de volta o
        // registo de comandos nunca dá o pedido por cumprido e repete-o até esgotar.
        if (($message['kind'] ?? null) === 'measurement') {
            $this->publishMeasurement($message['payload'] ?? null, $deviceKey, (string)$gateway['imei'], $device, $licenseId, $company);
            return;
        }

        // A onda do ECG chega à parte das tramas de estado: o gateway junta os pacotes de
        // uma medição e entrega o traçado completo de uma vez.
        if (($message['kind'] ?? null) === 'ecg_wave') {
            $this->publishEcg($message['payload'] ?? null, $deviceKey, (string)$gateway['imei'], $device, $licenseId, $company);
            return;
        }

        if (($message['kind'] ?? null) === 'command_result') {
            $this->resolvePending($deviceKey, (string)($message['payload']['dedupeKey'] ?? ''));
            return;
        }

        // Um `kind` que ninguém reclama sai daqui em silêncio, e foi assim que o registo de
        // sono se perdeu sem ninguém dar por isso. Dizê-lo uma vez por espécie e por aparelho
        // chega para aparecer no diário sem o encher.
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
            // A pulseira reproduz o histórico por desenho -- não empurra nada, e o gateway
            // relê o dia corrente de cinco em cinco minutos e os dias retidos a cada arranque.
            // Um bloco igual a um que já saiu é a mesma medição, com o mesmo instante, e não
            // uma leitura nova: republicá-lo enchia o MQTT de repetições que quem integra não
            // distingue das boas, e expulsava do histórico da dashboard o que era real.
            if (!$this->state->acceptObservation($deviceKey, self::blockFingerprint($block), self::REPLAY_TTL_SECONDS)) {
                continue;
            }

            $offset = is_int($message['tzOffsetMinutes'] ?? null) ? $message['tzOffsetMinutes'] : 0;
            foreach ($this->normalizer->normalize($block, $identity, (string)$gateway['imei'], $offset) as $telemetry) {
                $this->emitTelemetry($deviceKey, $telemetry, $licenseId, $company);
            }
        }
    }

    /**
     * Entrega ao gateway o que estiver em fila para esta pulseira.
     *
     * Publica no canal de comandos do próprio gateway, e não no do aparelho: quem executa é
     * a caixa, que tem a sessão BLE. A criação continua a ser exclusiva da API REST -- isto é
     * entrega, o equivalente ao socket por onde um relógio recebe os seus.
     */
    /**
     * Entrega o que esteja em fila às pulseiras com sessão aberta.
     *
     * O gateway fica subscrito ao tópico de comandos enquanto correr, e por isso a pulseira
     * é alcançável entre sessões. Chamado por um temporizador do loop: sem isto uma ordem
     * dada no ecrã esperava pelo anúncio de sessão seguinte -- até 30 s, mais do que a
     * pulseira leva a desistir de vibrar.
     */
    public function dispatchQueued(): void
    {
        foreach ($this->sessionGateway as $deviceKey => $gatewayKey) {
            $this->dispatchPending($deviceKey, $gatewayKey);
        }
    }

    /** Se já passou o intervalo de repetição desde a última entrega desta chave. */
    private function dueForSending(string $dedupeKey): bool
    {
        $now = time();
        foreach ($this->sentAt as $key => $at) {
            if ($now - $at >= self::RESEND_SECONDS) {
                unset($this->sentAt[$key]);
            }
        }

        if (isset($this->sentAt[$dedupeKey])) {
            return false;
        }

        $this->sentAt[$dedupeKey] = $now;

        return true;
    }

    private function dispatchPending(string $deviceKey, string $gatewayKey): void
    {
        if ($this->downlinks === null) {
            return;
        }

        foreach ($this->downlinks->pendingFor($deviceKey) as $downlink) {
            // Para este protocolo os bytes em fila são o próprio nome da operação: quem os
            // monta é o `DeviceCommandCatalog`, que aqui não tem trama que construir.
            $command = $downlink->command ?? [];
            $operation = (string)($command['command'] ?? $downlink->bytes);
            if ($operation === '') {
                continue;
            }

            if (!$this->dueForSending($downlink->dedupeKey)) {
                continue;
            }

            $this->mqttBridge->publishGatewayCommand($this->commandTopicFor($gatewayKey), [
                'deviceId' => $deviceKey,
                'operation' => $operation,
                // O nome da operação diz o que fazer e não com que valor. Sem isto, desligar
                // um interruptor chegava ao gateway indistinguível de o ligar, e uma ordem de
                // parar -- como a de deixar de procurar a pulseira -- não existia de todo.
                'payload' => $command['payload'] ?? null,
                'dedupeKey' => $downlink->dedupeKey,
                'commandId' => $command['id'] ?? null,
                'expiresAt' => $downlink->expiresAt,
            ]);

            // Fica em fila de propósito. Entregar não é executar: se o gateway não chegar a
            // correr o comando, a sessão seguinte volta a recebê-lo, e o TTL da política é
            // que decide quando deixa de fazer sentido. Sai da fila em `resolvePending`,
            // quando a caixa confirma -- caso contrário perdia-se em silêncio.
            $this->dashboardStore?->markLatestCommand($deviceKey, $operation, [
                'status' => 'waiting',
                'sentAt' => gmdate('Y-m-d\TH:i:s\Z'),
            ]);

            Logger::channel('hub')->info(
                "Veepoo downlink {$operation} entregue ao gateway {$gatewayKey} para {$deviceKey}"
            );
        }
    }

    /**
     * Publica telemetria no MQTT e no histórico da dashboard.
     *
     * São dois destinos e não um: o MQTT serve quem integra, a dashboard serve quem opera.
     * Publicar só no primeiro deixa o aparelho a parecer mudo no ecrã, que foi exactamente o
     * que aconteceu antes de isto existir.
     *
     * @param array<string, mixed> $telemetry
     */
    private function emitTelemetry(string $deviceKey, array $telemetry, int $licenseId, string $company): void
    {
        $this->mqttBridge->publishTelemetry($deviceKey, $telemetry, 'bracelet', $licenseId, $company);

        // A dashboard guarda cem entradas e serve para consultar, não para arquivar. Uma
        // pulseira produz 288 blocos de cinco minutos por dia, e os que não trazem nada
        // esgotavam a lista em horas -- exactamente o que já acontecia com os relatórios de
        // varrimento. No MQTT continua a sair tudo; quem arquiva é quem integra.
        if (!self::worthShowing($telemetry)) {
            return;
        }

        $this->dashboardStore?->append($deviceKey, 'telemetry', self::forDashboard($telemetry) + [
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
        ]);
    }

    /**
     * A mesma telemetria, sem o que não cabe num histórico de consulta.
     *
     * Um traçado de ECG são dezasseis mil amostras. Quem integra quer o traçado inteiro e
     * recebe-o pelo MQTT; a dashboard guarda cem entradas por aparelho e nem sequer desenha
     * ondas -- guardá-lo lá era despejar megabytes no Redis para mostrar «Dados de ECG».
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
     * Se uma leitura tem valor de consulta.
     *
     * Um bloco de atividade a zeros é o que uma pulseira pousada produz de cinco em cinco
     * minutos: não é uma medição, é ausência dela. Tudo o resto passa, incluindo bateria e
     * sinais vitais, mesmo repetidos -- aí a repetição é a informação.
     *
     * @param array<string, mixed> $telemetry
     */
    private static function worthShowing(array $telemetry): bool
    {
        if (($telemetry['type'] ?? '') !== 'activity') {
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
     * Marca a pulseira como online.
     *
     * Ao contrário de um relógio, ela não mantém ligação: está online enquanto um gateway
     * tiver sessão aberta. O estado é publicado retido, para que quem subscreva a seguir o
     * receba sem esperar pela próxima ronda.
     *
     * @param array<string, mixed> $device
     */
    private function markOnline(string $deviceKey, array $device, int $licenseId, string $company): void
    {
        $supplier = (string)($device['supplier'] ?? '');
        $model = (string)($device['model'] ?? '');
        $commercial = (string)($device['commercialName'] ?? '');
        $wasOnline = $this->online[$deviceKey] ?? false;
        $this->online[$deviceKey] = true;

        // É isto que a dashboard e a API leem para dizer se o aparelho está online. Sem
        // esta linha o estado sai no MQTT e mais nada, e o ecrã continua a dizer offline.
        $this->dashboardStore?->deviceSeen($deviceKey, [
            'supplier' => $supplier,
            'model' => $model,
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
            'company' => $company,
            'protocol' => 'veepoo-ble',
            'transport' => 'ble_gateway',
            'online' => '1',
        ]);

        $status = RawPayload::status($deviceKey, $supplier, $model, 'online', null, $commercial);
        $this->mqttBridge->publishStatus($deviceKey, $status, true, 'bracelet', $licenseId, $company);

        // O estado é retido e vale a cada anúncio; o acontecimento é da transição. Repetir
        // «Ligado» a cada batimento enchia o histórico e escondia o instante em que a
        // pulseira se ligou de facto.
        if ($wasOnline) {
            return;
        }

        $this->announce($deviceKey, $device, $licenseId, $company, 'device.connected');
    }

    /**
     * A ligação BLE caiu: a pulseira afastou-se, ficou sem bateria ou foi desligada.
     *
     * Sem isto o ecrã continuava a mostrá-la ligada até o varrimento de aparelhos parados
     * dar por ela, o que é bastante depois de já não haver ninguém a quem entregar um
     * comando -- e a fila de espera continuaria a ser drenada contra um aparelho ausente.
     *
     * @param array<string, mixed> $device
     */
    private function markOffline(string $deviceKey, array $device, int $licenseId, string $company): void
    {
        if (($this->online[$deviceKey] ?? false) === false) {
            return;
        }
        $this->online[$deviceKey] = false;

        $this->dashboardStore?->deviceOffline($deviceKey);

        $status = RawPayload::status(
            $deviceKey,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            'offline',
            null,
            (string)($device['commercialName'] ?? ''),
        );
        $this->mqttBridge->publishStatus($deviceKey, $status, true, 'bracelet', $licenseId, $company);

        $this->announce($deviceKey, $device, $licenseId, $company, 'device.disconnected');
    }

    /**
     * Publica um acontecimento de ligação no MQTT e no histórico do aparelho.
     *
     * @param array<string, mixed> $device
     */
    private function announce(string $deviceKey, array $device, int $licenseId, string $company, string $type): void
    {
        $event = RawPayload::event(
            $deviceKey,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            $type,
            null,
            null,
            (string)($device['commercialName'] ?? ''),
        );

        $this->mqttBridge->publishEvent($deviceKey, $event, 'bracelet', $licenseId, $company);
        $this->dashboardStore?->append($deviceKey, 'events', $event + [
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
        ]);
    }

    /**
     * Percentagem de bateria da pulseira.
     *
     * O firmware reporta ora percentagem ora tensão, e diz qual em `VPDeviceIsPercent`. Só a
     * percentagem é publicada: converter milivolts em percentagem exigia conhecer a curva da
     * célula, que não temos, e um palpite aqui vira um alarme de bateria fraca errado.
     *
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $device
     */
    private function publishBattery(
        mixed $payload,
        string $deviceKey,
        string $gatewayKey,
        array $device,
        int $licenseId,
        string $company,
    ): void {
        if (!is_array($payload) || ($payload['VPDeviceIsPercent'] ?? false) !== true) {
            return;
        }

        $percent = $payload['VPDeviceElectricPercent'] ?? null;
        if (!is_int($percent) || $percent < 0 || $percent > 100) {
            return;
        }

        $this->emitTelemetry($deviceKey, [
            'type' => 'battery',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => [
                'id' => $deviceKey,
                'supplier' => (string)($device['supplier'] ?? ''),
                'model' => (string)($device['model'] ?? ''),
            ],
            'source' => ['protocol' => 'veepoo-ble', 'nativeType' => 'battery', 'gatewayId' => $gatewayKey],
            'data' => array_filter([
                'percent' => $percent,
                'lowBattery' => ($payload['VPDeviceElectricTypeIsLowVoltage'] ?? null) === 'lowVoltage' ? true : null,
            ], static fn(mixed $v): bool => $v !== null),
        ], $licenseId, $company);
    }

    /**
     * A versão de firmware, publicada quando muda.
     *
     * Vem em cada sessão, e a sessão repete-se a cada batimento: publicá-la sempre era ruído.
     * Não a publicar de todo deixava o hub sem saber que firmware está no pulso.
     *
     * @param array<string, mixed> $device
     */
    private function publishFirmware(
        string $firmware,
        string $deviceKey,
        string $gatewayKey,
        array $device,
        int $licenseId,
        string $company,
    ): void {
        if ($firmware === '' || ($this->firmware[$deviceKey] ?? null) === $firmware) {
            return;
        }

        $this->firmware[$deviceKey] = $firmware;
        $this->emitTelemetry($deviceKey, [
            'type' => 'firmware_version',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => [
                'id' => $deviceKey,
                'supplier' => (string)($device['supplier'] ?? ''),
                'model' => (string)($device['model'] ?? ''),
            ],
            'source' => ['protocol' => 'veepoo-ble', 'nativeType' => 'session', 'gatewayId' => $gatewayKey],
            'data' => ['version' => $firmware],
        ], $licenseId, $company);
    }

    /**
     * Uma medição a pedido traz um valor só e o estado do sensor.
     *
     * Um valor a zero não é uma leitura: é o firmware a dizer que ainda não fixou o sinal, e
     * `notWear` que a pulseira não está em contacto com a pele. Publicar qualquer um deles
     * dava um batimento inventado a quem consome.
     *
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $device
     */
    private function publishMeasurement(
        mixed $payload,
        string $deviceKey,
        string $gatewayKey,
        array $device,
        int $licenseId,
        string $company,
    ): void {
        if (!is_array($payload)) {
            return;
        }

        // O aparelho diz em que estado está. Um `beMeasuring*` é ocupação passageira e não
        // vale um acontecimento; bateria fraca e sensor anómalo são razões que o operador tem
        // de ver, porque explicam um pedido que nunca se cumpre.
        $detection = (string)($payload['deviceDetectionInfo'] ?? '');
        if (isset(self::DETECTION_FAILURES[$detection])) {
            $this->reportMeasurementFailure($deviceKey, $device, $licenseId, $company, self::DETECTION_FAILURES[$detection]);
            return;
        }

        // O SDK manda verificar por esta ordem: primeiro se o aparelho está ocupado, depois
        // se a deteção de uso passou. Ao contrário, uma medição recusada por estar a decorrer
        // outra apareceria como pulseira fora do pulso.
        if (($payload['deviceBusy'] ?? false) === true) {
            return;
        }

        // A pulseira reporta explicitamente quando a deteção de uso falha. Sem isto o pedido
        // ficava eternamente em fila e ninguém sabia porquê -- é o tipo de silêncio que faz um
        // cuidador carregar no botão três vezes.
        //
        // O ECG usa um campo próprio: exige o dedo no elétrodo e não só a pulseira no pulso,
        // e por isso falha com `wearNotPass` mesmo com a pulseira bem colocada.
        if (($payload['notWear'] ?? false) === true || ($payload['wearStatus'] ?? '') === 'wearNotPass') {
            $this->reportMeasurementFailure($deviceKey, $device, $licenseId, $company, 'not_worn');
            return;
        }

        $measurement = self::measurement((int)($payload['sdkType'] ?? 0), $payload);
        if ($measurement === null) {
            return;
        }

        [$type, $data] = $measurement;

        $this->emitTelemetry($deviceKey, [
            'type' => $type,
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => [
                'id' => $deviceKey,
                'supplier' => (string)($device['supplier'] ?? ''),
                'model' => (string)($device['model'] ?? ''),
            ],
            'source' => ['protocol' => 'veepoo-ble', 'nativeType' => 'measurement', 'gatewayId' => $gatewayKey],
            'data' => $data,
        ], $licenseId, $company);
    }

    /**
     * O traçado de um ECG.
     *
     * A pulseira grava os trinta segundos peça haja sinal ou não: pousada numa mesa devolve
     * dezasseis mil amostras a zero. Isso não é um exame -- é uma medição que não apanhou
     * nada, e é assim que tem de aparecer, senão fica no histórico um ECG de quem nunca
     * chegou a fazer nenhum.
     *
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed> $device
     */
    private function publishEcg(
        mixed $payload,
        string $deviceKey,
        string $gatewayKey,
        array $device,
        int $licenseId,
        string $company,
    ): void {
        $samples = is_array($payload) ? ($payload['samples'] ?? null) : null;
        if (!is_array($samples) || $samples === []) {
            return;
        }

        $samples = array_values(array_filter($samples, static fn(mixed $v): bool => is_int($v) || is_float($v)));
        if ($samples === []) {
            return;
        }

        if (count(array_filter($samples, static fn(int|float $v): bool => $v !== 0)) === 0) {
            $this->reportMeasurementFailure($deviceKey, $device, $licenseId, $company, 'no_signal');
            return;
        }

        $samplingHz = is_int($payload['samplingHz'] ?? null) ? $payload['samplingHz'] : null;

        $this->emitTelemetry($deviceKey, [
            'type' => 'ecg',
            'occurredAt' => gmdate('Y-m-d\TH:i:s\Z'),
            'device' => [
                'id' => $deviceKey,
                'supplier' => (string)($device['supplier'] ?? ''),
                'model' => (string)($device['model'] ?? ''),
            ],
            'source' => ['protocol' => 'veepoo-ble', 'nativeType' => 'ecg_wave', 'gatewayId' => $gatewayKey],
            'data' => array_filter(
                ['samples' => $samples, 'samplingHz' => $samplingHz],
                static fn(mixed $v): bool => $v !== null,
            ),
        ], $licenseId, $company);
    }

    /**
     * Traduz uma medição ao vivo para o tipo e os campos do hub, ou `null` se não houver
     * leitura que publicar.
     *
     * O tipo do SDK é que diz qual é a grandeza -- 51 frequência cardíaca, 31 oxigénio, 22
     * glicemia, 6 temperatura, 58 stress, 18 e 28 tensão. Enquanto a medição decorre o
     * firmware repete a mesma trama com o valor a zero, e por isso cada grandeza tem de dizer
     * o que é uma leitura válida: publicar o zero dava uma saturação de 0% a meio de uma
     * medição que estava a correr bem.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, float|int>}|null
     */
    private static function measurement(int $sdkType, array $payload): ?array
    {
        return match ($sdkType) {
            // O fabricante documenta o intervalo válido e manda filtrar o resto: fora dele o
            // firmware devolve sentinelas -- `0` enquanto procura, `1` quando desiste.
            51 => self::withinRange($payload['heartRate'] ?? null, self::HEART_RATE_MIN, self::HEART_RATE_MAX) === null
                ? null
                : ['heart_rate', ['bpm' => (int)$payload['heartRate']]],
            31 => self::withinRange($payload['bloodOxygen'] ?? null, 1, 100) === null
                ? null
                : ['blood_oxygen', ['spo2Percent' => (int)$payload['bloodOxygen']]],
            // A pulseira reporta em mmol/L e o hub publica em mg/dL, tal como no histórico.
            22 => ($glucose = self::positive($payload['bloodGlucose'] ?? null)) === null
                ? null
                : ['blood_sugar', ['glucoseMgDl' => round($glucose * DailyBlockNormalizer::MMOL_PER_L_TO_MG_PER_DL, 1)]],
            6 => ($body = self::positive($payload['bodyTemperature'] ?? null)) === null
                ? null
                : ['temperature', array_filter([
                    'bodyCelsius' => round($body, 1),
                    'surfaceCelsius' => ($skin = self::positive($payload['bodySurfaceTemperature'] ?? null)) === null
                        ? null
                        : round($skin, 1),
                ], static fn(mixed $v): bool => $v !== null)],
            58 => self::withinRange($payload['pressure'] ?? null, 1, 100) === null
                ? null
                : ['stress', ['score' => (int)$payload['pressure']]],
            18, 28 => self::bloodPressureReading($payload),
            32 => self::bodyComposition($payload),
            9 => self::dailyActivity($payload),
            17 => self::findDeviceState($payload),
            default => null,
        };
    }

    /**
     * O estado de quem manda a pulseira vibrar.
     *
     * `timeout` é ela a desistir sozinha ao fim de cerca de um minuto, e é a única maneira de
     * saber que parou sem ninguém lhe ter pedido.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, string>}|null
     */
    private static function findDeviceState(array $payload): ?array
    {
        $state = match ($payload['value'] ?? null) {
            'search' => 'searching',
            'find' => 'stopped',
            'timeout' => 'timed_out',
            default => null,
        };

        return $state === null ? null : ['find_device', ['state' => $state]];
    }

    /**
     * Os totais do dia, contados pela própria pulseira.
     *
     * Tipo à parte porque não é a mesma coisa que o `activity` dos blocos: aquele é o que se
     * andou em cinco minutos e este é o acumulado do dia. Somar os dois contava tudo duas
     * vezes. As calorias vêm em décimas, como nos blocos.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, float|int>}|null
     */
    private static function dailyActivity(array $payload): ?array
    {
        $steps = $payload['step'] ?? null;
        if (!is_int($steps) || $steps < 0) {
            return null;
        }

        return ['activity_daily', array_filter([
            'steps' => $steps,
            'distanceMeters' => is_int($payload['distance'] ?? null) ? $payload['distance'] : null,
            'caloriesKcal' => is_int($payload['calorie'] ?? null) ? round($payload['calorie'] / 10, 1) : null,
        ], static fn(mixed $v): bool => $v !== null)];
    }

    /**
     * Composição corporal, medida pelos elétrodos do ECG.
     *
     * Os nomes do fabricante não distinguem percentagem de quilos -- `muscleRate` e
     * `muscleMass` são a mesma palavra com sufixos que não dizem a unidade. Os do hub dizem.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: array<string, float>}|null
     */
    private static function bodyComposition(array $payload): ?array
    {
        $fields = [
            'BMI' => 'bmi',
            'bodyFatPercentage' => 'bodyFatPercent',
            'fatMass' => 'fatMassKg',
            'leanBodyMass' => 'leanMassKg',
            'muscleRate' => 'musclePercent',
            'muscleMass' => 'muscleMassKg',
            'subcutaneousFat' => 'subcutaneousFatPercent',
            'bodyMoisture' => 'bodyWaterPercent',
            'waterContent' => 'waterMassKg',
            'skeletalMuscleRate' => 'skeletalMusclePercent',
            'boneMass' => 'boneMassKg',
            'proportionOfProtein' => 'proteinPercent',
            'proteinAmount' => 'proteinMassKg',
            'basalMetabolicRate' => 'basalMetabolicRateKcal',
        ];

        $data = [];
        foreach ($fields as $source => $target) {
            $value = self::positive($payload[$source] ?? null);
            if ($value !== null) {
                $data[$target] = $value;
            }
        }

        // Enquanto mede, a trama repete-se com tudo a zero. Sem o IMC não há resultado.
        return isset($data['bmi']) ? ['body_composition', $data] : null;
    }

    /** @param array<string, mixed> $payload @return array{0: string, 1: array<string, int>}|null */
    private static function bloodPressureReading(array $payload): ?array
    {
        $high = self::withinRange($payload['bloodPressureHigh'] ?? null, 1, 300);
        $low = self::withinRange($payload['bloodPressureLow'] ?? null, 1, 300);

        return $high === null || $low === null
            ? null
            : ['blood_pressure', ['systolicMmHg' => (int)$high, 'diastolicMmHg' => (int)$low]];
    }

    /** O valor, ou `null` se não for número ou cair fora do intervalo plausível. */
    private static function withinRange(mixed $value, int $min, int $max): int|float|null
    {
        return (is_int($value) || is_float($value)) && $value >= $min && $value <= $max ? $value : null;
    }

    /** O valor, ou `null` se não for um número acima de zero -- o sentinela de «sem leitura». */
    private static function positive(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !is_string($value)) {
            return null;
        }

        return (float)$value > 0.0 ? (float)$value : null;
    }

    /**
     * Diz porque é que uma medição não produziu valor.
     *
     * É um acontecimento e não telemetria: não há nada a registar, há uma razão a mostrar.
     *
     * @param array<string, mixed> $device
     */
    private function reportMeasurementFailure(
        string $deviceKey,
        array $device,
        int $licenseId,
        string $company,
        string $reason,
    ): void {
        // Uma medição falhada não é um acontecimento por trama. O ECG manda dezenas seguidas
        // com a mesma queixa enquanto o dedo não está no elétrodo, e relatar cada uma
        // afogava o histórico do aparelho no aviso em vez de o mostrar.
        $key = $deviceKey . '|' . $reason;
        $now = time();
        if ($now - ($this->lastFailureAt[$key] ?? 0) < self::FAILURE_REPEAT_SECONDS) {
            return;
        }
        $this->lastFailureAt[$key] = $now;

        // O motivo vai em `error` e não em `command`: descreve porque é que a medição não
        // saiu, e não o comando que a pediu -- que aqui nem sequer se conhece.
        $event = RawPayload::event(
            $deviceKey,
            (string)($device['supplier'] ?? ''),
            (string)($device['model'] ?? ''),
            'device.measurement_failed',
            ['reason' => $reason],
            null,
            (string)($device['commercialName'] ?? ''),
        );

        $this->mqttBridge->publishEvent($deviceKey, $event, 'bracelet', $licenseId, $company);
        $this->dashboardStore?->append($deviceKey, 'events', $event + [
            'deviceType' => 'bracelet',
            'licenseId' => $licenseId,
        ]);
    }

    /** Tira da fila o comando que o gateway confirmou ter executado. */
    private function resolvePending(string $deviceKey, string $dedupeKey): void
    {
        if ($this->downlinks === null || $dedupeKey === '') {
            return;
        }

        foreach ($this->downlinks->pendingFor($deviceKey) as $downlink) {
            if ($downlink->dedupeKey !== $dedupeKey) {
                continue;
            }

            $this->downlinks->remove($downlink);
            // Confirmado é caso encerrado: a chave sai do travão de repetição para que a
            // ordem seguinte -- mandar vibrar outra vez, por exemplo -- saia na hora.
            unset($this->sentAt[$dedupeKey]);
            $operation = (string)(($downlink->command['command'] ?? null) ?? $downlink->bytes);
            if ($operation !== '') {
                $this->dashboardStore?->markLatestCommand($deviceKey, $operation, [
                    'status' => 'acked',
                    'ackedAt' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
            }

            Logger::channel('hub')->info("Veepoo downlink {$dedupeKey} confirmado por {$deviceKey}");
            return;
        }
    }

    /**
     * O canal de comandos vive ao lado do de entrada, no mesmo espaço fixo do gateway:
     * `.../gw/{mac}/raw` para o que sobe, `.../gw/{mac}/cmd` para o que desce.
     */
    private function commandTopicFor(string $gatewayKey): string
    {
        $base = preg_replace('#/\+/raw$#', '', trim($this->topicFilter, '/')) ?? '';

        return $base . '/' . $gatewayKey . '/cmd';
    }

    /**
     * O que identifica um bloco é tudo o que ele traz, e não só o `date`.
     *
     * O bloco do intervalo a decorrer chega incompleto e é preenchido na leitura seguinte.
     * Pela data sozinha, a primeira versão congelava-o e os minutos que faltavam nunca
     * chegavam a sair.
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
