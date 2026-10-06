/**
 * As horas de todos os planos de medicação, achatadas, com o compartimento e o estado.
 *
 * Um plano é um medicamento com as suas horas; quem desenha o dispensador quer as horas. Vive
 * fora do `config/` porque o cartão do dispositivo lê-o no arranque, e o cluster das
 * configurações só entra quando alguém abre o separador.
 */
export function medicationPlanTimes(plans) {
    if (!Array.isArray(plans)) {
        return [];
    }

    const times = [];
    plans.forEach((plan, position) => {
        const entries = Array.isArray(plan?.times) ? plan.times : [];
        entries.forEach((entry) => {
            const [hour, minute] = String(entry?.time ?? "").split(":");
            times.push({
                slot: Number(entry?.slot ?? position + 1),
                hour: Number(hour) || 0,
                minute: Number(minute) || 0,
                enabled: entry?.enabled !== false,
            });
        });
    });

    return times;
}
