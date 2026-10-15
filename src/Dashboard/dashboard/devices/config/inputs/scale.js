import { requestCapability } from "../../../api/index.js";
import { stateBadge } from "../../../components/state-badge.js";
import { html, raw } from "../../../html.js";
import { state } from "../../../state.js";
import { nextUid } from "./shared.js";

/**
 * Os utilizadores da balança: um cartão por pessoa, com o estado dela e as ações do dia, e um
 * formulário que só abre para acrescentar ou editar. O identificador é do hub e viaja escondido no
 * cartão; um cartão sem ele é um registo novo.
 */

const SEX = { male: "Homem", female: "Mulher" };

const FAILURE = {
    memory_full: "A balança recusou: memória cheia. Apague um utilizador para libertar espaço.",
    user_selection_in_progress: "A balança recusou: estava a escolher utilizador no próprio aparelho.",
    await_timeout: "A balança não respondeu.",
};

const FAILED = ["failed", "retry_exhausted", "response_timeout"];

function meta(user) {
    return [
        user.ageYears ? `${user.ageYears} anos` : "",
        SEX[user.sex]?.toLowerCase() ?? "",
        user.heightCm ? `${user.heightCm} cm` : "",
        user.weightKg || user.weightKg === 0 ? `${user.weightKg} kg` : "",
        user.athlete ? "atleta" : "",
    ].filter(Boolean).join(" · ");
}

/** A última operação de cada pessoa diz se ela já está na balança. */
function deliveryOf(user) {
    const operations = state.deviceModal?.configurationSync?.entries?.health?.scale_users?.operations ?? [];
    const own = operations.filter((operation) => operation?.target === user.id &&
        operation.command !== "selectScaleUser" && operation.deliveryStatus !== "superseded");
    const last = own[own.length - 1];
    if (!last || last.deliveryStatus === "acked") return { label: "Na balança", tone: "success" };
    if (FAILED.includes(last.deliveryStatus)) {
        return { label: "Falhou", tone: "danger", error: FAILURE[last.error] ?? "A balança recusou o registo." };
    }

    return { label: "A enviar", tone: "warning" };
}

/** Em que pé está pôr em linha quem a lista diz que está, pela operação que o fez. */
function onlineBadge(id) {
    const operations = state.deviceModal?.configurationSync?.entries?.health?.scale_users?.operations ?? [];
    const last = operations.filter((operation) => operation?.command === "selectScaleUser" && operation.target === id).pop();
    if (!last || last.deliveryStatus === "acked") return stateBadge("Em linha", "success");
    if (FAILED.includes(last.deliveryStatus)) return stateBadge("Não ficou em linha", "danger");

    return stateBadge("A pôr em linha", "warning");
}

function userCard(user, { dirty = false, online = "", group = "" } = {}) {
    const saved = Boolean(user.id) && !dirty;
    const delivery = dirty || !user.id ? { label: "Por enviar", tone: "warning" } : deliveryOf(user);
    const isOnline = saved && user.id === online;

    return html`
        <div class="scale-user d-flex align-items-center gap-3 border rounded px-3 py-2${raw(isOnline ? " scale-user-online" : "")}"
             data-scale-user="${JSON.stringify(user)}"${raw(dirty ? " data-scale-user-dirty" : "")}>
            <input class="form-check-input scale-user-radio flex-shrink-0 m-0" type="radio" name="${group}" value="${user.id || ""}"
                   aria-label="${user.name} em linha"${raw(isOnline ? " checked" : "")}${raw(saved ? "" : " disabled")}>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold d-flex flex-wrap align-items-center gap-2">${user.name}${isOnline ? onlineBadge(user.id) : ""}</div>
                <div class="small text-secondary">${meta(user)}</div>
                ${delivery.error ? html`<div class="small text-danger">${delivery.error}</div>` : ""}
            </div>
            <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                ${stateBadge(delivery.label, delivery.tone)}
                ${saved ? html`<button type="button" class="btn btn-link btn-sm text-secondary text-decoration-none" data-action="scaleUserHistory">Pedir histórico</button>` : ""}
                <button type="button" class="btn btn-link btn-sm text-secondary text-decoration-none" data-action="scaleUserEdit">Editar</button>
            </div>
        </div>`;
}

function segmented(field, options, value) {
    return html`<div class="btn-group btn-group-sm" role="group" data-scale-field="${field}">
        ${options.map(([optionValue, label]) => {
            const on = optionValue === value;
            return html`<button type="button" class="btn ${on ? "btn-primary" : "btn-outline-secondary"}" data-action="${field === "sex" ? "scaleUserSex" : "scaleUserAthlete"}" data-value="${optionValue}" aria-pressed="${on ? "true" : "false"}">${label}</button>`;
        })}
    </div>`;
}

