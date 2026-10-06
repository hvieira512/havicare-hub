import js from "@eslint/js";
import stylistic from "@stylistic/eslint-plugin";
import globals from "globals";

// As opções do preset são as que o código já seguia na esmagadora maioria dos sítios:
// 4 espaços, aspas duplas, ponto e vírgula, parênteses sempre no argumento da arrow.
const style = stylistic.configs.customize({
    indent: 4,
    quotes: "double",
    semi: true,
    braceStyle: "1tbs",
    arrowParens: true,
    commaDangle: "always-multiline",
});

export default [
    {
        // Todo o JavaScript da dashboard, e não uma lista de pastas: em flat config um
        // ficheiro sem config correspondente é analisado sem regra nenhuma.
        files: ["src/Dashboard/**/*.js", "tests/Frontend/**/*.js"],
        plugins: style.plugins,
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: {
                ...globals.browser,
                ...globals.node,
                bootstrap: "readonly",
                Swal: "readonly",
                // Carregados a pedido pelo modal da planta do radar, e não no `<head>`.
                Konva: "readonly",
                am5: "readonly",
                am5xy: "readonly",
                am5themes_Animated: "readonly",
            },
        },
        rules: {
            ...js.configs.recommended.rules,
            ...style.rules,
            // Duas regras onde o default do preset ia contra o código: aspas nas chaves só onde são
            // precisas, e o operador no fim da linha, menos o `?` e o `:` do ternário.
            "@stylistic/quote-props": ["error", "as-needed"],
            "@stylistic/operator-linebreak": ["error", "after", {overrides: {"?": "before", ":": "before"}}],
            // Aviso e não erro: apanha imports e exports sem uso, que o teste do grafo de módulos não vê.
            // Os argumentos ficam de fora pelos handlers que não usam o `event`.
            "no-unused-vars": ["warn", {args: "none"}],
            "no-empty": ["error", {allowEmptyCatch: true}],
        },
    },
];
