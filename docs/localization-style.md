# CrozzTube language contract

The interface speaks to one person: friendly, direct, helpful and precise. German uses **du, dir, dich, dein** with appropriate verbs. Use lower-case pronouns within a sentence. Avoid forced familiarity, jokes in errors, blame and technical implementation detail that does not help the person act.

The approved German terminology is **Creator, Playlist, Kanal, Abonnieren**. Other languages use consistent, idiomatic equivalents; keep Creator or Playlist in English only where that is natural usage. Shorts is the product/format name and must never be translated as clothing. Use **Vorschaubild**, **Untertitel**, **Kapitel**, **Aufrufe**, **Livestream**, **Konto**, **Guthaben** and **Einstellungen** consistently. Translate “review” as **Prüfen** when checking settings, not as a product review. Translate “stream health” as **Streamstatus**, and “pre-flight” as **Startprüfung**, not aviation terminology. Apply friendly, slightly casual community language to every locale, without forced slang or mechanical pronoun substitution.

## Scope and ownership

Cover navigation, forms, player controls, accessibility labels, loading/empty/error/success states, administration, default help pages, API messages and default emails. Preserve names and content supplied by users or providers. Preserve administrator-authored page and email overrides; localized defaults must not overwrite them. Preserve product/configuration identifiers and functional meaning.

All regional variants use personal address and retain regional spelling and writing systems, including Swiss **ss**. The owner approved removing address-style duplicates on 2026-11-06: `de_de_formal` → `de_de`, `nl_nl_formal` → `nl_nl`, `de_ch_informal` → `de_ch`. Originals and customized settings are privately archived. Public legacy requests resolve to the canonical pack only if that target is enabled; administrator writes to retired variants are rejected. Regional languages and simplified/traditional scripts remain selectable.

The owner also approved replacing the absolute third-party privacy claim with a neutral, linked privacy-policy reference. The German text is: **Wie wir mit deinen Daten umgehen, erfährst du in unserer Datenschutzerklärung.** Link to `/privacy` and preserve the meaning in every translation. Other legal meaning must remain intact.

## Writing and verification

- Actions name the result: **Dateien auswählen**, **Video importieren**, **Änderungen speichern**.
- A form prompt can use an imperative: **Gib deinem Video einen Titel.** Avoid unnecessary possessives in short labels.
- An error explains the failure and a useful next action when known: **Die Videodetails konnten nicht geladen werden. Versuch es erneut.** Never invent an upstream cause or recovery promise.
- A success message confirms only what actually completed: **Dein Video wurde importiert.** Distinguish saved, processing, ready and published.
- Translate complete sentences. Preserve `{name}`, `{count}`, email tokens, links and numbers. Keep grammatical alternatives explicit through plural categories instead of concatenating translated words.
- A word identical to English is a review candidate, not proof of a missing translation. Brands, formats and identifiers are legitimate matches. Conversely, an English value under every key is not a completed translation.
- Unknown meaning is a question for the owner. Uncertain language quality belongs in a review queue, not a claimed “100% translated” result.

## Repeatable checks

`node tools/i18n/audit.mjs --output /tmp/crozztube-i18n.json` reads all PHP packs and extracts application UI strings, without database or network writes. The JSON includes concrete issues and source locations; the console reports totals. Structural checks cannot certify naturalness or factual accuracy.

The reference language and corrected locales need a second contextual reading plus focused browser verification. Track unresolved English, mixed-language text, copied plural/pattern data, placeholder damage and missing UI keys separately. New text changes should not add untracked regressions.

## Review workflow

