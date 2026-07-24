# Changelog

All notable changes to `bumpcore/json-patch` will be documented in this file.

## [Unreleased]

## [0.2.0] - 2026-07-24

### Changed

- Rework value copying around engine copy-on-write: arrays are shared structurally and only `stdClass` nodes are cloned, removing whole-document and per-step deep copies from apply, diff, pointer, and merge operations. Pointer-heavy and multi-operation workloads run orders of magnitude faster, and deep diffs drop from quadratic to linear time and memory.
- Make patch generation single-pass with a strict-equality fast path, so equal subtrees compare at engine speed and structurally shared subtrees compare in constant time.
- Skip redundant validation and isolation for freshly decoded input in `JsonPatch::applyJson()`, `JsonPatch::diffJson()`, and `JsonMergePatch::applyJson()`.
- Scope `JsonPointer::get()` and `JsonPointer::has()` validation to the subtree they traverse and return instead of the whole document.
- Replace regex pointer-token parsing with `strspn()` checks and escape guards, removing the implicit `ctype` extension dependency.
- Raise static analysis to PHPStan level 10 on PHPStan 2.2.

### Added

- Add tests pinning result isolation: returned documents never share objects with inputs, and `copy` operations never alias inside the result.
- Add a linear-memory regression bound for diffing deeply nested documents.

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

[Unreleased]: https://github.com/bumpcore/json-patch/compare/v0.2.0...0.x
[0.2.0]: https://github.com/bumpcore/json-patch/compare/v0.1.1...v0.2.0
[0.1.1]: https://github.com/bumpcore/json-patch/compare/v0.1.0...v0.1.1
[0.1.0]: https://github.com/bumpcore/json-patch/releases/tag/v0.1.0
