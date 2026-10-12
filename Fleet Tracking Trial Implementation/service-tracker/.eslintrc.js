'use strict';

module.exports = {
    root: true,
    parser: '@babel/eslint-parser',
    parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
        requireConfigFile: false,
        babelOptions: { plugins: [['@babel/plugin-proposal-decorators', { decoratorsBeforeExport: true }]] },
    },
    plugins: ['ember'],
    extends: ['eslint:recommended', 'plugin:ember/recommended'],
    env: { browser: true },
    rules: {
        'ember/no-get': 'off',
        'ember/no-computed-properties-in-native-classes': 'off',
    },
    overrides: [
        {
            files: ['./.eslintrc.js', './.template-lintrc.js', './ember-cli-build.js', './index.js', './config/**/*.js'],
            parserOptions: { sourceType: 'script' },
            env: { browser: false, node: true },
        },
    ],
};
