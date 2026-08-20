# Contributing

Thanks for helping build WP Static Secure.

## Before contributing

Please read:

- `README.md` for the project mission;
- `ARCHITECTURE.md` for trust boundaries and design direction;
- `SECURITY.md` for security reporting;
- `AGENTS.md` for implementation and review expectations.

## What makes a good contribution

The project favors focused changes that improve one concrete behavior without expanding the public attack surface unnecessarily.

Good contributions include:

- reproducible static-export bugs;
- URL normalization and rewriting fixes;
- asset-discovery fixes;
- compatibility fixtures from real WordPress output;
- regression tests;
- form adapters with clear boundaries;
- documentation improvements;
- deterministic build and validation improvements.

## Issues

For a bug, please include:

- WordPress version;
- relevant theme/plugin information;
- source URL shape with private values anonymized;
- expected output;
- actual output;
- minimal HTML or fixture when possible;
- steps to reproduce.

For compatibility requests, include a reproducible example. "Support plugin X" without a concrete failure mode may remain lower priority until there is a test case.

## Security issues

Do not open a public issue for an exploitable security vulnerability. Follow `SECURITY.md`.

## Pull requests

Keep PRs narrow. A PR should explain:

1. the problem;
2. the approach;
3. tests performed;
4. security or trust-boundary implications;
5. limitations and follow-up work.

Bug fixes should include regression tests where practical.

Avoid unrelated formatting/refactoring changes in the same PR.

## Design discussions

Architectural changes should begin with an issue before substantial implementation, especially changes involving:

- public-to-private connectivity;
- new dynamic APIs;
- authentication or authorization;
- credentials/secrets;
- deployment deletion or synchronization;
- submission storage;
- a new provider-specific dependency.

## Compatibility philosophy

The project will not promise universal WordPress compatibility.

Compatibility is accepted incrementally through fixtures, tests, and real-world reports. We would rather support a known subset reliably than silently produce broken static output.

## Open-source philosophy

The goal is for the open-source project to remain useful on its own. Contributions should not add artificial limitations whose primary purpose is to force users toward a commercial product.
