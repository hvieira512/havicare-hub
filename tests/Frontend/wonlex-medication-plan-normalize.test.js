import test from "node:test";
import assert from "node:assert/strict";

import {
    normalizeWonlexMedicationPlan,
    normalizeWonlexMedicationPlans,
} from "../../src/Dashboard/dashboard/devices/config/normalizers.js";

/**
 * O plano passa pelo normalizador duas vezes -- uma na lista, outra na linha -- e a segunda
 * não pode desfazer a primeira: os períodos deixam de vir em `drugTime` e cairiam para um só.
 */
test("normalizar um plano já normalizado não lhe tira os períodos", () => {
    const [once] = normalizeWonlexMedicationPlans({
        plans: [{
            drugName: "Paracetamol",
            drugTime: {
                alarmClock: { Morning: "08:00", Midday: "12:00" },
                checkboxes: [0, 1],
                radio: 1,
            },
        }],
    });

    const twice = normalizeWonlexMedicationPlan(once);

    assert.deepEqual(twice.periods, [0, 1]);
    assert.deepEqual(twice.alarmClock, { Morning: "08:00", Midday: "12:00" });
    assert.equal(twice.mealTiming, 1);
});
