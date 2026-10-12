const path = require('path');
module.exports = {
    rootDir: path.resolve(__dirname, '..'),
    testEnvironment: 'node',
    roots: ['<rootDir>/__tests__'],
    transform: { '^.+\\.tsx?$': [require.resolve('ts-jest'), { tsconfig: path.resolve(__dirname, 'tsconfig.jest.json'), diagnostics: true }] },
    testMatch: ['**/*.test.ts'],
    moduleDirectories: ['node_modules', path.resolve(__dirname, 'node_modules')],
};