1. Run the audit and inspect the actual source locations and complete interaction. The inventory covers JSX text, literal accessibility/form attributes, message calls, label properties and conditional JSX labels. It is conservative: imported content and sample data can appear as candidates; PHP/API/email sources need an additional contextual review.
2. Export the reference with `docker exec crozztube-web php tools/i18n/export.php`. Keep the JSON outside the repository. Write a reviewed JSON map of complete source messages to translations. Never auto-fill an entire language from isolated words.
3. Preview a batch with `php tools/i18n/apply-reviewed.php --locale=CODE --translations=PATCH --reference=EXPORT` inside the application container. The files must be accessible inside the container. Add `--apply` only after review. Changed reference values cause a conflict; inspect them rather than replacing the reference to force a write.
4. Validate PHP syntax and run `node --test tools/test/localization.test.mjs`, `docker exec crozztube-web php tools/test/php/localization-checks.php` and the focused browser tests in `tools/test/ui/specs/localization.spec.ts`. Build changed frontend code before browser verification.
5. Run the audit again. `--check` fails for damaged placeholders, empty values and leaked markers. It does **not** certify that all messages are translated or linguistically reviewed. Read review candidates separately.
6. Record the reviewed flows and remaining questions in the change description. A language progresses from inventoried → structurally checked → linguistically reviewed → checked in context. Regional reuse and technical repairs do not skip these stages.

## Initial review, 2026-11-05

All 128 installed catalogs were inventoried and structurally checked. Confirmed leaked terminology markers were repaired in 120 catalogs. The normal German variants (`de_de`, `de_at`, `de_ch`, `de_ch_informal`) received a first editorial pass covering personal address, common controls, imports, authentication, support, administration and live features. This is not a claim that every string in these variants has been reviewed. Explicitly formal variants retain their existing semantics pending the owner's decision.

German import messages and standard email subjects/bodies have complete reviewed translations. The renderer now uses localized email defaults when there is no applicable administrator override; existing override precedence remains intact. English catalogs are fetched too, so corrections to English defaults and regional variants are actually honored. Time phrases use supported `Intl` formatters; German count forms use named plural categories. Other languages still need editorial review of their words and remaining plural metadata.

Confirmed browser coverage includes narrow German import forms, subtitle/chapter counts, English↔German switching, retained form values, URLs, and video/channel content that matches UI keys. RTL layout is checked separately from Arabic wording quality. Remaining work includes uncatalogued UI messages, copied English or mixed-language text in other packs, default help/API content, additional complete flow reviews and owner decisions about formal variants, terminology and the privacy claim. Never label these outstanding areas “complete” based on the structural audit.

## Approved migration and review state, 2026-11-06

The owner's decisions above supersede the pending questions in the historical initial review. There are now 125 regional packs. `App\Support\LanguageLocales` is the shared source of the three retired-code aliases. Website/API catalogs expose `aliases`; legacy public reads respect the canonical pack's enabled status, and retired-code administration writes return 422. `tools/i18n/retire-address-variants.php` privately archives old packs and customized settings before removing selectable duplicates. Never publish that archive or its administrator data.

`tools/i18n/php-inventory.php` adds API/controller messages, exceptions, plugin labels, emails and built-in help definitions to the JavaScript inventory. Every candidate has source locations. A sample name, configuration example or provider description must be classified before counting it as an untranslated interface message. Imported content and author-written overrides are not translation sources.

Reviewed batches live in `tools/i18n/batches/`. Apply them with `node tools/i18n/apply-batch.mjs BATCH.json` for a preview, then `--apply` after reading each complete message in context. Regional overrides beat language-family values. The native PHP writer verifies its pre-edit snapshot and placeholders, preserves file ownership and writes atomically. Do not use family reuse as a substitute for checking regional wording.

`tools/i18n/reviews/CODE.json` records a hash of each source and translation, a concrete review context and separate `editorial` / `verified` states. Pattern and plural entries are tracked too. Missing records stay pending. Changing either text invalidates its prior review. `audit.mjs --strict` requires context verification of every dictionary, pattern and plural entry, and rejects uncatalogued UI candidates. It intentionally does not currently pass. `--check` remains a structural check only.

Use `tools/test/ui/verify-community-copy.mjs --record` for the complete login privacy sentence and `tools/test/ui/verify-community-import.mjs --record` for the reviewed import messages in loading, populated and empty states. These commands verify the actual built UI in isolated, read-only contexts before updating only the observed message records. They do not certify whole languages. Explicit `t()` output must be excluded from the legacy DOM translation bridge so it cannot be translated a second time or lose punctuation/spacing. Server-resolved page content and administrator navigation labels are also excluded from that bridge; surrounding controls remain translated.

