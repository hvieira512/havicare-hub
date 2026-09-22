import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { supplierModelLabel } = await import("../../src/Dashboard/dashboard/domain.js");

/**
 * Seis dos vinte modelos da frota já trazem o fornecedor no nome comercial -- todos os MOKO
 * e o MONIT. O cabeçalho do modal juntava os dois em cru e lia-se "MONIT MONIT MECS Pro".
 */

test("o fornecedor não se repete quando o nome comercial já começa por ele", () => {
    assert.equal(supplierModelLabel("MONIT", "MONIT MECS Pro"), "MONIT MECS Pro");
    assert.equal(supplierModelLabel("MOKO", "MOKOSmart MKGW3"), "MOKOSmart MKGW3");
    assert.equal(supplierModelLabel("MOKO", "MOKO W6B"), "MOKO W6B");
});

test("o fornecedor entra à frente quando o nome comercial não o traz", () => {
    assert.equal(supplierModelLabel("Wonlex", "HW20PRO"), "Wonlex HW20PRO");
    assert.equal(supplierModelLabel("Zayata", "M228"), "Zayata M228");
});

test("a comparação ignora maiúsculas, que o catálogo não garante", () => {
    assert.equal(supplierModelLabel("Monit", "MONIT MECS Pro"), "MONIT MECS Pro");
});

test("com um dos dois em falta sobra o que há, sem espaços soltos", () => {
    assert.equal(supplierModelLabel("", "M228"), "M228");
    assert.equal(supplierModelLabel("Zayata", ""), "Zayata");
    assert.equal(supplierModelLabel("", ""), "");
});
