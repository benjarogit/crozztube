# Bounded stable-series tooling authorization

Owner authorization: 15 November 2026, explicit response “Genau diesen begrenzten
Patch freigeben” to the concrete 16-path proposal. This records the scope of the
authorization, not successful publication or runtime activation.

Reviewed patch SHA-256:
`d0259b0d7b4266f41c36efe1408588cd214f4fe55960dfe1dac0c9e3ccb941e6`.
Tooling baseline: `544b702620f3955c60e7414dcc1fe93f0791fa44`.
Feature base remains that exact commit; existing fifth canonical merge remains
`d302272ee27e69482301981f258186d722f1795e`. No sixth merge is authorized.

Authorized implementation is the stable-series helper/CLI/policy, its adapter in
series-maintenance, actual PR-base bindings in audit/lifecycle/Project Manager,
positive/negative tests, mandatory local/CI test inclusion and corresponding
CORE/README instructions. This approval record is the additional documentation
described in the concrete proposal. Existing source/index/secret/hook/review/CI/
publication gates, legacy path, historical anchors and acceptance rules remain.

The integrated draft passed 188 Node tests. Separate reviewers inspected helper,
adapter, CLI, policy and test integration; the integrating reviewer inspected the
other author's PR-base changes. Fixtures use isolated Git/CI/API data and are not
live runtime or provider evidence. The final full run included the unchanged
secret-scan configuration and existing scanner missing from the initial scratch
fixture. No tests were removed or weakened to address that fixture error.

Actual activation still requires ordinary tooling verification, reviewed staged
paths, commit/confirmed push, exact-head hosted CI and fresh independent PR
evidence before explicit private runtime acceptance. An immutable release record
is not continued readiness of a later changed GitHub PR base. Full actual-source
and runtime results belong in the existing private journal and PR evidence.

No main merge, PR retarget, extra parent integration, deployment, Hook/Trust
change, plan/progress edit, step-8 completion, step-9 start or Orqen integration
is authorized. A changed selected feature base remains separately controlled.

## Separately authorized actual-target-ref follow-up

Owner response: “Sieben-Dateien-Zusatzpatch freigeben”, 15 November 2026.
Exact seven-file patch SHA-256:
`09fc7e9991849891e1a341471a0808bbe478c86b5f0e87a9aba521b9fc00561e`,
based on published `0e2a00a89e6e752b0b1d08ad6b5e01edaa386ee9`.
The seven paths are ChatDev pr-audit, github-lifecycle, project-manager and CLI,
their existing PR/manager tests and the stable-base test fixtures. This record
is the additional documentation explicitly included in that approval.

Actual observation: PR4's Pull payload still reported base544b while its target
Git ref reported0e2a00a. Green CI43727104064 checked the older merge627d5c57.
PR4 was never declared READY; its incomplete language/draft acceptance remained.
PR3's main payload and actual Git ref both reported80ed7e3, so the previously
observed acceptance of0e2a00a remains a historical fact under that exact code.
The quality finding was recorded as CHANGES_REQUIRED before the follow-up.

The correction independently checks the exact current commit ref before/after
collection and immediately before each issue mutation. Missing, changed or
inconsistent refs fail closed. Old READY records lacking ref evidence are not
upgraded. Read-only inventory remains available with an explicit blocker. A
later reconciliation disagreement lowers the live CLI and PM result together;
historical collector snapshots remain unchanged and CHANGES_REQUIRED is never
upgraded. Authoritative harness and all prior source/index/secret/hook/review/
CI/publication rules remain intact. No helper, selected-base policy or historical
merge anchor is changed by this correction.

The integrated draft passed202/202 Node tests, including85 focused cases and
five independently executed reviewer regressions. All seven file hashes and
published baselines were independently checked. Canonical bundle SHA-256:
`dcd7f257c5b52937c3c4a7c99e4650fc153f3a3add3a1751cdd4ae604d163019`
(sorted relative path + NUL + raw bytes + NUL). Actual source verification,
publication, CI, new private runtime acceptance and the feature checkpoint still
require their separate real results. The feature base remains544b and exactly
five integrations; no further parent integration or main merge is authorized.
