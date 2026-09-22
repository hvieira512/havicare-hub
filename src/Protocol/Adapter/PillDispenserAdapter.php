<?php

namespace Hub\Protocol\Adapter;

/**
 * Descodificador binário do dispensador Zayata/ZoomCare M228 (série M2, device type 0x02).
 *
 * Trama: 0xAA · Length(2) · Status · Version · Serial(2) · Subserial · Subtotal · Flag ·
 * DeviceType · DeviceNumber(8) · PacketType · dados TFLV · CRC16(2). Os inteiros vão em
 * ordem do anfitrião — little-endian no x86 em que o hub corre. O CRC é o de MODBUS,
 * calculado de Length ao fim dos dados.
 */
class PillDispenserAdapter implements DeviceAdapterInterface
{
    private const HEADER = 0xAA;
    private const DEVICE_TYPE = 0x02;

    /** Offsets fixos da trama. */
    private const OFF_STATUS = 3;
    private const OFF_DEVICE_TYPE = 10;
    private const OFF_DEVICE_NUMBER = 11;
    private const OFF_PACKET_TYPE = 19;
    private const OFF_APP_DATA = 20;

    /** Bytes fixos entre o campo Length e os dados (Status..PacketType). */
    private const HEADER_BODY_BYTES = 17;
    private const MIN_FRAME_BYTES = 22;

    public function protocol(): string
    {
        return 'zayata-m228';
    }

    public function canDecode(string $raw): bool
    {
        $length = strlen($raw);
        if ($length < self::MIN_FRAME_BYTES || ord($raw[0]) !== self::HEADER) {
            return false;
        }

        $declared = unpack('v', substr($raw, 1, 2))[1];
        if ($length !== 3 + $declared + 2) {
            return false;
        }

        if (ord($raw[self::OFF_DEVICE_TYPE]) !== self::DEVICE_TYPE) {
            return false;
        }

        $region = substr($raw, 1, $length - 3);
        return self::crc16Modbus($region) === unpack('v', substr($raw, -2))[1];
    }

    public function decodeIncoming(string $raw, array $context = []): ?array
    {
        if (!$this->canDecode($raw)) {
            return null;
        }

        $length = unpack('v', substr($raw, 1, 2))[1];
        $serial = unpack('v', substr($raw, 5, 2))[1];
        $flag = ord($raw[9]);
        $deviceNumber = unpack('P', substr($raw, self::OFF_DEVICE_NUMBER, 8))[1];
        $packetType = ord($raw[self::OFF_PACKET_TYPE]);
        $appData = substr($raw, self::OFF_APP_DATA, $length - self::HEADER_BODY_BYTES);

        $identity = self::decodeDeviceNumber($deviceNumber);
        $type = self::packetTypeName($packetType);

        $encrypted = ($flag & 0x04) === 0x04;
        $plain = $encrypted ? self::decryptAppData($appData, $deviceNumber) : $appData;

        // A resposta à descoberta de parâmetros não é TFLV: é uma lista de TAGs coladas. Lê-la
        // como TFLV dava TAGs inventadas -- o `0xA002` aparecia como `0x02A0`.
        //
        // E uma lista que não se entenda também não vira TFLV. Ler esses bytes como TLV dava
        // telemetria inventada com identidade correcta e CRC válido, que é a falha calada que
        // este protocolo torna fácil; um `0x8B` de um firmware com famílias que não listamos
        // chegava para a provocar.
        $isDiscovery = self::isDiscoveryReply($packetType);
        $discovered = $isDiscovery ? self::parseTagList($plain ?? '') : null;
        $tlv = $plain === null || $isDiscovery ? [] : self::parseTlv($plain);

        return [
            'encrypted' => $encrypted,
            'decrypted' => !$encrypted || $plain !== null,
            'supportedTags' => $discovered,
            'type' => $type,
            'packetType' => $packetType,
            'imei' => $identity['id'],
            'idKind' => $identity['kind'],
            'ident' => (string)$serial,
            'ref' => 'pill:' . $type,
            'deviceNumber' => $deviceNumber,
            'status' => ord($raw[self::OFF_STATUS]),
            'flag' => $flag,
            // Bit 1 do Flag dispensa a resposta do servidor; o bit 0 é reservado e o bit 2
            // marca cifra AES128-CFB. A lógica de ACK vive na camada de protocolo, não aqui.
            'waivesReply' => ($flag & 0x02) === 0x02,
            'tlv' => $tlv,
            'data' => ['idKind' => $identity['kind'], 'tlv' => $tlv, 'supportedTags' => $discovered],
            'timestamp' => $this->now(),
        ];
    }

