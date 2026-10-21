import { stateBadge } from "../../../components/state-badge.js";
import { html, raw } from "../../../html.js";
import { state } from "../../../state.js";
import { nextUid } from "./shared.js";

// O identificador é do hub e viaja escondido no cartão: um cartão sem ele é um registo novo.

const SEX = { male: "Homem", female: "Mulher" };

const FAILURE = {
    memory_full: "A balança recusou: memória cheia. Apague um utilizador para libertar espaço.",
    user_selection_in_progress: "A balança recusou: estava a escolher utilizador no próprio aparelho.",
    await_timeout: "A balança não respondeu.",
};

const FAILED = ["failed", "retry_exhausted", "response_timeout"];

/** A idade de hoje; quem foi guardado antes da data de nascimento tem só a idade. */
function age(user) {
    if (!user.birthDate) return user.ageYears;
    const [year, month, day] = user.birthDate.split("-").map(Number);
    const today = new Date();
    const birthdayPassed = today.getMonth() + 1 > month || (today.getMonth() + 1 === month && today.getDate() >= day);
    return today.getFullYear() - year - (birthdayPassed ? 0 : 1);
}

function meta(user) {
    const years = age(user);
    return [
        years ? `${years} anos` : "",
        SEX[user.sex]?.toLowerCase() ?? "",
        user.heightCm ? `${user.heightCm} cm` : "",
        user.weightKg || user.weightKg === 0 ? `${user.weightKg} kg` : "",
        user.athlete ? "atleta" : "",
    ].filter(Boolean).join(" · ");
}

/** O que falta à última operação de cada pessoa; aplicada, não há nada a dizer. */
function deliveryOf(user) {
    const operations = state.deviceModal?.configurationSync?.entries?.health?.scale_users?.operations ?? [];
    const own = operations.filter((operation) => operation?.target === user.id &&
        operation.command !== "selectScaleUser" && operation.deliveryStatus !== "superseded");
    const last = own[own.length - 1];
    if (!last || last.deliveryStatus === "acked") return null;
    if (FAILED.includes(last.deliveryStatus)) {
        return { label: "Falhou", tone: "danger", error: FAILURE[last.error] ?? "A balança recusou o registo." };
    }

    return { label: "A enviar", tone: "warning" };
}

/** O que falta a pôr em linha quem o rádio marca; aceite, o rádio chega. */
function onlineBadge(id) {
    const operations = state.deviceModal?.configurationSync?.entries?.health?.scale_users?.operations ?? [];
    const last = operations.filter((operation) => operation?.command === "selectScaleUser" && operation.target === id).pop();
    if (!last || last.deliveryStatus === "acked") return "";
    if (FAILED.includes(last.deliveryStatus)) return stateBadge("Não ficou em linha", "danger");

    return stateBadge("A pôr em linha", "warning");
}

