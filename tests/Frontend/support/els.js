/**
 * O `els` da dashboard, com cada elemento criado à primeira leitura; `tagFor` escolhe a tag
 * pelo nome, e `found` traz os que já existem na marcação do teste.
 */
export function autoEls(tagFor = () => "div", found = {}) {
    return new Proxy(found, {
        get: (target, name) => (typeof name === "string"
            ? (target[name] ??= document.createElement(tagFor(name)))
            : undefined),
    });
}
