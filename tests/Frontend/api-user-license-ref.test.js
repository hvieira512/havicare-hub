import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { licenseRefIdFor } = await import("../../src/Dashboard/dashboard/settings/api-users.js");

/**
 * O binder recusa a string em vez de a converter (`(int)"abc"` dá `0`, que tem significado nas
 * licenças); o `<select>` vazio dá `""`, e é aqui que passa a `null`.
 */
test("um admin do hub não fica preso a licença nenhuma", () => {
    assert.equal(licenseRefIdFor("hub_admin", ""), null);
    assert.equal(licenseRefIdFor("hub_admin", "1001"), null);
    assert.equal(licenseRefIdFor("hub_admin", 1001), null);
});

test("a licença de um cliente sai como inteiro e não como texto", () => {
    assert.equal(licenseRefIdFor("license_client", "1001"), 1001);
    assert.equal(licenseRefIdFor("license_client", 1001), 1001);
});

test("um cliente sem licença escolhida manda null, e não a string vazia", () => {
    assert.equal(licenseRefIdFor("license_client", ""), null);
    assert.equal(licenseRefIdFor("license_client", null), null);
    assert.equal(licenseRefIdFor("license_client", undefined), null);
});