function form() {
    return html`
        <div class="scale-user-form border rounded p-3 vstack gap-2" data-scale-user-form hidden>
            <div class="fw-semibold" data-scale-form-title>Novo utilizador</div>
            <div class="row g-2">
                <label class="col-12 col-md-6 form-label-sm">Nome<input class="form-control" type="text" maxlength="64" data-scale-field="name"></label>
                <label class="col-6 col-md-3 form-label-sm">Idade<input class="form-control" type="number" min="1" max="120" data-scale-field="ageYears"></label>
                <div class="col-6 col-md-3 form-label-sm d-flex flex-column gap-1">Sexo${segmented("sex", [["male", "Homem"], ["female", "Mulher"]], "male")}</div>
                <label class="col-6 col-md-3 form-label-sm">Altura (cm)<input class="form-control" type="number" min="50" max="250" data-scale-field="heightCm"></label>
                <label class="col-6 col-md-3 form-label-sm">Peso inicial (kg)<input class="form-control" type="number" min="0" max="250" step="0.1" data-scale-field="weightKg"></label>
                <div class="col-12 col-md-6 form-label-sm d-flex flex-column gap-1">Modo atleta${segmented("athlete", [["false", "Não"], ["true", "Sim"]], "false")}</div>
            </div>
            <div class="small text-danger" data-scale-form-error hidden></div>
            <div class="d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm me-auto" data-action="scaleUserDelete" hidden>Apagar</button>
                <button type="button" class="btn btn-link btn-sm text-secondary text-decoration-none" data-action="scaleUserCancel">Cancelar</button>
                <button type="button" class="btn btn-outline-secondary btn-sm" data-action="scaleUserSave">Guardar</button>
            </div>
        </div>`;
}

function awakeStrip() {
    if (state.deviceModal?.online) {
        return html`<div class="small border rounded px-3 py-2 bg-body-tertiary"><span class="fw-semibold">Acordada</span> · o que enviar sai agora.</div>`;
    }
    const lastSeen = Date.parse(String(state.deviceModal?.lastSeenAt ?? ""));
    const when = Number.isNaN(lastSeen)
        ? ""
        : ` · acordada pela última vez às ${new Date(lastSeen).toLocaleTimeString("pt-PT", { hour: "2-digit", minute: "2-digit" })}`;

    return html`<div class="small border rounded px-3 py-2 bg-body-tertiary"><span class="fw-semibold">A dormir</span>${when}. O que enviar espera até 5 minutos que alguém suba para a balança.</div>`;
}

function scaleUsersInput(_entry, desired) {
    const users = Array.isArray(desired?.items) ? desired.items : [];
    const online = String(desired?.onlineUserId ?? "");
    const group = nextUid("scale-online");
    return html`
        <div class="vstack gap-2" data-scale-users>
            <div class="d-flex justify-content-end"><button type="button" class="btn btn-outline-secondary btn-sm" data-action="scaleUserAdd">Adicionar</button></div>
            ${awakeStrip()}
            <div class="vstack gap-2" data-scale-user-list data-scale-group="${group}">${users.map((user) => userCard(user, { online, group }))}</div>
            ${form()}
        </div>`;
}

const userOf = (element) => JSON.parse(element.dataset.scaleUser || "{}");

function fill(formElement, user) {
    for (const field of ["name", "ageYears", "heightCm", "weightKg"]) {
        formElement.querySelector(`input[data-scale-field="${field}"]`).value = user[field] ?? "";
    }
    pick(formElement, "sex", user.sex ?? "male");
    pick(formElement, "athlete", String(Boolean(user.athlete)));
}

function pick(formElement, field, value) {
    for (const button of formElement.querySelectorAll(`[data-scale-field="${field}"] button`)) {
        const on = button.dataset.value === value;
        button.classList.toggle("btn-primary", on);
        button.classList.toggle("btn-outline-secondary", !on);
        button.setAttribute("aria-pressed", on ? "true" : "false");
    }
}

function picked(formElement, field) {
    return formElement.querySelector(`[data-scale-field="${field}"] [aria-pressed="true"]`)?.dataset.value ?? "";
}

