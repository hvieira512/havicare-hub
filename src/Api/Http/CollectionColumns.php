<?php

declare(strict_types=1);

namespace Hub\Api\Http;

use ReflectionClass;
use ReflectionParameter;

/**
 * O que uma listagem deixa ordenar, filtrar e editar, dito à máquina que a consome em vez
 * de escrito de novo em cada cliente.
 *
 * Cada listagem declara-o uma vez, e nada aqui é uma lista à mão: o que se ordena vem do
 * mapa que o repositório usa no `ORDER BY`, o que se edita vem dos campos que o pedido de
 * escrita aceita, e as opções de escolha vêm das contagens que a própria listagem apurou.
 * Uma capacidade nova aparece no descritor por existir, e não por alguém se lembrar dela.
 *
 * Não leva etiquetas. O descritor descreve estrutura, e traduzir é trabalho de quem desenha
 * a interface -- a mesma regra que vale para os valores publicados em MQTT.
 */
final class CollectionColumns
{
    /**
     * @param array<string, string> $sortable As colunas por que a listagem se deixa ordenar.
     * @param class-string|null $writable O pedido de escrita, ou `null` numa listagem só de leitura.
     * @param array<string, string> $textFilters As colunas que se estreitam por texto livre.
     * @param array<string, list<string>> $fixedOptions Opções que não saem dos dados, como um estado.
     * @param list<string> $extra Colunas que não se ordenam nem editam, mas existem na resposta.
     */
    public function __construct(
        private array $sortable = [],
        private ?string $writable = null,
        private array $textFilters = [],
        private array $fixedOptions = [],
        private array $extra = [],
    ) {
    }

    /**
     * @param array<string, list<array{value: string, count?: int}>> $counts Os valores contados, por coluna.
     * @return list<array<string, mixed>>
     */
    public function describe(array $counts = []): array
    {
        // O pedido de escrita diz o que se *edita*, e não que colunas existem. Derivá-lo
        // para as duas coisas punha a password e a matriz de capacidades como colunas de
        // uma tabela -- campos que a resposta nem traz.
        $writable = $this->writableFields();

        $fields = array_values(array_unique(array_merge(
            array_keys($this->sortable),
            array_keys($this->textFilters),
            array_keys($this->fixedOptions),
            $this->extra,
        )));

        $columns = [];
        foreach ($fields as $field) {
            $columns[] = [
                'field' => $field,
                'sortable' => isset($this->sortable[$field]),
                'editable' => in_array($field, $writable, true),
                'filter' => $this->filterFor($field, $counts),
            ];
        }

        return $columns;
    }

    /** As colunas por que a listagem se deixa ordenar. */
    public function sortableColumns(): array
    {
        return $this->sortable;
    }

    /** As colunas que se estreitam por texto livre. */
    public function textFilterColumns(): array
    {
        return $this->textFilters;
    }

    /** @return array<string, mixed>|null */
    private function filterFor(string $field, array $counts): ?array
    {
        if (isset($this->textFilters[$field])) {
            return ['type' => 'text', 'param' => $field];
        }

        if (!isset($this->fixedOptions[$field])) {
            return null;
        }

        // O conjunto é fechado e oferece-se inteiro, mas a contagem vem dos dados: com
        // todas as linhas num dos valores, um dropdown tirado delas deixaria o outro
        // inalcançável.
        return [
            'type' => 'select',
            'param' => $field,
            'multiple' => false,
            'options' => self::withCounts($this->fixedOptions[$field], $counts[$field] ?? []),
        ];
    }

    /**
     * As opções de um conjunto fechado, cada uma com o que os dados tiverem -- zero quando
     * o filtro actual não deixou nenhuma linha nesse valor.
     *
     * @param list<string> $values
     * @param list<array{value?: string, count?: int}> $counted
     * @return list<array{value: string, count: int}>
     */
    private static function withCounts(array $values, array $counted): array
    {
        $byValue = [];
        foreach ($counted as $option) {
            $byValue[(string)($option['value'] ?? '')] = (int)($option['count'] ?? 0);
        }

        return array_values(array_map(
            static fn(string $value): array => ['value' => $value, 'count' => $byValue[$value] ?? 0],
            $values,
        ));
    }

    /** Os campos de conjunto fechado, que o motor conta como conta os outros. */
    public function fixedOptionFields(): array
    {
        return $this->fixedOptions;
    }

    /** @return list<string> */
    private function writableFields(): array
    {
        if ($this->writable === null) {
            return [];
        }

        $constructor = (new ReflectionClass($this->writable))->getConstructor();

        return array_map(
            static fn(ReflectionParameter $parameter): string => $parameter->getName(),
            $constructor?->getParameters() ?? [],
        );
    }
}
