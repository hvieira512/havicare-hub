import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";
import { renderConfigInputs } from "../../src/Dashboard/dashboard/devices/config/index.js";
import { configSection } from "./support/dom.js";

const ENTRY = { input: "alarm_clock", key: "alarm_clock", fields: [] };

/**
 * As três combinações que os fornecedores declaram. Nenhum declara nome e tipo ao mesmo
 * tempo, e por isso essa quarta não se testa: seriam seis controlos numa linha de doze.
 */
const META = {
    "4P Touch": {},
    Vivistar: {
        type: {
            options: [
                { value: 1, label: "Medicação" },
                { value: 2, label: "Água" },
                { value: 3, label: "Sedentarismo" },
            ],
        },
    },
    Wonlex: { label: { supported: true } },
};

const columnsOf = (meta) =>
    [...configSection(renderConfigInputs, ENTRY, {}, meta)
        .querySelectorAll("[data-repeat-row=\"alarm_clock\"] details .row > *")]
        .map((cell) => cell.className);

/** Só a Vivistar declara `type`, e era ela que acertava no ramo de uma coluna. */
test("nenhum controlo de um alarme fica com uma coluna de doze", () => {
    for (const [supplier, meta] of Object.entries(META)) {
        const columns = columnsOf(meta);

        assert.ok(columns.length > 0, `${supplier}: a linha não desenhou`);
        for (const className of columns) {
            assert.doesNotMatch(className, /\bcol-lg-1\b/, `${supplier}: ${className}`);
        }
    }
});

/** Passar de doze parte a linha para baixo e desalinha os alarmes entre si. */
test("as colunas de um alarme cabem nas doze em qualquer fornecedor", () => {
    for (const [supplier, meta] of Object.entries(META)) {
        const total = columnsOf(meta)
            .map((className) => className.match(/\bcol-lg-(\d+)\b/))
            .reduce((sum, found) => sum + (found ? Number(found[1]) : 0), 0);

        assert.ok(total <= 12, `${supplier}: as colunas somam ${total}`);
    }
});