The current implementation includes further English/German reference batches, 85 built-in help messages, personal French/Dutch messages, initial Russian/Czech/Korean/Chinese corrections, and Latin-language/Russian plural metadata. The complete privacy link has browser coverage in 83 regional packs. Import loading, subtitle/chapter results and empty states have coverage in 25 regional packs. These are message-level milestones, not acceptance of the remaining catalog entries. Unreviewed English, other formal imperatives, uncertain terminology, all remaining language groups and additional contextual reviews remain open.

Additional focused checks: `docker exec crozztube-web php tools/test/php/locale-alias-checks.php` and `docker exec crozztube-web php tools/test/php/localized-messages-checks.php`. Run Playwright from the installed test package (`node tools/test/ui/node_modules/playwright/cli.js test localization.spec.ts --config=tools/test/ui/playwright.config.ts --project=chromium`) to avoid resolving a different runner version.

## Identical terms and register checks

The following can legitimately remain identical where the locale uses them: product/provider names such as CrozzTube, PlayHub, YouTube, Rumble, Odysee, BitChute, Stripe, PayPal, LiveKit and KLIPY; format/protocol identifiers such as MP4, WebM, HLS, SRT, VTT, CUE, JSON, API, HTTP, HTTPS, RTMP, SMTP and CDN; and the approved product terms Creator and Playlist. This is a terminology guide, not a blanket exemption for English sentences or unreviewed entries. URLs, code identifiers, placeholders and author-supplied names remain literal.

The audit flags likely formal address in German, Dutch, French, Spanish, Italian, Polish, Czech, Slovak, Russian, Ukrainian, Chinese, Japanese and Korean. These patterns are review candidates, not proof: plural address and references to another person need context. Portuguese `você`, normal Japanese polite verb endings and Korean everyday `해요` style can be natural personal language. Do not replace them mechanically with slang or a less appropriate register. Review formal imperatives even when no explicit pronoun is present; absence of a detector hit does not certify tone.

For a readable per-message worklist, run `node tools/i18n/review-queue.mjs --locale=de_de --status=pending --audit=/tmp/crozztube-i18n.json --limit=50` after generating that audit. Each row includes the locale, complete English reference, current translation, source/translation hashes, source locations, review contexts and current status. Missing keys remain explicitly missing. The audit also flags likely customer/formal address in further European, Asian and RTL language families; every hit still needs a human contextual decision.

## Continued implementation and owner decisions, 2026-11-06

The owner explicitly authorized three parallel language reviewers. Their disjoint batches are under `rest-romance-*`, `rest-european-*` and `rest-asian-rtl-*`; English/German reference and integration batches use `rest-reference-*`. All three parallel reviewers later reached the service usage limit. Their written batches and completed checks are retained. A stopped reviewer is not evidence of a completed language.

Shorts are **at most 60 seconds and strictly portrait** (`height > width`). This replaces the former 180-second/square rule across upload validation, actual worker-probed media, import classification, API/feed queries and interface text. 9:16 can be recommended, but other portrait ratios qualify. A legacy flag cannot make a known long, square or landscape video a Short.

The owner now plans to remove RTL support in a **future** change because there is no demand. Do not expand RTL work as a target of this task. This decision does not authorize deleting existing regional catalogs or user preferences now. The already confirmed mobile focus correction remains: clipping the root horizontally avoids programmatic focus scrolling into an offscreen drawer. No future removal has been carried out in this pass.

### Complete messages and content ownership

