/**
 * As horas de todos os planos de medicação, achatadas. Fica fora do `config/` porque o cartão
 * lê-as no arranque, e o `config/` só carrega quando se abre o separador.
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