function userCard(user, { dirty = false, online = "", group = "" } = {}) {
    const saved = Boolean(user.id) && !dirty;
    const delivery = dirty || !user.id ? { label: "Por enviar", tone: "warning" } : deliveryOf(user);
    const isOnline = saved && user.id === online;

    return html`
        <div class="scale-user d-flex align-items-center gap-3 border rounded px-3 py-2${raw(isOnline ? " scale-user-online" : "")}"
             data-scale-user="${JSON.stringify(user)}">
            <input class="form-check-input scale-user-radio flex-shrink-0 m-0" type="radio" name="${group}" value="${user.id || ""}"
                   aria-label="${user.name} em linha"${raw(isOnline ? " checked" : "")}${raw(saved ? "" : " disabled")}>
            <div class="flex-grow-1 min-w-0">
                <div class="fw-semibold d-flex flex-wrap align-items-center gap-2">${user.name}${isOnline ? onlineBadge(user.id) : ""}</div>
                <div class="small text-secondary">${meta(user)}</div>
                ${delivery?.error ? html`<div class="small text-danger">${delivery.error}</div>` : ""}
            </div>
            <div class="d-flex flex-wrap align-items-center justify-content-end gap-2">
                ${delivery ? stateBadge(delivery.label, delivery.tone) : ""}
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

function form(bounds) {
    return html`
        <div class="scale-user-form border rounded p-3 vstack gap-2" data-scale-user-form hidden>
            <div class="fw-semibold" data-scale-form-title>Novo utilizador</div>
            <div class="row g-2">
                <label class="col-12 col-md-6 form-label-sm">Nome<input class="form-control" type="text" maxlength="64" data-scale-field="name"></label>
                <label class="col-6 col-md-3 form-label-sm">Data de nascimento<input class="form-control" type="date" data-scale-field="birthDate"></label>
                <div class="col-6 col-md-3 form-label-sm d-flex flex-column gap-1">Sexo${segmented("sex", [["male", "Homem"], ["female", "Mulher"]], "male")}</div>
                <label class="col-6 col-md-3 form-label-sm">Altura (cm)<input class="form-control" type="number" min="${bounds.heightCm?.min ?? 0}" max="${bounds.heightCm?.max ?? ""}" data-scale-field="heightCm"></label>
                <label class="col-6 col-md-3 form-label-sm">Peso inicial (kg)<input class="form-control" type="number" min="${bounds.weightKg?.min ?? 0}" max="${bounds.weightKg?.max ?? ""}" step="0.1" data-scale-field="weightKg"></label>
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

function scaleUsersInput(entry, desired) {
    const users = Array.isArray(desired?.items) ? desired.items : [];
    const online = String(desired?.onlineUserId ?? "");
    const group = nextUid("scale-online");
    return html`
        <div class="vstack gap-2">
            <div class="d-flex justify-content-end"><button type="button" class="btn btn-outline-secondary btn-sm" data-action="scaleUserAdd">Adicionar</button></div>
            <div class="vstack gap-2" data-scale-user-list data-scale-group="${group}">${users.map((user) => userCard(user, { online, group }))}</div>
            ${form(entry.bounds ?? {})}
        </div>`;
}

const userOf = (element) => JSON.parse(element.dataset.scaleUser || "{}");

function fill(formElement, user) {
    for (const field of ["name", "birthDate", "heightCm", "weightKg"]) {
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

function readForm(formElement) {
    const number = (field) => {
        const text = String(formElement.querySelector(`input[data-scale-field="${field}"]`).value).trim();
        return text === "" ? null : Number(text);
    };
    const user = {
        name: String(formElement.querySelector("input[data-scale-field=\"name\"]").value).trim(),
        birthDate: String(formElement.querySelector("input[data-scale-field=\"birthDate\"]").value),
        sex: picked(formElement, "sex"),
        heightCm: number("heightCm"),
        athlete: picked(formElement, "athlete") === "true",
        weightKg: number("weightKg"),
    };
    if (user.name === "") return { error: "O nome é obrigatório." };
    if (user.birthDate === "" || user.heightCm === null || user.weightKg === null) {
        return { error: "Preencha a data de nascimento, a altura e o peso inicial." };
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

/** Devolve se mexeu no que vai ser enviado. */
export function handleScaleUserAction(section, button) {
    const action = button.dataset.action;
    const formElement = section.querySelector("[data-scale-user-form]");

    if (action === "scaleUserAdd") openForm(section, null);
    if (action === "scaleUserEdit") openForm(section, button.closest("[data-scale-user]"));
    if (action === "scaleUserCancel") closeForm(section);
    if (action === "scaleUserSex") pick(formElement, "sex", button.dataset.value);
    if (action === "scaleUserAthlete") pick(formElement, "athlete", button.dataset.value);

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

function scaleUserChoiceInput() {
    const users = registeredUsers();
    if (users.length === 0) {
        return html`<div class="small text-secondary">Registe primeiro um utilizador da balança.</div>`;
    }

    return html`<select class="form-select" data-config-field="id" aria-label="Utilizador">
        ${users.map((user) => html`<option value="${user.id}">${user.name}</option>`)}
    </select>`;
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
    },
    // Ler o histórico de uma pessoa: escolhe-se pelo nome, e sem ninguém registado não há envio.
    scaleUserChoice: {
        render: scaleUserChoiceInput,
        read: (section) => ({ id: String(section.querySelector("select[data-config-field=\"id\"]")?.value ?? "") }),
        ready: () => registeredUsers().length > 0,
    },
    // Quem está em linha escolhe-se no rádio da lista: o pedido avulso não tem secção própria.
    scaleUserAction: {
        embedded: true,
        render: () => "",
        read: () => ({ id: registeredUsers()[0]?.id ?? "" }),
    },
};
