# CrozzTube interface contract

Use the configured product name, logo, font, accent palette and light/dark tokens. This is an existing video product, not a marketing landing page. Media screens let the previews lead; settings and administration use restrained, familiar controls.

- Navigation B: docked sidebar at 1280 CSS pixels and above, overlay drawer below. Retain the desktop collapse preference. Viewer bottom navigation remains through 860px. Administration has its own destinations with the same docking threshold.
- `responsiveLayout.js` owns navigation mode. Shells expose `data-navigation-mode`. The sidebar's measured/configured width and content inset agree; drawer state never overwrites the desktop preference.
- Content containers own column changes. Video grids have 240px minimum tracks or one full-width track on narrower screens. Content max-width is 1680px. Use 4/8px spacing increments and existing type tokens with readable rem sizes.
- Mobile home feature: preview, title limited to two lines, primary watch action. Omit settled empty trend rails. Controls and labels must survive long text and theme changes.
- Settings use a labeled compact section selector below 900px content width. Management lists turn into cards below 1000px content width. Comparison tables may scroll locally while identity/actions stay reachable.
- Modals and drawers have explicit focus, Escape, focus return, background isolation and viewport-bounded scrolling. Touch actions are at least 44px; reduced motion keeps clear state feedback.
- Preserve mounted forms, media elements and their state through responsive changes. Resize must not reload playback or discard inputs.

Impeccable 4.8.0 is vendored under `.agents/skills/impeccable`, from `pbakaus/impeccable` commit `c8891a9ae865d6ee311836ae5199bfce0fe115b1`. Use audit/adapt/harden/polish playbooks against rendered evidence. Local engine caches and screenshot artifacts are not project source.
