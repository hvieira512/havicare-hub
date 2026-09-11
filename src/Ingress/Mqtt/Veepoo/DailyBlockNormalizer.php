<?php

declare(strict_types=1);

namespace Hub\Ingress\Mqtt\Veepoo;

/**
 * Traz um bloco diário da pulseira Veepoo para as formas genéricas do hub.
 *
 * A pulseira agrupa cinco minutos num só bloco e devolve, para cada grandeza, cinco leituras
 * — uma por minuto. O carimbo do bloco é o do primeiro minuto, por isso cada leitura é
 * datada somando o seu índice.
 *
 * O firmware usa dois sentinelas para «não medido»: 0xFF nas grandezas de um byte e 0 nas
 * que nunca valem zero num ser vivo. Publicar qualquer um deles seria inventar uma medição.
 *
 * O sono fica de fora, apesar de o bloco trazer um campo com esse nome. A documentação
 * promete seis estados; num histórico de 733 blocos o firmware só devolveu 0, 112, 136, 144,
 * 200 e 208, com a mesma distribuição de manhã e de tarde. Nesse histórico não há um único
 * bloco noturno com batimentos -- a pulseira nunca esteve ao pulso a dormir --, e sem isso
 * não há como saber o que os códigos dizem. Quando houver uma noite medida, resolve-se aqui.
 */
final class DailyBlockNormalizer
{
    private const NO_DATA = 255;

    /**
     * Fator de conversão da glicemia: 1 mmol/L equivale a 18,016 mg/dL.
     *
     * Público porque a medição ao vivo chega pelo `Bridge` e não por aqui, e as duas têm de
     * converter da mesma maneira -- uma glicemia do histórico e uma pedida agora não podem
     * sair em unidades diferentes.
     */
    public const MMOL_PER_L_TO_MG_PER_DL = 18.016;

    /**
     * De quantos em quantos segundos o bloco guarda um intervalo R-R.
     *
     * São cinquenta lugares a cobrir os trezentos segundos do bloco, e o modo de teste do
     * fabricante chama `RR2Per6Second` ao campo equivalente.
     */
    private const RR_SLOT_SECONDS = 6;

    /** Quanto tempo cobre um bloco. */
    private const BLOCK_SECONDS = 300;

    /**
     * O código de uso que significa «detecção passou».
     *
     * O javadoc chama ao campo «bits de bandeira de uso» e não publica a tabela. Em capturas
     * de dois dias apareceram quatro valores: `0` sempre que há leitura ótica, e `1`, `2` e
     * `6` sempre que não há nenhuma -- a pulseira a ser calçada, pousada na secretária, e a
     * noite inteira fora do pulso. A regra é a bandeira a zero, não cada código de per si.
     */
    private const WEAR_OK = 0;

    /** Grandezas por minuto: chave no bloco => [type do hub, campo em data]. */
    private const PER_MINUTE = [
        'pulseReat' => ['heart_rate', 'bpm'],
        'heartReat' => ['heart_rate', 'bpm'],
        'respirationRate' => ['breath_rate', 'breathsPerMinute'],
        'HRVData' => ['hrv', 'milliseconds'],
    ];

