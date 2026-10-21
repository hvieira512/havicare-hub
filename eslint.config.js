import js from "@eslint/js";
import stylistic from "@stylistic/eslint-plugin";
import globals from "globals";

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
        // Todo o JavaScript da dashboard: em flat config um ficheiro sem config não tem regras.
        files: ["src/Dashboard/**/*.js", "tests/Frontend/**/*.js"],
        plugins: style.plugins,
        languageOptions: {
            ecmaVersion: "latest",
            sourceType: "module",
            globals: {
                ...globals.browser,
                bootstrap: "readonly",
                Swal: "readonly",
                // Carregados a pedido pelo modal da planta do radar, e não no `<head>`.
                Konva: "readonly",
            },
        },
        rules: {
            ...js.configs.recommended.rules,
            ...style.rules,
            "@stylistic/quote-props": ["error", "as-needed"],
            "@stylistic/operator-linebreak": ["error", "after", {overrides: {"?": "before", ":": "before"}}],
            // Os argumentos ficam de fora pelos handlers que não usam o `event`.
            "no-unused-vars": ["error", {args: "none"}],
            "no-empty": ["error", {allowEmptyCatch: true}],
        },
    },
    {
        files: ["tests/Frontend/**/*.js"],
        languageOptions: {globals: globals.node},
    },
];
