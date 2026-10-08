<?php

declare(strict_types=1);

namespace Hub\Infrastructure\Persistence\Migration;

use PDO;

/**
 * Tira dos modelos o alarme de frequência cardíaca da 4P Touch, que o primeiro deploy ligou: o
 * catálogo continua a declará-lo, mas nenhum protocolo o anuncia, e o semeador só acrescenta.
 */
final class UnconfirmedHeartRateAlarmIsUnlinked implements Migration
{
    public function version(): string
    {
        return 'unconfirmed_heart_rate_alarm_is_unlinked';
    }

    public function up(PDO $pdo): void
    {
        $pdo->exec("DELETE FROM model_capabilities WHERE device_type = 'watch' AND capability_key = 'heart_rate_abnormal'");
    }
}