    /**
     * @param array<string, mixed> $block   um elemento de `payload` do gateway
     * @param array<string, mixed> $device  identidade já resolvida pelo hub
     * @return list<array<string, mixed>>   envelopes de telemetria, prontos a publicar
     */
    public function normalize(array $block, array $device, string $gatewayId, int $tzOffsetMinutes = 0): array
    {
        $start = self::parseDate((string)($block['date'] ?? ''), $tzOffsetMinutes);
        if ($start === null) {
            return [];
        }

        // O envelope é sempre o mesmo menos o tipo, o instante e os dados; montá-lo aqui uma
        // vez deixa o resto do método a falar só das grandezas.
        $at = static fn(int $offsetMinutes): string => gmdate('Y-m-d\TH:i:s\Z', $start + ($offsetMinutes * 60));
        $envelope = static fn(string $type, int $offsetMinutes, array $data): array => [
            'type' => $type,
            'occurredAt' => $at($offsetMinutes),
            'device' => $device,
            'source' => [
                'protocol' => 'veepoo-ble',
                'nativeType' => 'daily_block',
                'gatewayId' => $gatewayId,
            ],
            'data' => $data,
        ];

        $out = [];
        foreach (self::PER_MINUTE as $key => [$type, $field]) {
            foreach (self::readings($block[$key] ?? null) as $minute => $value) {
                $out[] = $envelope($type, $minute, [$field => $value]);
            }
        }

        $pressure = $this->bloodPressure($block['bloodPressure'] ?? null);
        if ($pressure !== null) {
            $out[] = $envelope('blood_pressure', 0, $pressure);
        }

        $intervals = $this->rrIntervals($block['rr50'] ?? null, $start);
        if ($intervals !== []) {
            // A cadência sai dos instantes de cada intervalo; um campo à parte a repeti-la
            // era mais uma coisa a poder discordar de si mesma.
            $out[] = $envelope('rr_interval', 0, ['intervals' => $intervals]);
        }

        // O oxigénio vem num objeto com as leituras e os derivados de apneia; só as leituras
        // têm tipo no hub. Os cinco valores são por minuto, como as restantes grandezas.
        $oxygen = $block['bloodOxygen'] ?? null;
        if (is_array($oxygen)) {
            foreach (self::readings($oxygen['oxygens'] ?? null) as $minute => $value) {
                $out[] = $envelope('blood_oxygen', $minute, ['spo2Percent' => $value]);
            }
        }

        // Derivados de apneia e carga cardíaca vêm no mesmo objeto do oxigénio. São contagens
        // acumuladas do bloco e não leituras por minuto, por isso levam o carimbo do bloco.
        if (is_array($oxygen)) {
            $counters = [
                'sleep_apnea' => ['apneaResults' => 'episodes', 'hypoxiaTimes' => 'hypoxiaSeconds'],
                'cardiac_load' => ['cardiacLoads' => 'value'],
            ];
            foreach ($counters as $type => $fields) {
                $data = [];
                foreach ($fields as $source_ => $target) {
                    $sum = self::sum($oxygen[$source_] ?? null);
                    if ($sum !== null) {
                        $data[$target] = $sum;
                    }
                }
                if ($data !== []) {
                    $out[] = $envelope($type, 0, $data);
                }
            }
        }

        // O stress vem como inteiro e o MET com uma casa decimal implícita, tal como os R-R.
        // A app do fabricante mostra 0,9 MET onde o bloco traz 9, e nove equivalentes
        // metabólicos seriam corrida a bom ritmo -- não alguém sentado a uma secretária.
        $scored = [
            'stress' => ['pressure', 'score', 1],
            'met' => ['meiTuo', 'value', 10],
        ];
        foreach ($scored as $type => [$key, $field, $divisor]) {
            foreach (self::readings($block[$key] ?? null) as $minute => $value) {
                $out[] = $envelope($type, $minute, [
                    $field => $divisor === 1 ? $value : round($value / $divisor, 1),
                ]);
            }
        }

        $lipids = $this->bloodLipids($block['bloodLiquid'] ?? null);
        foreach ($lipids as $type => $data) {
            $out[] = $envelope($type, 0, $data);
        }

        $glucose = $this->bloodGlucose($block['bloodGlucose'] ?? null);
        if ($glucose !== null) {
            $out[] = $envelope('blood_sugar', 0, $glucose);
        }

        $temperature = $this->temperature($block['bodyTemperature'] ?? null);
        if ($temperature !== null) {
            $out[] = $envelope('temperature', 0, $temperature);
        }

        $steps = $this->steps($block['step'] ?? null);
        if ($steps !== null) {
            $out[] = $envelope('steps', 0, $steps);
        }

        $wear = $this->wearState($block['step'] ?? null);
        if ($wear !== null) {
            $out[] = $envelope('wear_state', 0, $wear);
        }

        return $out;
    }

    /**
     * Descarta os sentinelas e devolve só os minutos com leitura, indexados pelo minuto.
     *
     * @return array<int, int>
     */
    private static function readings(mixed $values): array
    {
        if (!is_array($values)) {
            return [];
        }

        $out = [];
        foreach (array_values($values) as $minute => $value) {
            if (is_int($value) && $value > 0 && $value !== self::NO_DATA) {
                $out[$minute] = $value;
            }
        }

        return $out;
    }