    public function encodeOutgoing(array $payload, array $context = []): string
    {
        $deviceNumber = isset($payload['deviceNumber'])
            ? (int)$payload['deviceNumber']
            : self::encodeDeviceNumber(
                (string)($payload['imei'] ?? $payload['mac'] ?? ''),
                isset($payload['imei']) ? 'imei' : 'mac',
            );

        $appData = isset($payload['appDataRaw'])
            ? (string)$payload['appDataRaw']
            : self::packTlv($payload['tlv'] ?? []);

        $body = pack('C', $payload['status'] ?? 0)
            . pack('C', $payload['version'] ?? 0x01)
            . pack('v', $payload['serial'] ?? 0)
            . pack('C', $payload['subserial'] ?? 0)
            . pack('C', $payload['subtotal'] ?? 1)
            . pack('C', $payload['flag'] ?? 0)
            . pack('C', $payload['deviceType'] ?? self::DEVICE_TYPE)
            . pack('P', $deviceNumber)
            . pack('C', $payload['packetType'] ?? 0x02)
            . $appData;

        $region = pack('v', strlen($body)) . $body;

        return pack('C', self::HEADER) . $region . pack('v', self::crc16Modbus($region));
    }

    /** CRC-16/MODBUS: polinómio 0xA001 reflectido, valor inicial 0xFFFF. */
    public static function crc16Modbus(string $data): int
    {
        $crc = 0xFFFF;
        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($data[$i]);
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 1) ? (($crc >> 1) ^ 0xA001) : ($crc >> 1);
            }
        }

        return $crc;
    }

    /**
     * O DeviceNumber de 64 bits codifica um MAC ou um IMEI. Bits 63-62: 00 MAC, 01 IMEI.
     * O IMEI vem em BCD 8421 nos 60 bits inferiores, dígito mais significativo primeiro.
     *
     * @return array{kind: string, id: string}
     */
    public static function decodeDeviceNumber(int $deviceNumber): array
    {
        $kind = ($deviceNumber >> 62) & 0x03;

        if ($kind === 0) {
            return ['kind' => 'mac', 'id' => sprintf('%012X', $deviceNumber & 0xFFFFFFFFFFFF)];
        }

        if ($kind === 1) {
            $value = $deviceNumber & ((1 << 60) - 1);
            $imei = '';
            for ($shift = 56; $shift >= 0; $shift -= 4) {
                $imei .= (string)(($value >> $shift) & 0xF);
            }

            return ['kind' => 'imei', 'id' => $imei];
        }

        return ['kind' => 'unknown', 'id' => ''];
    }

    public const T_INT8S = 1;
    public const T_INT8U = 2;
    public const T_INT16S = 3;
    public const T_INT16U = 4;
    public const T_INT32U = 6;
    public const T_STRING = 11;

    /**
     * O tipo declarado de cada TAG, da tabela «TAG Definition - Device Type 02».
     *
     * O tipo vai nos bits 0--4 do Flag de cada TFLV e não é decorativo: uma TAG que chegue ao
     * aparelho como `UNKONW` volta recusada com «tipo de parâmetro inválido», e o pedido
     * inteiro não produz nada. Daqui sai também o comprimento com que uma leitura pede o
     * valor, que antes vivia numa segunda lista à parte.
     *
     * @var array<int, list<int>>
     */
    private const TAGS_BY_TYPE = [
        self::T_INT8S => [
            0x810E,
        ],
        self::T_INT8U => [
            0x1001, 0x1002, 0x1003, 0x1005, 0x1006, 0x1008, 0x1009, 0x100A, 0x100B, 0x100C, 0x100D,
            0x100E, 0x1012, 0x1013, 0x1014, 0x1019, 0x101A, 0x101C, 0x101D, 0x1021, 0x1022, 0x1023,
            0x1024, 0x1025, 0x1026, 0x1027, 0x1028, 0x1029, 0x1031, 0x1032, 0x1033, 0x1034, 0x1035,
            0x1036, 0x1037, 0x1038, 0x1039, 0x1041, 0x1042, 0x1043, 0x1044, 0x1045, 0x1046, 0x1047,
            0x1048, 0x1049, 0x1051, 0x1052, 0x1053, 0x1054, 0x1055, 0x8005, 0x8007, 0x8008, 0x800A,
            0x8101, 0x8102, 0x8103, 0x8104, 0x8105, 0x8106, 0x8107, 0x8109, 0x810C, 0x810D, 0x810F,
            0x8111, 0x8112, 0x811A, 0x811B, 0x811D, 0x8121, 0x8122, 0x8123, 0x8124, 0x8125, 0x8131,
            0x8132, 0x8133, 0x8134, 0x8135, 0x8136, 0x8137, 0x8138, 0x8139, 0xA001, 0xA002, 0xA003,
            0xA004, 0xA102, 0xA103, 0xA123, 0xC001, 0xC201, 0xC204, 0xC205, 0xC206,
        ],
        self::T_INT16S => [
            0x1015, 0x810A, 0x810B,
        ],
        self::T_INT16U => [
            0x1004, 0x1007, 0x1063, 0x8002, 0x8003, 0x8004, 0x8006, 0x800B, 0xA011, 0xA012, 0xA023,
        ],
        self::T_INT32U => [
            0x1017, 0x1018, 0x8081, 0x8082,
        ],
        self::T_STRING => [
            0x8009, 0xA021, 0xA022, 0xA101, 0xC202, 0xC203,
        ],
    ];

    /** Quantos bytes ocupa o valor de cada tipo. O `STRING` não tem comprimento fixo. */
    private const TYPE_BYTES = [
        self::T_INT8S => 1,
        self::T_INT8U => 1,
        self::T_INT16S => 2,
        self::T_INT16U => 2,
        self::T_INT32U => 4,
        // Todas as TAGs de texto da especificação são de 20 bytes, e o aparelho recusa com
        // «comprimento» qualquer outra medida: a calibração do relógio saía com os 19 do
        // texto e nunca chegou a ser aplicada.
        self::T_STRING => 20,
    ];

    /**
     * O tipo com que uma TAG tem de ser enviada.
     *
     * Rebenta em vez de assumir: uma TAG por declarar chegava ao aparelho como `UNKONW` e era
     * recusada em silêncio do lado de cá, que é exactamente o defeito que isto existe para
     * não repetir.
     */
    public static function parameterType(int $tag): int
    {
        foreach (self::TAGS_BY_TYPE as $type => $tags) {
            if (in_array($tag, $tags, true)) {
                return $type;
            }
        }

        throw new \InvalidArgumentException(sprintf('TAG 0x%04X sem tipo declarado na especificação', $tag));
    }

    /** As TAGs de configuração que o hub sabe ler e escrever. */
    public const CONFIGURATION_TAGS = [
        0x1001, 0x1015,                                     // idioma e fuso
        0x1004, 0x1005, 0x1006, 0x1007, 0x1008, 0x1009, 0x100A, // período do plano
        0x100C, 0x100D,                                     // bloqueio de criança, toma antecipada
        0x1012, 0x1013,                                     // toque e volume
        0x1021, 0x1022, 0x1023, 0x1024, 0x1025, 0x1026, 0x1027, 0x1028, 0x1029, // horas
        0x1031, 0x1032, 0x1033, 0x1034, 0x1035, 0x1036, 0x1037, 0x1038, 0x1039, // minutos
        0x1041, 0x1042, 0x1043, 0x1044, 0x1045, 0x1046, 0x1047, 0x1048, 0x1049, // interruptores
        0x1051, 0x1052, 0x1053, 0x1054, 0x1055,             // não incomodar
    ];

    /** As TAGs de estado que o hub sabe ler. */
    public const STATUS_TAGS = [
        0x8101,                     // nível de medicação
        0x8102,                     // estado do bloqueio de criança, como o aparelho o vê
        0x8103, 0x8104,             // bateria
        0x810A, 0x810B,             // sinal WiFi e GSM em dBm, unidade que o fornecedor confirmou
        0x810D,                     // nível do sinal GSM: 0 a 3, e esse está documentado
        0x810E, 0x810F,             // temperatura e humidade
        0x8009,                     // CCID do cartão SIM, STRING de 20 bytes
        0x8107, 0x8109, 0x8111,     // tampa, alimentação DC, alarme de temperatura/humidade
        0x8112,                     // chamada de emergência
        0x811A, 0x811B, 0x811D,     // compartimentos
        // O estado de toma de cada um dos nove alarmes. É a única leitura da toma que chega
        // em claro: o evento `0x03` é mais rico e vem cifrado.
        0x8131, 0x8132, 0x8133, 0x8134, 0x8135, 0x8136, 0x8137, 0x8138, 0x8139,
        0x8121, 0x8122, 0x8123, 0x8124, 0x8125, // avarias
    ];

    /**
     * O corpo de um pedido de leitura: as TAGs pedidas, cada uma com o valor a zeros no
     * comprimento do seu tipo — é esse espaço que o aparelho preenche na resposta.
     *
     * @param list<int> $tags
     * @return array<int, array{value: string}>
     */
    public static function readRequestTlv(array $tags): array
    {
        $tlv = [];
        foreach ($tags as $tag) {
            $bytes = self::TYPE_BYTES[self::parameterType($tag)] ?? 1;
            $tlv[$tag] = ['value' => str_repeat("\x00", $bytes)];
        }

        return $tlv;
    }

    /**
     * A identidade tal como a whitelist a guarda, de volta ao inteiro de 64 bits. Quinze
     * dígitos são um IMEI; doze hexadecimais são um MAC.
     */
    public static function deviceNumberFor(string $id): int
    {
        $id = trim($id);

        return self::encodeDeviceNumber($id, preg_match('/^\d{15}$/', $id) === 1 ? 'imei' : 'mac');
    }

    public static function encodeDeviceNumber(string $id, string $kind): int
    {
        if ($kind === 'imei') {
            $value = 0;
            $digits = str_pad($id, 15, '0', STR_PAD_LEFT);
            for ($i = 0; $i < 15; $i++) {
                $value = ($value << 4) | (ord($digits[$i]) - 48);
            }

            return (1 << 62) | $value;
        }

        return hexdec($id) & 0xFFFFFFFFFFFF;
    }

    /**
     * Os dados são uma sequência de TFLV: Tag(2) · Flag(1) · Length(1) · Value(Length).
     * Sendo auto-descritivo, o resultado é um mapa de TAG para o valor cru e o seu estado.
     *
     * @return array<int, array{type: int, state: int, value: string}>
     */
    public static function parseTlv(string $data): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($data);

        while ($offset + 4 <= $length) {
            $tag = unpack('v', substr($data, $offset, 2))[1];
            $flag = ord($data[$offset + 2]);
            $valueLength = ord($data[$offset + 3]);
            $value = substr($data, $offset + 4, $valueLength);
            if (strlen($value) < $valueLength) {
                break;
            }

            $type = $flag & 0x1F;
            // O texto chega preenchido até aos 20 bytes; quem o lê quer a string e não o
            // enchimento colado ao fim.
            if ($type === self::T_STRING) {
                $value = rtrim($value, "\x00");
            }

            $out[$tag] = ['type' => $type, 'state' => ($flag >> 5) & 0x07, 'value' => $value];
            $offset += 4 + $valueLength;
        }

        return $out;
    }

    /** @param array<int, array{type?: int, state?: int, value?: string}> $tlv */
    public static function packTlv(array $tlv): string
    {
        $out = '';
        foreach ($tlv as $tag => $entry) {
            $value = (string)($entry['value'] ?? '');
            $type = $entry['type'] ?? self::parameterType($tag);
            // O texto vai até ao comprimento que a especificação declara. O aparelho compara
            // o campo Length com o que espera da TAG, e recusa com «comprimento» se diferir.
            if ($type === self::T_STRING) {
                $value = substr(str_pad($value, self::TYPE_BYTES[self::T_STRING], "\x00"), 0, self::TYPE_BYTES[self::T_STRING]);
            }
            $flag = ($type & 0x1F) | ((($entry['state'] ?? 0) & 0x07) << 5);
            $out .= pack('v', $tag) . pack('C', $flag) . pack('C', strlen($value)) . $value;
        }

        return $out;
    }

    /**
     * O corpo de uma trama que o aparelho cifrou, ou `null` se não abrir.
     *
     * O M228 cifra em AES128-CFB tudo o que envia por iniciativa própria — o heartbeat, as
     * notificações, e o evento de toma de medicação, que é a funcionalidade central. As
     * respostas aos nossos pedidos vêm em claro, e é por isso que a configuração sempre
     * funcionou enquanto a telemetria não chegava.
     *
     * A chave e o IV são a mesma coisa: o Device Number escrito como string hexadecimal de
     * dezasseis caracteres, que é exactamente o comprimento de uma chave AES-128. O
     * fornecedor descreveu-o como «both the key and the random IV are based on the device's
     * Device Number», e a leitura confirmou-se contra tramas reais.
     *
     * Tentam-se as duas caixas. Um Device Number que codifica um IMEI é só dígitos e a caixa
     * não se nota; um que codifique um MAC leva letras, e não há aqui nenhum aparelho desses
     * para decidir qual delas o firmware usa.
     */
    private static function decryptAppData(string $appData, int $deviceNumber): ?string
    {
        if ($appData === '') {
            return $appData;
        }

        $hex = sprintf('%016X', $deviceNumber);
        foreach ([$hex, strtolower($hex)] as $key) {
            $plain = openssl_decrypt(
                $appData,
                'aes-128-cfb',
                $key,
                OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
                $key,
            );
            if (is_string($plain) && self::closesAsTlv($plain)) {
                return $plain;
            }
        }

        return null;
    }

    /**
     * Se um corpo decifrado é mesmo TFLV.
     *
     * Sem esta verificação, uma decifra falhada devolvia ruído que o `parseTlv` lia como
     * TAGs inventadas, e o hub publicava telemetria fabricada com identidade correcta e CRC
     * válido — a pior falha calada que este protocolo permite.
     */
    private static function closesAsTlv(string $body): bool
    {
        $offset = 0;
        $length = strlen($body);

        while ($offset + 4 <= $length) {
            $tag = unpack('v', substr($body, $offset, 2))[1];
            if (!in_array($tag >> 8, self::TAG_FAMILIES, true)) {
                return false;
            }
            $valueLength = ord($body[$offset + 3]);
            if ($valueLength === 0) {
                return false;
            }
            $offset += 4 + $valueLength;
        }

        return $offset === $length && $length > 0;
    }

    /** Os bytes altos das TAGs que a especificação declara. */
    private const TAG_FAMILIES = [0x10, 0x80, 0x81, 0xA0, 0xA1, 0xC2];

    /**
     * Só as três que o hub sabe nomear. O `0x8D` estava aqui dentro e fazia fechar como
     * aceite qualquer operação pendente, enquanto o descodificador o via como `unknown` e não
     * publicava nada.
     */
    private static function isDiscoveryReply(int $packetType): bool
    {
        return $packetType >= 0x8A && $packetType <= 0x8C;
    }

    /**
     * A lista de TAGs de uma resposta à descoberta, na ordem do anfitrião.
     *
     * Um corpo vazio é uma lista vazia e não uma falha: é o que responde um firmware que não
     * serve nenhum parâmetro daquela família, e o pedido tem de fechar na mesma. Devolver
     * `null` aqui deixava-o para sempre à espera, a repetir-se de minuto a minuto.
     *
     * @return list<int>|null
     */
    private static function parseTagList(string $body): ?array
    {
        if ($body === '') {
            return [];
        }
        if (strlen($body) % 2 !== 0) {
            return null;
        }

        $tags = [];
        foreach (str_split($body, 2) as $pair) {
            $tag = unpack('v', $pair)[1];
            if (!in_array($tag >> 8, self::TAG_FAMILIES, true)) {
                return null;
            }
            $tags[] = $tag;
        }

        return $tags;
    }

    private static function packetTypeName(int $packetType): string
    {
        return match ($packetType) {
            // Do aparelho para o hub.
            0x01 => 'register',
            0x02 => 'heartbeat',
            0x03 => 'event',
            0x04 => 'change',
            0x81 => 'register_ack',
            0x82 => 'heartbeat_ack',
            0x83 => 'event_ack',
            0x84 => 'change_ack',
            // Do hub para o aparelho. Saem nos metadados do comando em fila, e por isso
            // precisam de nome tanto como os de subida.
            0x05 => 'read_config',
            0x06 => 'write_config',
            0x07 => 'read_status',
            0x08 => 'control',
            0x85 => 'read_config_ack',
            0x86 => 'write_config_ack',
            0x87 => 'read_status_ack',
            0x88 => 'control_ack',
            // A descoberta de parâmetros: perguntar ao aparelho que TAGs ele serve.
            0x0A => 'discover_config',
            0x0B => 'discover_status',
            0x0C => 'discover_control',
            0x8A => 'discover_config_ack',
            0x8B => 'discover_status_ack',
            0x8C => 'discover_control_ack',
            default => 'unknown',
        };
    }

    protected function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }
}
