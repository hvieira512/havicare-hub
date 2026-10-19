import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { cardContent as uplinkCardContent } from "./support/cards.js";

const { state } = await import("../../src/Dashboard/dashboard/state.js");

test("a pesagem diz de quem é, pelo nome da lista da balança, e se veio da memória", () => {
    state.selectedDetail = {
        device: { imei: "cfe908067329" },
        configurations: { scale_users: { items: [{ id: "u-0a1b2c3d", name: "Hugo" }] } },
    };

    assert.equal(uplinkCardContent("weight", { weightKg: 96.2, scaleUserId: "u-0a1b2c3d" }).details, "Utilizador: Hugo");
    assert.equal(
        uplinkCardContent("weight", { weightKg: 18.2, scaleUserId: "u-0a1b2c3d", fromHistory: true }).details,
        "Utilizador: Hugo · Da memória: Sim",
    );
    // Um utilizador que já saiu da lista continua a ver-se pelo identificador.
    assert.equal(uplinkCardContent("weight", { weightKg: 70, scaleUserId: "u-ffffffff" }).details, "Utilizador: u-ffffffff");
});