- Use `LocalizedCount` / `useI18n().count()` for actual React quantities, including labels whose number and noun would otherwise be separate DOM nodes. Complete `count_*`, `ago_*` and `duration_*` patterns carry word order, classifiers, plural forms and grammatical case. Extra locale-specific CLDR categories refer to the generic English pattern for source review, not invented English forms.
- `resources/localization/locale-tags.json` is the shared exception map between stable catalog codes and registered BCP47 tags. For example, `pt_pt_ao90` uses `pt-PT-ao1990` for Intl, HTML and emails, while preferences/API catalog identities remain compatible. The tag is documented in the [IANA language subtag registry](https://www.iana.org/assignments/language-subtag-registry/language-subtag-registry). Unsupported Intl locales still require authored whole patterns; never select a guessed neighbouring language.
- `brandedProjectText()` retains the original project message key before replacing PlayHub with the configured site name. Do not apply it to author/provider text. A copied historical English value falls back to the current English reference in both frontend and backend.
- Vidstack layouts receive their translation dictionary directly. The DOM bridge leaves their dynamic controls, captions, chapter cues and track names alone, including portalled layouts/tooltips. The installed Vidstack version leaves only its static font-family/shadow radio labels untranslated; the bridge handles those two explicit UI selectors. Never broaden this exception to caption or audio track labels. Its Play button deliberately has a stable accessible toggle name; test `aria-pressed` and the changing tooltip rather than expecting its name to change.
- Transcript UI labels, loading/error/retry messages and accessible names are translated explicitly; the transcript body remains literal. Names, comments, mentions, tags and administrator-authored category/playlist names remain protected, including native and React-select options and upload review values.
- Monetary support and advice have different message keys. Use complete messages such as `Send a tip to {name}`, `Support {name} during this live stream.` and `Playlist tip`; do not assemble a sentence from `Send`, `Tip` and a user name.
- Registration countdown, strength validation and chapter validation use complete named messages. Password feedback describes the actual ASCII ranges counted by the existing strength algorithm (A–Z, a–z and 0–9). This pass does not change the password algorithm.
- Default code emails use an idiomatic invariant minute unit or a unit-neutral construction so 1, 2 and 15 all read correctly. Renderer substitution alone does not prove grammar. English uses `min.`, German `Min.`. Existing administrator templates remain literal and authoritative.

### Verified milestones and remaining acceptance

The complete privacy link has recorded narrow-browser coverage in **99 regional packs**. The six default email templates have renderer coverage across **80 regional packs**, including all English/German references, the reviewed Romance group, 31 European regions and 16 Asian/RTL regions. This checks expiry 1/2/15, literal tokens, escaping and temporary administrator override precedence without sending mail. It does not certify unrelated catalog entries or every email client.

Additional actual browser checks cover import loading/error/recovery and captions/chapters; chapter editing and provider/manual timing rules; complete count and duration grammar; real video-card/watch counters; provider content that matches UI keys; community comments/mentions; player controls, caption/track/chapter content, font choices and theater mode; branded registration copy and retained form drafts; native and desktop selection controls. Tests record only observed messages. Do not infer whole-language acceptance from these flows.

Focused reproducible checks added in this pass include `tools/test/php/localization-writer-checks.php`, `shorts-policy-checks.php`, `localized-password-strength-checks.php`, `localized-reference-emails.php`, `tools/test/ui/rest-reference-rendering.mjs` and `rest-reference-taxonomy.mjs`. Use the group-specific email and browser checks alongside them. `tools/test/ui/strict-https.mjs` still verifies Chromium/Firefox with certificate checking enabled; integrated-browser trust was checked separately without reloading the user's draft.

**Overall acceptance remains open.** Many unreviewed dictionary messages, uncatalogued inventory candidates, stale reference reviews and whole-context passes remain. The source inventory also contains legacy sample names, code/examples and unresolved sentence fragments that require classification; do not count them as either translated UI or accepted exclusions without reading their source. Rare-language questions are recorded in `tools/i18n/batches/rest-asian-rtl-native-questions.json`; that file is a question register, not an applyable translation batch. The owner has no native-reviewed wording to supply. Public primary references have resolved some short terms, but uncertain complete phrases remain pending. No whole language is accepted merely because structural checks pass.

The final reference integration also fills the complete installed player vocabulary in English/German, including previously uncatalogued accessibility, caption, playback and casting labels. Real German and British-English player tests observe 26 labels each, font choices, stable toggle state/tooltips and literal provider chapters/tracks. AU/GB/NZ/ZA and Canadian spelling differences are explicitly retained in regional overrides; a shared English reference batch must not erase `Colour`, `Favourites`, `Centre` or the region's accepted -ise/-ize choices. Treat older batches as review history; do not bulk replay obsolete wording over newer accepted changes.
