# Changelog

All notable changes to `bumpcore/json-patch` will be documented in this file.

## [Unreleased]

## [0.1.1] - 2026-05-21

### Changed

- Improve README package documentation and usage guidance.
- Add Composer author metadata with the developer role.
- Set `0.x` as the default release branch.

## [0.1.0] - 2026-05-21

### Added

- Add RFC 6902 JSON Patch support with `JsonPatch::apply()` and `JsonPatch::applyJson()`.
- Add deterministic JSON Patch generation with `JsonPatch::diff()` and `JsonPatch::diffJson()`.
- Add RFC 6901 JSON Pointer parsing, escaping, and document helper APIs.
- Add RFC 7396 JSON Merge Patch support with `JsonMergePatch::apply()` and `JsonMergePatch::applyJson()`.
- Add package-specific exceptions with optional operation, path, and source-pointer context.
- Add upstream `json-patch/json-patch-tests` fixture parity coverage.
- Add PHPUnit, PHPStan level 9, PHP CS Fixer, GitHub Actions, and a 100% coverage gate.

[Unreleased]: https://github.com/bumpcore/json-patch/compare/v0.1.1...0.x
[0.1.1]: https://github.com/bumpcore/json-patch/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/bumpcore/json-patch/releases/tag/v0.1.0
