import test from "node:test";
import assert from "node:assert/strict";

import "./support/browser-env.js";

const { ROLE_COLUMN } = await import("../../src/Dashboard/dashboard/settings/api-users.js");

const rendered = (value) => ROLE_COLUMN.cellRenderer({ value });
const formatted = (value) => ROLE_COLUMN.valueFormatter({ value });

/**
 * O perfil é um estado do utilizador e passa a lê-se como os outros: a pastilha da
 * plataforma, com o ícone a distinguir quem manda em todas as licenças de quem tem uma.
 */
test("a célula em repouso desenha a pastilha do perfil", () => {
    assert.match(rendered("hub_admin"), /class="[^"]*\bstate-badge\b/);
    assert.match(rendered("hub_admin"), />Administrador</);
    assert.match(rendered("license_client"), />Cliente</);
});

/** O ícone é decoração: quem lê o perfil lê o rótulo, e por isso ele sai do leitor de ecrã. */
test("cada perfil tem o seu ícone, fora do alcance do leitor", () => {
    assert.match(rendered("hub_admin"), /<i class="fa-solid fa-shield-halved" aria-hidden="true"><\/i>/);
    assert.match(rendered("license_client"), /<i class="fa-solid fa-building" aria-hidden="true"><\/i>/);
});

/** O `secondary` desta plataforma lê-se como inativo, e um cliente não é isso. */
test("os dois perfis têm tons próprios, e nenhum é o do estado apagado", () => {
    assert.match(rendered("hub_admin"), /bg-primary-subtle/);
    assert.match(rendered("license_client"), /bg-info-subtle/);
    assert.doesNotMatch(rendered("license_client"), /bg-secondary-subtle/);
});

/**
 * O `valueFormatter` é o que desenha as opções do `agSelectCellEditor`. Marcação dentro de
 * um `<option>` não se desenha, e por isso a lista de edição continua a ser texto.
 */
test("as opções do editor continuam texto, com as etiquetas novas", () => {
    assert.equal(formatted("hub_admin"), "Administrador");
    assert.equal(formatted("license_client"), "Cliente");
});

/** Um valor que a lista não conhece não pode desaparecer da célula. */
test("um perfil desconhecido cai no texto do valor", () => {
    assert.equal(formatted("outro"), "outro");
    assert.match(rendered("outro"), />outro</);
});
