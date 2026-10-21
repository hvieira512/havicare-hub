import test, { beforeEach, describe } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";

const { state, resetDetailFiltersDraft, selectImei, updateDetailFiltersDraft } =
    await import("../../src/Dashboard/dashboard/state.js");
const {
    applyDetailRange,
    applyDetailType,
    clearDetailFilters,
    detailFilterChipLabels,
    filterDetailItems,
    initDetailFilters,
    populateDetailFilterTypes,
    syncDetailFilterControls,
} = await import("../../src/Dashboard/dashboard/devices/detail-filters.js");

const els = autoEls((name) => (name === "detailFilterType" || name === "detailFilterSeverity"
    ? "select"
    : name.startsWith("detailFilter") || name === "detailSearch" ? "input" : "div"));

/** O rascunho é o que está nos campos antes de «Aplicar», e um `""` de propósito conta. */
describe("rascunho", () => {
    beforeEach(() => {
        initDetailFilters({ els, onChange: () => {} });
        state.detailFilters = { from: "2026-09-20T08:00", to: "", type: "all", q: "" };
        resetDetailFiltersDraft();
    });

    test("uma data apagada no rascunho não é ressuscitada pelo valor aplicado", () => {
        updateDetailFiltersDraft({ from: "" });

        syncDetailFilterControls();

        assert.equal(els.detailFilterFrom.value, "", "o campo devia ficar vazio");
    });

    test("sem rascunho, os campos mostram o que está aplicado", () => {
        state.detailFiltersDraft = null;

        syncDetailFilterControls();

        assert.equal(els.detailFilterFrom.value, "2026-09-20T08:00");
    });

    test("um rascunho por aplicar sobrepõe-se ao valor aplicado", () => {
        updateDetailFiltersDraft({ from: "2026-09-21T09:30" });

        syncDetailFilterControls();

        assert.equal(els.detailFilterFrom.value, "2026-09-21T09:30");
    });
});

describe("etiquetas", () => {
    /**
     * A pesquisa e a pastilha do filtro usam a etiqueta da capacidade, «Bateria», e não o
     * `fieldLabel`, que é o dicionário dos campos do payload.
     */

    function battery(percent) {
        return {
            _source: "telemetry",
            raw: {},
            payload: {
                type: "battery",
                occurredAt: "2026-09-08T10:00:00Z",
                data: { percent },
            },
        };
    }

    beforeEach(() => {
        // A etiqueta vem do catálogo de capacidades, que é o mesmo que enche o select do tipo.
        state.capabilityCatalogByType.watch = [
            { key: "battery", label: "Bateria", section: "telemetry" },
        ];
        state.selectedDetail = { model: { deviceType: "watch" } };
        state.detailFilters = { from: "", to: "", type: "all", q: "" };
    });

    test("procurar pela etiqueta em português encontra a linha", () => {
        state.detailFilters = { ...state.detailFilters, q: "bateria" };

        assert.equal(filterDetailItems([battery(70)]).length, 1);
    });

    test("procurar pela chave em inglês continua a encontrá-la", () => {
        // Quem opera o hub conhece as chaves do contrato, e elas não deixam de servir.
        state.detailFilters = { ...state.detailFilters, q: "battery" };

        assert.equal(filterDetailItems([battery(70)]).length, 1);
    });

    test("a pastilha do filtro de tipo mostra a etiqueta e não a chave", () => {
        const labels = detailFilterChipLabels({
            from: "",
            to: "",
            type: "battery",
            q: "",
        });

        assert.deepEqual(labels, [{ key: "type", label: "Bateria" }]);
    });

    test("o texto procurado aparece na pastilha como foi escrito", () => {
        const labels = detailFilterChipLabels({
            from: "",
            to: "",
            type: "all",
            q: " bateria ",
        });

        assert.deepEqual(labels, [{ key: "q", label: "\"bateria\"" }]);
    });
});

