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
            "no-unused-vars": "off",
            "no-empty": ["error", {allowEmptyCatch: true}],
        },
    },
];
