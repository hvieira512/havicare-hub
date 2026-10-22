import test, { beforeEach, describe } from "node:test";
import assert from "node:assert/strict";
import { autoEls } from "./support/els.js";
import { cacheElements } from "../../src/Dashboard/dashboard/dom.js";

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
describe("draft", () => {
    beforeEach(() => {
        cacheElements(els);
        initDetailFilters();
        state.detailFilters = { from: "2026-09-20T08:00", to: "", type: "all", q: "" };
        resetDetailFiltersDraft();
    });

    test("a date cleared in the draft is not revived by the applied value", () => {
        updateDetailFiltersDraft({ from: "" });

        syncDetailFilterControls();

        assert.equal(els.detailFilterFrom.value, "", "o campo devia ficar vazio");
    });

    test("without a draft the fields show what is applied", () => {
        state.detailFiltersDraft = null;

        syncDetailFilterControls();

        assert.equal(els.detailFilterFrom.value, "2026-09-20T08:00");
    });

    test("an unapplied draft overrides the applied value", () => {
        updateDetailFiltersDraft({ from: "2026-09-21T09:30" });

        syncDetailFilterControls();

        assert.equal(els.detailFilterFrom.value, "2026-09-21T09:30");
    });
});

describe("labels", () => {
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

    test("searching by the Portuguese label finds the row", () => {
        state.detailFilters = { ...state.detailFilters, q: "bateria" };

        assert.equal(filterDetailItems([battery(70)]).length, 1);
    });

    test("searching by the English key still finds it", () => {
        // Quem opera o hub conhece as chaves do contrato, e elas não deixam de servir.
        state.detailFilters = { ...state.detailFilters, q: "battery" };

        assert.equal(filterDetailItems([battery(70)]).length, 1);
    });

    test("the type filter chip shows the label, not the key", () => {
        const labels = detailFilterChipLabels({
            from: "",
            to: "",
            type: "battery",
            q: "",
        });

        assert.deepEqual(labels, [{ key: "type", label: "Bateria" }]);
    });

    test("the searched text appears in the chip as typed", () => {
        const labels = detailFilterChipLabels({
            from: "",
            to: "",
            type: "all",
            q: " bateria ",
        });

        assert.deepEqual(labels, [{ key: "q", label: "\"bateria\"" }]);
    });
});

describe("ranges", () => {
    /**
     * Ninguém escreve uma data para ver o dia de hoje: os alcances prontos aplicam-se ao toque e
     * o intervalo à mão fica para quem precisa mesmo dele.
     */
    let view;

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
        state.detailFilters = { from: "", to: "", type: "all", q: "" };
        state.selectedDetail = null;
        view = autoEls(undefined, filterEls());
        cacheElements(view);
        initDetailFilters();
    });

    test("today starts at local midnight, not twenty-four hours ago", () => {
        applyDetailRange("today");

        assert.equal(Date.parse(state.detailFilters.from), startOfToday());
        assert.equal(state.detailFilters.to, "");
    });

    test("seven days counts seven days back", () => {
        applyDetailRange("7d");

        const days = (Date.now() - Date.parse(state.detailFilters.from)) / 86400000;
        assert.ok(Math.abs(days - 7) < 0.01, `foram ${days} dias`);
    });

    test("the range applies on tap, without going through Aplicar", () => {
        view.requestGrid.innerHTML = "<p>antes</p>";

        applyDetailRange("30d");

        assert.equal(view.requestGrid.innerHTML, "", "o detalhe devia ter sido redesenhado");
        assert.equal(state.telemetryPage, 1);
    });

    test("a new range takes the request list back to its first page", () => {
        state.downlinkPage = 3;
        state.downlinkCumulative = true;

        applyDetailRange("7d");

        assert.equal(state.downlinkPage, 1);
        assert.equal(state.downlinkCumulative, false);
    });

    test("the range chip says the button name, not the dates", () => {
        applyDetailRange("7d");

        assert.deepEqual(detailFilterChipLabels(state.detailFilters), [
            { key: "range", label: "7 dias" },
        ]);
    });

    /** Um intervalo escrito à mão não é nenhum dos alcances, e a pastilha volta às datas. */
    test("a hand-typed interval still shows the dates", () => {
        const labels = detailFilterChipLabels({
            from: "2026-09-01T00:00",
            to: "2026-09-02T00:00",
            type: "all",
            q: "",
        });

        assert.equal(labels.length, 1);
        assert.match(labels[0].label, /→/);
    });

    test("clearing the filters drops the chosen range", () => {
        applyDetailRange("today");
        clearDetailFilters();

        assert.deepEqual(detailFilterChipLabels(state.detailFilters), []);
    });

    /** O «Aplicar» vive dentro das «Datas…», que podem estar fechadas. */
    test("the type applies when chosen, without going through Aplicar", () => {
        const els = filterEls();
        els.detailFilterType.innerHTML = "<option value=\"all\">Todos</option><option value=\"sleep_state\">Estado do sono</option>";
        view = autoEls(undefined, els);
        view.requestGrid.innerHTML = "<p>antes</p>";
        cacheElements(view);
        initDetailFilters();

        els.detailFilterType.value = "sleep_state";
        applyDetailType();

        assert.equal(state.detailFilters.type, "sleep_state");
        assert.equal(view.requestGrid.innerHTML, "", "o detalhe devia ter sido redesenhado");
    });
});

/**
 * Filtros herdados do aparelho anterior escondem as leituras do seguinte, e o painel diria
 * «Ainda não há leituras».
 */
describe("device switch", () => {
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
        cacheElements(els);
        initDetailFilters();
        state.selectedImei = "aaa111";
        state.detailFilters = { from: "", to: "", type: "blood_pressure", q: "78" };
        state.detailFiltersDraft = { ...state.detailFilters };
    });

    test("choosing another device drops the filters applied to the previous one", () => {
        selectImei("bbb222");

        assert.deepEqual(state.detailFilters, { from: "", to: "", type: "all", severity: "all", q: "" });
        assert.deepEqual(state.detailFiltersDraft, { from: "", to: "", type: "all", severity: "all", q: "" });
    });

    test("choosing the same device again leaves the filters alone", () => {
        selectImei("aaa111");

        assert.equal(state.detailFilters.type, "blood_pressure");
    });

    test("the type selector drops the options the new device does not have", () => {
        state.selectedDetail = detailWith(["blood_pressure", "heart_rate"]);
        populateDetailFilterTypes();
        assert.deepEqual(
            [...els.detailFilterType.options].map((option) => option.value),
            ["all", "heart_rate", "blood_pressure"],
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
