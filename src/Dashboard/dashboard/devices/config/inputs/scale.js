import { html, raw } from "../../../html.js";
import { state } from "../../../state.js";

/**
 * Os utilizadores da balança: uma linha por pessoa, que se abre com o perfil. O identificador é
 * do hub e viaja escondido na linha; uma linha sem ele é um registo novo.
 */

const SEX = { male: "Homem", female: "Mulher" };

function summary(user) {
    if (!user.name) return "Novo utilizador";
    const parts = [
        user.ageYears ? `${user.ageYears} anos` : "",
        SEX[user.sex]?.toLowerCase() ?? "",
        user.heightCm ? `${user.heightCm} cm` : "",
        user.weightKg ? `${user.weightKg} kg` : "",
        user.athlete ? "atleta" : "",
    ].filter(Boolean);

    return html`<span class="fw-semibold">${user.name}</span> <span class="small text-secondary">${parts.join(" · ")}</span>`;
}

export function scaleUserRow(user = {}) {
    const open = user.name ? "" : " open";
    return html`
        <div data-repeat-row="scale_users" data-user-id="${user.id || ""}">
        <details class="border rounded px-3 py-2" name="scale-users"${raw(open)}>
            <summary class="d-flex gap-2 align-items-baseline">${summary(user)}</summary>
            <div class="row g-2 mt-1">
                <label class="col-12 col-md-6 form-label-sm">Nome<input class="form-control" type="text" maxlength="64" data-user-field="name" value="${user.name || ""}"></label>
                <label class="col-6 col-md-3 form-label-sm">Idade<input class="form-control" type="number" min="1" max="120" data-user-field="ageYears" value="${user.ageYears ?? ""}"></label>
                <label class="col-6 col-md-3 form-label-sm">Sexo<select class="form-select" data-user-field="sex">
                    ${Object.entries(SEX).map(([value, label]) => html`<option value="${value}"${raw(user.sex === value ? " selected" : "")}>${label}</option>`)}
                </select></label>
                <label class="col-6 col-md-3 form-label-sm">Altura (cm)<input class="form-control" type="number" min="50" max="250" data-user-field="heightCm" value="${user.heightCm ?? ""}"></label>
                <label class="col-6 col-md-3 form-label-sm">Peso inicial (kg)<input class="form-control" type="number" min="0" max="250" step="0.1" data-user-field="weightKg" value="${user.weightKg ?? ""}"></label>
                <div class="col-12 col-md-6 d-flex align-items-end justify-content-between gap-2">
                    <label class="form-check form-switch m-0"><input class="form-check-input" type="checkbox" role="switch" data-user-field="athlete"${raw(user.athlete ? " checked" : "")}> Modo atleta</label>
                    <button type="button" class="btn btn-outline-danger btn-quiet-danger btn-sm" data-action="removeRepeatRow">Apagar</button>
                </div>
            </div>
        </details>
        </div>`;
}

function awakeStrip() {
    const awake = Boolean(state.deviceModal?.online);
    return html`<div class="small text-secondary border rounded px-3 py-2">${awake
        ? "Acordada: o que enviar sai agora."
        : "A dormir: o que enviar espera até 5 minutos que alguém suba para a balança."}</div>`;
}

function scaleUsersInput(_entry, desired) {
    const users = Array.isArray(desired?.items) ? desired.items : [];
    return html`
        <div class="vstack gap-2">
            ${awakeStrip()}
            <div class="vstack gap-2" data-repeat-list="scale_users">
                ${users.map((user) => scaleUserRow(user))}
            </div>
            <div><button type="button" class="btn btn-outline-secondary btn-sm" data-action="addRepeatRow" data-repeat-kind="scale_users">Adicionar</button></div>
        </div>`;
}

function readUser(row) {
    const value = (field) => row.querySelector(`[data-user-field="${field}"]`);
    const number = (field) => {
        const text = String(value(field)?.value ?? "").trim();
        return text === "" ? null : Number(text);
    };
    const user = {
        name: String(value("name")?.value ?? "").trim(),
        ageYears: number("ageYears"),
        sex: String(value("sex")?.value ?? ""),
        heightCm: number("heightCm"),
        athlete: Boolean(value("athlete")?.checked),
        weightKg: number("weightKg"),
    };

    return row.dataset.userId ? { id: row.dataset.userId, ...user } : user;
}

function registeredUsers() {
    const items = state.deviceModal?.configurations?.scale_users?.items;
    return Array.isArray(items) ? items.filter((user) => user?.id) : [];
}

function scaleUserChoiceInput() {
    const users = registeredUsers();
    if (users.length === 0) {
        return html`<div class="small text-secondary">Registe primeiro um utilizador da balança.</div>`;
    }

    return html`<select class="form-select" data-config-field="id">
        ${users.map((user) => html`<option value="${user.id}">${user.name || user.id}</option>`)}
    </select>`;
}

export const INPUTS = {
    scaleUsers: {
        render: scaleUsersInput,
        // Uma linha nova sem nome não é ninguém: não vai.
        read: (section) => ({
            items: [...section.querySelectorAll("[data-repeat-row=\"scale_users\"]")]
                .map(readUser)
                .filter((user) => user.name !== ""),
        }),
        defaults: () => ({ items: [] }),
    },
    scaleUserChoice: {
        render: scaleUserChoiceInput,
        read: (section) => ({ id: String(section.querySelector("select[data-config-field=\"id\"]")?.value ?? "") }),
        defaults: () => ({ id: registeredUsers()[0]?.id ?? "" }),
    },
};
