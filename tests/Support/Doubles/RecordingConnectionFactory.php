<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Hub\Mqtt\ConnectionFactory;
use PhpMqtt\Client\Contracts\Repository;
use PhpMqtt\Client\MqttClient;

/** Cria os clientes sem os ligar, e guarda-os para o teste ver os ids e as subscrições. */
final class RecordingConnectionFactory extends ConnectionFactory
{
    /** @var list<MqttClient> */
    public array $created = [];
    /** @var list<Repository> */
    public array $repositories = [];
    /** @var list<bool> */
    public array $cleanSessions = [];

    public function create(string $suffix, bool $stableClientId = false, ?Repository $repository = null): MqttClient
    {
        $client = parent::create($suffix, $stableClientId, $repository);
        $this->created[] = $client;
        if ($repository !== null) {
            $this->repositories[] = $repository;
        }

        return $client;
    }

    public function connect(MqttClient $client, bool $cleanSession = true): MqttClient
    {
        $this->cleanSessions[] = $cleanSession;

        return $client;
    }
}
