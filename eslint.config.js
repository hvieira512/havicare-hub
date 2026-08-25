import js from "@eslint/js";
import globals from "globals";

export default [
    {
        files: ["src/Dashboard/dashboard/**/*.js", "tests/Frontend/**/*.js"],
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