    /**
     * Os cinquenta intervalos R-R do bloco, na forma que o hub já usa para esta grandeza.
     *
     * O firmware conta-os em unidades de dez milissegundos e não em milissegundos: no bloco
     * das 09:00 a média das leituras é 73,75, que a dez milissegundos dá 738 ms -- os 81 bpm
     * que o mesmo bloco reporta como frequência cardíaca. Publicá-los em cru daria intervalos
     * de oitenta milissegundos, ou seja setecentos batimentos por minuto.
     *
     * Vão sem instante próprio de propósito. São uma amostra de trinta e sete segundos dentro
     * de um bloco de cinco minutos, e a posição na lista não diz onde: reconstruir carimbos
     * somando-os afirmaria que foram medidos em fila logo no início do bloco.
     *
     * @return list<array{milliseconds: int}>
     */
    private function rrIntervals(mixed $values, int $start): array
    {
        $out = [];
        foreach (self::readings($values) as $slot => $value) {
            $out[] = [
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z', $start + ($slot * self::RR_SLOT_SECONDS)),
                'milliseconds' => $value * 10,
            ];
        }

        return $out;
    }

    /**
     * Soma as leituras de um contador do bloco, ignorando os sentinelas.
     *
     * Devolve `null` quando não houve leitura nenhuma -- distinto de zero episódios, que é uma
     * afirmação e não uma ausência.
     */
    private static function sum(mixed $values): ?int
    {
        if (!is_array($values)) {
            return null;
        }

        $any = false;
        $total = 0;
        foreach ($values as $value) {
            if (is_int($value) && $value !== self::NO_DATA) {
                $any = true;
                $total += $value;
            }
        }

        return $any ? $total : null;
    }

    /**
     * Lípidos e ácido úrico, separados em duas capacidades.
     *
     * Vêm juntos numa trama só, mas o ácido úrico não é um lípido e tem unidade própria --
     * agrupá-los obrigaria quem consome a saber que `blood_lipids` traz algo que não o é.
     *
     * @return array<string, array<string, float>>
     */
    private function bloodLipids(mixed $liquid): array
    {
        if (!is_array($liquid)) {
            return [];
        }

        $lipids = array_filter([
            'totalCholesterolMmolPerL' => self::decimal($liquid['cholesterol'] ?? null),
            'triglyceridesMmolPerL' => self::decimal($liquid['triacylglycerol'] ?? null),
            'hdlMmolPerL' => self::decimal($liquid['highDensity'] ?? null),
            'ldlMmolPerL' => self::decimal($liquid['lowDensity'] ?? null),
        ], static fn(?float $v): bool => $v !== null);

        $uric = self::decimal($liquid['uricAcidVal'] ?? null);

        $out = [];
        if ($lipids !== []) {
            $out['blood_lipids'] = $lipids;
        }
        if ($uric !== null) {
            $out['uric_acid'] = ['umolPerL' => $uric];
        }

        return $out;
    }

    /** Aceita texto ou número; zero é ausência de leitura. */
    private static function decimal(mixed $value): ?float
    {
        if (!is_string($value) && !is_float($value) && !is_int($value)) {
            return null;
        }

        $number = (float)$value;

        return $number > 0.0 ? round($number, 2) : null;
    }

    /**
     * Glicemia do bloco, com o nível de risco que o firmware lhe atribui.
     *
     * O firmware usa duas formas para o mesmo campo: um objeto com valor e nível quando os
     * declara, e um número solto quando só tem o valor -- é assim que o MF91 o envia. Aceitar
     * só o objeto deixava a glicemia calada para sempre, sem erro nenhum.
     *
     * O nível vem como 1, 2 ou 3 e traduz-se para as enumerações inglesas do hub. Um valor a
     * zero é ausência de leitura, não uma glicemia de zero.
     *
     * O hub publica glicemia em mg/dL -- é o nome do campo e portanto o contrato. A pulseira
     * reporta em mmol/L, e por isso converte-se aqui: publicar o valor original sob um nome
     * que diz mg/dL seria dar o número certo com a unidade errada.
     *
     * @return array{glucoseMgDl: float, riskLevel?: string}|null
     */
    private function bloodGlucose(mixed $glucose): ?array
    {
        if (!is_array($glucose)) {
            $glucose = ['bloodGlucose' => $glucose];
        }

        $value = $glucose['bloodGlucose'] ?? null;
        if ((!is_float($value) && !is_int($value) && !is_string($value)) || (float)$value <= 0.0) {
            return null;
        }

        $risk = match ($glucose['level'] ?? null) {
            1 => 'low',
            2 => 'medium',
            3 => 'high',
            default => null,
        };

        $data = ['glucoseMgDl' => round((float)$value * self::MMOL_PER_L_TO_MG_PER_DL, 1)];

        return $risk === null ? $data : $data + ['riskLevel' => $risk];
    }

