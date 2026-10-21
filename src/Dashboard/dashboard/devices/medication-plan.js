/** As horas dos planos, achatadas; fora do `config/`, que só carrega com o separador. */
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
