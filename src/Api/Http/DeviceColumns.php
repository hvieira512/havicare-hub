<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use Hub\Api\Repository\WhitelistRepository;
use Hub\Api\Request\DeviceWriteRequest;
use ReflectionClass;

/**
 * O que a listagem de dispositivos deixa ordenar, filtrar e editar, dito à máquina que a
 * consome em vez de escrito de novo em cada cliente.
 *
 * Nada aqui é uma lista à mão: o que se ordena sai da `SORTABLE_COLUMNS`, o que se edita sai
 * dos campos que o `DeviceWriteRequest` aceita, e as opções de escolha saem das contagens que
 * a própria listagem já apurou. Uma capacidade nova aparece no descritor por existir, e não
 * por alguém se lembrar de a acrescentar aqui.
 *
 * Não leva etiquetas. O descritor descreve estrutura, e traduzir é trabalho de quem desenha a
 * interface -- a mesma regra que vale para os valores publicados em MQTT.
 */
final class DeviceColumns
{
    /** As colunas que se filtram por texto livre, e o parâmetro de cada uma. */
    public const TEXT_FILTERS = [
        'imei' => 'w.imei',
        'simNumber' => 'w.sim_number',
        'deviceId' => 'w.device_id',
    ];

    /** As que se filtram por escolha, e o parâmetro que o servidor já aceita. */
    private const SELECT_FILTERS = [
        'deviceType' => 'deviceType',
        'supplier' => 'supplier',
        'model' => 'model',
        'company' => 'company',
        'licenseId' => 'licenseId',
    ];

    /** O estado de ligação não é coluna da tabela: vem do Redis e escolhe-se um só. */
    private const RUNTIME_COLUMNS = ['online' => ['online', 'offline']];

    /**
     * @param array<string, list<string>> $available Valores possíveis, por coluna.
     * @param array<string, list<array{value: string, count?: int}>> $counts As mesmas com contagem.
     * @return list<array<string, mixed>>
     */
    public static function describe(array $available = [], array $counts = []): array
    {
        $sortable = array_keys(WhitelistRepository::SORTABLE_COLUMNS);
        $editable = self::writableFields();

        $fields = array_values(array_unique(array_merge(
            $sortable,
            $editable,
            array_keys(self::TEXT_FILTERS),
            array_keys(self::SELECT_FILTERS),
            array_keys(self::RUNTIME_COLUMNS),
        )));

        $columns = [];
        foreach ($fields as $field) {
            $columns[] = [
                'field' => $field,
                'sortable' => in_array($field, $sortable, true),
                'editable' => in_array($field, $editable, true),
                'filter' => self::filterFor($field, $available, $counts),
            ];
        }

        return $columns;
    }

    /** @return array<string, mixed>|null */
    private static function filterFor(string $field, array $available, array $counts): ?array
    {
        if (isset(self::TEXT_FILTERS[$field])) {
            return ['type' => 'text', 'param' => $field];
        }

        if (isset(self::RUNTIME_COLUMNS[$field])) {
            return [
                'type' => 'select',
                'param' => $field,
                'multiple' => false,
                'options' => self::options(self::RUNTIME_COLUMNS[$field], []),
            ];
        }

        if (!isset(self::SELECT_FILTERS[$field])) {
            return null;
        }

        return [
            'type' => 'select',
            'param' => self::SELECT_FILTERS[$field],
            'multiple' => true,
            'options' => self::options($available[$field] ?? [], $counts[$field] ?? []),
        ];
    }

    /**
     * As contagens mandam na ordem quando existem, porque já vêm da consulta mais frequente
     * primeiro. Um valor sem contagem sai na mesma com `null`, para o dropdown não o perder.
     *
     * @param list<string> $values
     * @param list<array{value?: string, count?: int}> $counted
     * @return list<array{value: string, count: int|null}>
     */
    private static function options(array $values, array $counted): array
    {
        if ($counted !== []) {
            return array_values(array_map(
                static fn(array $option): array => [
                    'value' => (string)($option['value'] ?? ''),
                    'count' => isset($option['count']) ? (int)$option['count'] : null,
                ],
                $counted,
            ));
        }

        return array_values(array_map(
            static fn(string $value): array => ['value' => $value, 'count' => null],
            $values,
        ));
    }

    /** @return list<string> */
    private static function writableFields(): array
    {
        $constructor = (new ReflectionClass(DeviceWriteRequest::class))->getConstructor();

        return array_map(
            static fn(\ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor?->getParameters() ?? [],
        );
    }
}
