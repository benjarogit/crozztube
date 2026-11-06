---
name: crozztube-localization
description: Correct CrozzTube interface copy and language packs, including missing translations, personal address, terminology, dynamic messages and language quality checks. Use for CrozzTube localization work; preserve creator content and administrator-authored pages or email overrides.
---

Read [the language contract](../../../docs/localization-style.md) before editing. Use the existing PHP catalogs under `lang/`; the UI currently uses English messages as keys. A changed key needs corresponding catalog updates, not only a rewritten JSX literal.

Run `node tools/i18n/audit.mjs --output /tmp/crozztube-i18n.json` to inventory all installed packs and visible UI messages. Distinguish missing keys, untranslated English, placeholder damage, fragmentary translations and uncertain wording. Counts of existing keys alone do not establish completeness. Names and technical terms that are identical in several languages are not automatically defects.

Review whole messages against the actual UI state and controller behavior. Every language uses personal, friendly, community-oriented address, never a formal customer register. German uses informal singular address with matching verb forms. Translate other languages idiomatically; do not map pronouns mechanically. Preserve regional spelling and writing systems. The owner explicitly approved retiring address-style duplicates: de_de_formal → de_de, nl_nl_formal → nl_nl, de_ch_informal → de_ch. Use the shared alias resolver and preserve archived originals; never reintroduce selectable formal variants.

Do not translate user names, video titles/descriptions, comments, imported captions or provider-supplied chapters. Do not replace tokens, URLs, product names, technical identifiers or placeholders with translated approximations. Existing administrator overrides remain authoritative.

Keep complete dynamic messages with named placeholders and locale-aware number/plural formatting. Preserve unknown placeholders visibly rather than silently deleting them. Use `Intl` locale support only when it is available; do not claim an unsupported language is covered by a guessed neighboring language.

Work from a reviewed reference, then handle language families in reviewable batches. Reuse a translation across regional variants only after checking the existing variant and its terminology. Technical repairs to known leaked term markers are allowed only when the source confirms the protected term. A language needing linguistic review remains marked as such, even when all structural checks pass.

Validate changed catalogs with PHP, run the focused localization checks and inspect complete browser flows in the changed locales, including narrow screens, loading/errors, plural counts and RTL where relevant. Keep the user's shared browser draft intact. Record remaining work honestly; never present machine-generated or merely structurally complete packs as reviewed.

Ask the owner about concrete ambiguous meaning, conflicting product terminology or changes to legal meaning. Continue independent work while awaiting answers. No external translation service, paid integration or delegation is authorized merely by using this skill.

Use the shared catalog-to-BCP47 mapping in `resources/localization/locale-tags.json` for registered language tags without renaming stable catalog identities. Preserve orthography variants; do not fall back to a different Portuguese region when formatting `pt_pt_ao90`.

For Vidstack, pass component translations directly and keep provider captions/chapters/track labels outside the DOM bridge. The only narrow bridge exception is the installed component's static font-family/shadow option labels. Verify toggle state and tooltip behavior against the installed component rather than guessing its accessible-name contract. Use complete count/time patterns on actual React nodes, not just synthetic text fixtures.

The owner has deferred removal of RTL support to a future task. Do not expand RTL work or delete current language packs as part of this decision. Keep uncertain native wording pending; a service usage limit stopping a reviewer does not complete their language group.

English reference changes must preserve regional spelling overrides too. Review whole affected messages; AU/GB/NZ/ZA and Canada do not share every US spelling. Historical batches are evidence of review, not a command to replay obsolete text over newer approved wording.
