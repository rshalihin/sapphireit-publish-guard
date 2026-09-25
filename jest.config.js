/**
 * Jest configuration for the editor panel's unit tests (`npm run test:unit:js`).
 *
 * Transforms go through babel.config.js (babel-jest), so tests compile exactly like the
 * build, JSX runtime included.
 */

module.exports = {
	rootDir: __dirname,
	testEnvironment: 'jsdom',
	testMatch: [ '<rootDir>/tests/js/**/*.test.js' ],
	testPathIgnorePatterns: [ '/node_modules/', '/vendor/', '/build/' ],
};