/** O que está no formulário, ou a razão por que não se guarda. */
function readForm(formElement) {
    const number = (field) => {
        const text = String(formElement.querySelector(`input[data-scale-field="${field}"]`).value).trim();
        return text === "" ? null : Number(text);
    };
    const user = {
        name: String(formElement.querySelector("input[data-scale-field=\"name\"]").value).trim(),
        ageYears: number("ageYears"),
        sex: picked(formElement, "sex"),
        heightCm: number("heightCm"),
        athlete: picked(formElement, "athlete") === "true",
        weightKg: number("weightKg"),
    };
    if (user.name === "") return { error: "O nome é obrigatório." };
    if (user.ageYears === null || user.heightCm === null || user.weightKg === null) {
        return { error: "Preencha a idade, a altura e o peso inicial." };
    }

    return { user };
}

function openForm(section, editing) {
    const formElement = section.querySelector("[data-scale-user-form]");
    section.querySelectorAll("[data-scale-user-editing]").forEach((element) => element.removeAttribute("data-scale-user-editing"));
    editing?.setAttribute("data-scale-user-editing", "");
    fill(formElement, editing ? userOf(editing) : {});
    formElement.querySelector("[data-scale-form-title]").textContent = editing ? userOf(editing).name : "Novo utilizador";
    formElement.querySelector("[data-action=\"scaleUserDelete\"]").hidden = !editing;
    formElement.querySelector("[data-scale-form-error]").hidden = true;
    formElement.hidden = false;
}

function closeForm(section) {
    section.querySelector("[data-scale-user-form]").hidden = true;
    section.querySelector("[data-scale-user-editing]")?.removeAttribute("data-scale-user-editing");
}

function cardElement(section, user) {
    const group = section.querySelector("[data-scale-user-list]").dataset.scaleGroup || "";
    const holder = document.createElement("template");
    holder.innerHTML = String(userCard(user, { dirty: true, group }));
    return holder.content.firstElementChild;
}

async function request(button, capability) {
    const user = userOf(button.closest("[data-scale-user]"));
    button.disabled = true;
    button.textContent = "A enviar…";
    const result = await requestCapability(state.deviceModal.imei, capability, { id: user.id });
    button.textContent = result?.error
        ? result.error.message || "Não foi possível pedir."
        : result?.status === "queued" ? "Em fila" : "Pedido";
}

/** As ações da lista. Devolve se mexeu no que vai ser enviado. */
export function handleScaleUserAction(section, button) {
    const action = button.dataset.action;
    const formElement = section.querySelector("[data-scale-user-form]");

    if (action === "scaleUserAdd") openForm(section, null);
    if (action === "scaleUserEdit") openForm(section, button.closest("[data-scale-user]"));
    if (action === "scaleUserCancel") closeForm(section);
    if (action === "scaleUserSex") pick(formElement, "sex", button.dataset.value);
    if (action === "scaleUserAthlete") pick(formElement, "athlete", button.dataset.value);
    if (action === "scaleUserHistory") void request(button, "read_scale_history");

    if (action === "scaleUserDelete") {
        section.querySelector("[data-scale-user-editing]")?.remove();
        closeForm(section);
        return true;
    }

    if (action === "scaleUserSave") {
        const { user, error } = readForm(formElement);
        const errorElement = formElement.querySelector("[data-scale-form-error]");
        if (error) {
            errorElement.textContent = error;
            errorElement.hidden = false;
            return false;
        }
        const editing = section.querySelector("[data-scale-user-editing]");
        if (editing) {
            const id = userOf(editing).id;
            editing.replaceWith(cardElement(section, id ? { id, ...user } : user));
        } else {
            section.querySelector("[data-scale-user-list]").appendChild(cardElement(section, user));
        }
        closeForm(section);
        return true;
    }

    return false;
}

function registeredUsers() {
    const items = state.deviceModal?.configurations?.scale_users?.items;
    return Array.isArray(items) ? items.filter((user) => user?.id) : [];
}

export const INPUTS = {
    scaleUsers: {
        render: scaleUsersInput,
        // Quem está em linha vai com a lista: marcar o círculo não envia nada sozinho.
        read: (section) => {
            const online = section.querySelector("[data-scale-user] input[type=\"radio\"]:checked:not(:disabled)")?.value || "";
            const items = [...section.querySelectorAll("[data-scale-user]")].map(userOf);
            return online ? { items, onlineUserId: online } : { items };
        },
        defaults: () => ({ items: [] }),
    },
    // «Pôr em linha» e «Pedir histórico» vivem em cada cartão da lista: não têm secção própria.
    scaleUserAction: {
        embedded: true,
        render: () => "",
        read: () => ({ id: registeredUsers()[0]?.id ?? "" }),
    },
};
