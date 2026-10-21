import test from "node:test";
import assert from "node:assert/strict";

import { cardContent } from "./support/cards.js";

/** As fases têm nome de fase: `weightKg` é o peso, e `weightPhase` o que a balança está a fazer com ele. */
test("the weighing card says the phase the scale is in", () => {
    assert.equal(cardContent("weighing_status", { weightKg: 70.2, weightPhase: "stable", impedancePhase: "idle", heartRatePhase: "idle" }).value, "Peso estável");
    assert.equal(cardContent("weighing_status", { weightPhase: "stable", heartRatePhase: "measuring" }).value, "A medir a FC");
});