    /**
     * Temperatura do bloco: corporal e de superfície, como o firmware as rotula.
     *
     * Vem em texto decimal e o sentinela de «não medido» é `0.0` em ambas. Os nomes são os
     * dos relógios -- o contrato já tinha `bodyCelsius` e `surfaceCelsius`, e a pulseira não
     * tem razão para inventar outros.
     *
     * @return array{bodyCelsius?: float, surfaceCelsius?: float}|null
     */
    private function temperature(mixed $temperature): ?array
    {
        if (!is_array($temperature)) {
            return null;
        }

        $data = array_filter([
            'bodyCelsius' => self::celsius($temperature['bodyTemperature'] ?? null),
            'surfaceCelsius' => self::celsius($temperature['bodySurfaceTemperature'] ?? null),
        ], static fn(?float $v): bool => $v !== null);

        return $data === [] ? null : $data;
    }

    /** Aceita texto ou número, e trata `0.0` como ausência de leitura. */
    private static function celsius(mixed $value): ?float
    {
        if (!is_string($value) && !is_float($value) && !is_int($value)) {
            return null;
        }

        $celsius = (float)$value;

        return $celsius > 0.0 ? round($celsius, 1) : null;
    }

    /** @return array{systolicMmHg: int, diastolicMmHg: int}|null */
    private function bloodPressure(mixed $pressure): ?array
    {
        if (!is_array($pressure)) {
            return null;
        }

        $high = $pressure['bloodPressureHigh'] ?? 0;
        $low = $pressure['bloodPressureLow'] ?? 0;
        if (!is_int($high) || !is_int($low) || $high <= 0 || $low <= 0 || $high === self::NO_DATA) {
            return null;
        }

        return ['systolicMmHg' => $high, 'diastolicMmHg' => $low];
    }

    /**
     * Quantos passos naquela janela de cinco minutos.
     *
     * Zero passos é uma leitura verdadeira, ao contrário de zero batimentos; o que se
     * descarta é o bloco que não traz sequer a contagem.
     *
     * A distância e as calorias do bloco ficam de fora porque não são medições: em
     * quatrocentos e dezasseis blocos capturados são sempre os passos vezes uma constante --
     * 0,86 m e 0,067 kcal por passo -- e zero sempre que os passos são zero. O acumulado do
     * dia, esse, é contado pela pulseira e chega pelo `Bridge` como `activity`.
     *
     * A `amountOfExercise` também fica: é um contador do acelerómetro sem unidade nem escala
     * documentada, que a app do fabricante não mostra em lado nenhum. O movimento com unidade
     * é o `met`, que cobre vinte e um dos vinte e cinco blocos em que ela é diferente de zero
     * e nunca aparece sem ela.
     *
     * @return array{count: int, periodSeconds: int}|null
     */
    private function steps(mixed $step): ?array
    {
        if (!is_array($step) || !is_int($step['stepCount'] ?? null)) {
            return null;
        }

        return ['count' => $step['stepCount'], 'periodSeconds' => self::BLOCK_SECONDS];
    }

    /**
     * Se a pulseira estava a ser usada durante o bloco.
     *
     * Sai em cada bloco, mesmo quando é igual ao anterior. Colapsar repetições parece
     * poupança e é decisão de quem integra: o hub entrega o que o aparelho mediu naquela
     * janela, e quem consome compara com o que leu da vez anterior se lhe interessar.
     *
     * @return array{state: string}|null
     */
    private function wearState(mixed $step): ?array
    {
        if (!is_array($step) || !is_int($step['wear'] ?? null)) {
            return null;
        }

        return ['state' => $step['wear'] === self::WEAR_OK ? 'worn' : 'not_worn'];
    }

    /**
     * O firmware data os blocos como `YYYY-MM-DD-HH-mm`, e sem fuso.
     *
     * O relógio da pulseira é acertado pelo gateway com a hora local dele, portanto é nessa
     * que os blocos vêm. O desvio chega na mensagem e é subtraído aqui: lê-los como UTC dava
     * um histórico deslocado -- em Portugal, no verão, uma hora no futuro.
     */
    private static function parseDate(string $date, int $tzOffsetMinutes): ?int
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})-(\d{2})-(\d{2})$/', $date, $m) !== 1) {
            return null;
        }

        $local = gmmktime((int)$m[4], (int)$m[5], 0, (int)$m[2], (int)$m[3], (int)$m[1]);

        return $local === false ? null : $local - ($tzOffsetMinutes * 60);
    }
}
