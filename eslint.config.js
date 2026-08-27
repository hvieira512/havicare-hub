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
        files: ["src/Dashboard/main.js", "src/Dashboard/dashboard/**/*.js", "tests/Frontend/**/*.js"],
        plugins: style.plugins,
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: {
                ...globals.browser,
                ...globals.node,
                bootstrap: "readonly",
                Swal: "readonly",
                am5: "readonly",
                am5xy: "readonly",
                am5themes_Animated: "readonly",
            },
        },
        rules: {
            ...js.configs.recommended.rules,
            ...style.rules,
            // Duas regras onde o default do preset ia contra o codigo: as aspas nas
            // chaves so onde sao precisas -- o `consistent-as-needed` obrigava a
            // cita-las todas por causa de um `"device.connected"` -- e o operador no
            // fim da linha, menos o `?` e o `:` do ternario, que este codigo poe
            // sempre no inicio.
            "@stylistic/quote-props": ["error", "as-needed"],
            "@stylistic/operator-linebreak": ["error", "after", {overrides: {"?": "before", ":": "before"}}],
            // Ligado como aviso e nao erro: os imports nao usados e os exports sem
            // chamadores eram invisiveis, e foi assim que o codigo morto se acumulou --
            // o teste do grafo de modulos so prova que cada ficheiro e alcancavel, nao
            // que cada nome importado e usado. Os argumentos ficam de fora porque as
            // assinaturas dos handlers de eventos nao usam sempre o `event`.
            "no-unused-vars": ["warn", {args: "none"}],
            "no-empty": ["error", {allowEmptyCatch: true}],
        },
    },
];