describe("alcances", () => {
    /**
     * Ninguém escreve uma data para ver o dia de hoje: os alcances prontos aplicam-se ao toque e
     * o intervalo à mão fica para quem precisa mesmo dele.
     */
    let changes;

    function severitySelect() {
        const select = document.createElement("select");
        select.innerHTML = "<option value=\"all\">Todas</option><option value=\"alarm\">Alarmes</option>";
        return select;
    }

    function filterEls() {
        const element = (tag) => document.createElement(tag);
        return {
            detailFilterFrom: element("input"),
            detailFilterTo: element("input"),
            detailFilterType: element("select"),
            detailFilterSeverity: severitySelect(),
            detailSearch: element("input"),
            detailRangePresets: element("div"),
            detailActiveFilters: element("div"),
            detailActiveFiltersRow: element("div"),
            detailFilterCount: element("span"),
            clearDetailFiltersBtn: element("button"),
        };
    }

    const startOfToday = () => {
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        return today.getTime();
    };

    beforeEach(() => {
        changes = 0;
        state.detailFilters = { from: "", to: "", type: "all", q: "" };
        initDetailFilters({
            els: filterEls(),
            onChange: () => {
                changes += 1;
            },
            renderDownlinkRequests: () => {},
            renderTelemetryList: () => {},
        });
    });

    test("o hoje começa à meia-noite de cá e não vinte e quatro horas atrás", () => {
        applyDetailRange("today");

        assert.equal(Date.parse(state.detailFilters.from), startOfToday());
        assert.equal(state.detailFilters.to, "");
    });

    test("sete dias conta sete dias para trás", () => {
        applyDetailRange("7d");

        const days = (Date.now() - Date.parse(state.detailFilters.from)) / 86400000;
        assert.ok(Math.abs(days - 7) < 0.01, `foram ${days} dias`);
    });

    test("o alcance aplica-se ao toque, sem passar pelo Aplicar", () => {
        applyDetailRange("30d");

        assert.equal(changes, 1);
        assert.equal(state.telemetryPage, 1);
    });

    test("a pastilha do alcance diz o nome do botão e não as datas", () => {
        applyDetailRange("7d");

        assert.deepEqual(detailFilterChipLabels(state.detailFilters), [
            { key: "range", label: "7 dias" },
        ]);
    });

    /** Um intervalo escrito à mão não é nenhum dos alcances, e a pastilha volta às datas. */
    test("um intervalo à mão continua a mostrar as datas", () => {
        const labels = detailFilterChipLabels({
            from: "2026-09-01T00:00",
            to: "2026-09-02T00:00",
            type: "all",
            q: "",
        });

        assert.equal(labels.length, 1);
        assert.match(labels[0].label, /→/);
    });

    test("limpar os filtros larga o alcance escolhido", () => {
        applyDetailRange("today");
        clearDetailFilters();

        assert.deepEqual(detailFilterChipLabels(state.detailFilters), []);
    });

    /** O «Aplicar» vive dentro das «Datas…», que podem estar fechadas. */
    test("o tipo aplica-se ao escolher, sem passar pelo Aplicar", () => {
        const els = filterEls();
        els.detailFilterType.innerHTML = "<option value=\"all\">Todos</option><option value=\"sleep_state\">Estado do sono</option>";
        initDetailFilters({
            els,
            onChange: () => {
                changes += 1;
            },
            renderDownlinkRequests: () => {},
            renderTelemetryList: () => {},
        });

        els.detailFilterType.value = "sleep_state";
        applyDetailType();

        assert.equal(state.detailFilters.type, "sleep_state");
        assert.equal(changes, 1);
    });
});

/**
 * Filtros herdados do aparelho anterior escondem as leituras do seguinte, e o painel diria
 * «Ainda não há leituras».
 */
describe("troca de dispositivo", () => {
    const detailWith = (types) => ({
        recent: {
            telemetry: types.map((type, index) => ({
                type,
                seq: index + 1,
                occurredAt: "2026-10-01T09:00:00Z",
                data: {},
            })),
            events: [],
            commands: [],
            connection: [],
        },
    });

    beforeEach(() => {
        initDetailFilters({ els, onChange: () => {} });
        state.selectedImei = "aaa111";
        state.detailFilters = { from: "", to: "", type: "blood_pressure", q: "78" };
        state.detailFiltersDraft = { ...state.detailFilters };
    });

    test("escolher outro dispositivo larga os filtros aplicados ao anterior", () => {
        selectImei("bbb222");

        assert.deepEqual(state.detailFilters, { from: "", to: "", type: "all", severity: "all", q: "" });
        assert.deepEqual(state.detailFiltersDraft, { from: "", to: "", type: "all", severity: "all", q: "" });
    });

    test("voltar a escolher o mesmo dispositivo não mexe nos filtros", () => {
        selectImei("aaa111");

        assert.equal(state.detailFilters.type, "blood_pressure");
    });

    test("o selector de tipo larga as opções que o dispositivo novo não tem", () => {
        state.selectedDetail = detailWith(["blood_pressure", "heart_rate"]);
        populateDetailFilterTypes();
        assert.deepEqual(
            [...els.detailFilterType.options].map((option) => option.value),
            ["all", "blood_pressure", "heart_rate"],
        );

        selectImei("bbb222");
        state.selectedDetail = detailWith(["presence"]);
        populateDetailFilterTypes();

        assert.deepEqual(
            [...els.detailFilterType.options].map((option) => option.value),
            ["all", "presence"],
        );
    });
});
