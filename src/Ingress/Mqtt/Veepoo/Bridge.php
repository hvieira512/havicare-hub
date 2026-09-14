<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

use Hub\Dashboard\DashboardStoreContract;
use Hub\Device\HubMqttBridge;
use Hub\Device\PendingDownlinkQueue;
use Hub\Device\TelemetryEnvelope;
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
final class Bridge extends \Hub\Ingress\Mqtt\Bridge implements \Hub\Ingress\Mqtt\DispatchesQueued
{
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

    /** O nome deste protocolo no contrato, que vai no `source` de tudo o que sai daqui. */
    private const PROTOCOL = 'veepoo-ble';

    /**
     * Quanto tempo um bloco fica reconhecido como já publicado.
     *
     * Tem de exceder a janela que o gateway consegue reproduzir: o `RETENTION_DAYS` dele, três
     * dias por omissão, mais o dia corrente. Cinco dias dá folga sem a memória pesar -- são
     * 288 blocos por dia e por pulseira, e cada um é uma chave curta com prazo.
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

    private readonly DailyBlockNormalizer $normalizer;

    private readonly DownlinkDispatcher $downlinkDispatcher;

    private readonly BraceletPresence $presence;

    private readonly MeasurementFailureReporter $failures;

    public function __construct(
        MqttClient $subscriber,
        Whitelist $whitelist,
        HubMqttBridge $mqttBridge,
        private readonly GatewayDeviceLinkLookup $links,
        ?PendingDownlinkQueue $downlinks,
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
        $this->downlinkDispatcher = new DownlinkDispatcher(
            $downlinks,
            $mqttBridge,
            $dashboardStore,
            $topicFilter,
        );
        $this->presence = new BraceletPresence($mqttBridge, $dashboardStore);
        $this->failures = new MeasurementFailureReporter($mqttBridge, $dashboardStore);
    }

    /**
     * Entrega o que esteja em fila às pulseiras com sessão aberta.
     *
     * O gateway fica subscrito ao tópico de comandos enquanto correr, e por isso a pulseira é
     * alcançável entre sessões. Chamado por um temporizador do `IngressRunner`: sem isto uma
     * ordem dada no ecrã esperava pelo anúncio de sessão seguinte -- até 30 s, mais do que a
     * pulseira leva a desistir de vibrar.
     */
    public function dispatchQueued(): void
    {
        foreach ($this->sessionGateway as $deviceKey => $gatewayKey) {
            $this->downlinkDispatcher->dispatchPending($deviceKey, $gatewayKey);
        }
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
                $this->presence->markOffline($deviceKey, $device, $licenseId, $company);
                return;
            }

            $this->presence->markOnline($deviceKey, $device, $licenseId, $company);
            $this->publishFirmware(
                (string)($message['device']['firmware'] ?? ''),
                $deviceKey,
                (string)$gateway['imei'],
                $device,
                $licenseId,
                $company,
            );
            $this->sessionGateway[$deviceKey] = (string)$gateway['imei'];
            $this->downlinkDispatcher->dispatchPending($deviceKey, (string)$gateway['imei']);
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
            $this->downlinkDispatcher->resolvePending($deviceKey, (string)($message['payload']['dedupeKey'] ?? ''));
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

        $this->emitTelemetry($deviceKey, TelemetryEnvelope::for(
            'battery',
            $deviceKey,
            $device,
            self::PROTOCOL,
            'battery',
            array_filter([
                'percent' => $percent,
                'lowBattery' => ($payload['VPDeviceElectricTypeIsLowVoltage'] ?? null) === 'lowVoltage' ? true : null,
            ], static fn(mixed $v): bool => $v !== null),
            $gatewayKey,
        ), $licenseId, $company);
    }

    /**
     * A versão de firmware, tal como a sessão a traz.
     *
     * Sai em cada sessão, mesmo repetida. Guardar a anterior para só publicar a mudança era
     * o hub a decidir o que vale a pena dizer -- e essa é a comparação de quem integra, que
     * tem o valor que leu da vez passada.
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
        if ($firmware === '') {
            return;
        }

        $this->emitTelemetry($deviceKey, TelemetryEnvelope::for(
            'firmware_version',
            $deviceKey,
            $device,
            self::PROTOCOL,
            'session',
            ['version' => $firmware],
            $gatewayKey,
        ), $licenseId, $company);
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
            $this->failures->report($deviceKey, $device, $licenseId, $company, self::DETECTION_FAILURES[$detection]);
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
            $this->failures->report($deviceKey, $device, $licenseId, $company, 'not_worn');
            return;
        }

        $sdkType = (int)($payload['sdkType'] ?? 0);
        $measurement = MeasurementNormalizer::forSdkType($sdkType, $payload);
        if ($measurement === null) {
            // Um tipo do SDK que ninguém reclama sai daqui tão calado como saía um `kind`, e
            // pela mesma razão se diz uma vez por espécie e por aparelho.
            $seenKey = $deviceKey . '|sdk:' . $sdkType;
            if ($sdkType !== 0 && !isset($this->unhandledKinds[$seenKey])) {
                $this->unhandledKinds[$seenKey] = true;
                Logger::channel('hub')->warning(
                    "Veepoo tipo do SDK sem normalização: {$sdkType} de {$deviceKey}"
                );
            }

            return;
        }

        [$type, $data] = $measurement;

        $this->emitTelemetry($deviceKey, TelemetryEnvelope::for(
            $type,
            $deviceKey,
            $device,
            self::PROTOCOL,
            // O tipo do fabricante e não a espécie de mensagem: `measurement` cobria nove
            // tipos e não dizia qual, e é por ele que se vai à documentação da Veepoo.
            "type-{$sdkType}",
            $data,
            $gatewayKey,
        ), $licenseId, $company);
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
            $this->failures->report($deviceKey, $device, $licenseId, $company, 'no_signal');
            return;
        }

        // `frequencyHz` é o nome do contrato, o mesmo que os relógios usam para a onda deles.
        $frequencyHz = is_int($payload['samplingHz'] ?? null) ? $payload['samplingHz'] : null;

        $this->emitTelemetry($deviceKey, TelemetryEnvelope::for(
            'ecg',
            $deviceKey,
            $device,
            self::PROTOCOL,
            'ecg_wave',
            array_filter(
                ['samples' => $samples, 'frequencyHz' => $frequencyHz],
                static fn(mixed $v): bool => $v !== null,
            ),
            $gatewayKey,
        ), $licenseId, $company);
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
