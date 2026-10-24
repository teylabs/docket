# Roadmap

Docket 1.0 means: any Laravel app can start work that outlives a request, hand the caller a ticket, and show truthful progress, per-item outcomes, cancel and retry from any surface (JSON, Vue, React, Blade, Livewire, the console), with access checked on every read and every run deleted on schedule. Its public API and JSON contract are frozen and documented.

**Versions are milestones, not dates.**

## Shipped

- [x] **[v0.1 — Runs you can come back to](https://github.com/teylabs/docket/milestone/1).** The run model, dispatch and inline runs, in-job reporting, run trees with roll-up, cancel and retry, staleness and prune, testing fakes, package routes with policy authorization, and the v1 JSON status contract. 0.1.1 fixed PostgreSQL migration ordering.

## The countdown to 1.0

- [ ] **[v0.2 — Surfaces](https://github.com/teylabs/docket/milestone/2).** Tenant-scoped run access, prune on separate schedules, progress components for Vue, React, Blade and Livewire plus console progress, and warn-only loop signals.
- [ ] **[v0.3 — External workers](https://github.com/teylabs/docket/milestone/3).** Capability mode for accountless runs, signed writer updates from workers outside PHP, and the optional Concierge integration.
- [ ] **[1.0 — Stable](https://github.com/teylabs/docket/milestone/4).** A frozen API and JSON contract, a versioning and support policy, complete docs, and a release candidate.
- [ ] **[1.x](https://github.com/teylabs/docket/milestone/5).** After 1.0, in no particular order.
