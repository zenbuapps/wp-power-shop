---
name: daisyui-v4
description: daisyUI v4 (4.12.x) component library skill for Tailwind CSS 3 projects. TRIGGER when generating HTML/JSX for a project whose package.json has daisyui ^4.x. Do NOT use for daisyUI 5 / Tailwind CSS 4 projects — this project does not ship a daisyUI 5 skill.
when_to_use: >
  Use whenever generating any HTML or JSX in this project, or for any Tailwind CSS 3 UI work.
  Trigger on daisyUI, component, UI, Tailwind, layout, template, theme, color, design, btn, card,
  modal, navbar, drawer, menu, badge, alert, tabs, the pc- class prefix, tailwind.config.cjs,
  or edits to js/src/**/*.tsx and *.scss. Trigger even if the user does not explicitly ask for it.
metadata:
  version: "4.12.x"
  source: https://v4.daisyui.com/
---

# daisyUI 4

daisyUI 4 is a CSS library for Tailwind CSS 3.
daisyUI 4 provides class names for common UI components, semantic color names and themes.

## Version check — read this before writing any code

> daisyUI 4 runs on Tailwind CSS 3 and is configured in `tailwind.config.js`. daisyUI 5 runs on Tailwind CSS 4 and is configured in CSS via `@plugin "daisyui"`. Check `package.json` before generating code. If the project has `daisyui: ^5`, stop — this skill does not apply, and no daisyUI 5 skill is installed here.

The line above is the **only** place in this skill that mentions daisyUI 5. Everything else in this
skill is daisyUI 4 only. Class names changed a lot between 4 and 5 — never mix them, and never
"remember" a class name from daisyUI 5 while working in a daisyUI 4 project.

| | daisyUI 4 (this skill) | daisyUI 5 (not installed here) |
|---|---|---|
| Tailwind CSS | 3.x | 4.x |
| Config location | `tailwind.config.js` → `daisyui: {}` | CSS file |
| Plugin registration | `plugins: [require("daisyui")]` | CSS `@plugin` at-rule |
| Theme color variables | short names: `--p`, `--b1`, `--bc` | long names |
| Theme radius variables | `--rounded-box`, `--rounded-btn`, `--rounded-badge` | different names |

## This project's daisyUI setup (Powerhouse)

`package.json` has `daisyui: ^4.12.23` + `tailwindcss: ^3.4.4`, so **this skill applies**.
`tailwind.config.cjs` sets some options that change how you must write class names:

1. **`daisyui.prefix: 'pc-'`** — every daisyUI **component / part / modifier / responsive** class
   must be written with the `pc-` prefix.
   Write `pc-btn`, `pc-btn-primary`, `pc-card-body`, `lg:pc-menu-horizontal` —
   not `btn`, `btn-primary`, `card-body`, `lg:menu-horizontal`.
   All the class names listed in `./references/components/` are written **without** the prefix (that is how the
   official docs list them); add `pc-` yourself when writing code for this project.
2. **Colors, border-radius utilities, `glass` and `no-animation` are NOT prefixed.**
   Colors and radius are added as Tailwind CSS extensions and `glass` / `no-animation` ship in
   daisyUI 4's unprefixed utilities bundle, so the daisyUI `prefix` option does not touch any of
   them: `bg-primary`, `text-base-content`, `border-base-300`, `rounded-box`, `rounded-btn`,
   `rounded-badge`, `glass`, `no-animation`.

   ⚠️ **`glass` on a button needs BOTH forms.** The base `.glass` rule lives in the unprefixed
   `dist/utilities` bundle, but the button-specific overrides (`.btn.glass`, `.btn.glass:hover`,
   `.btn.glass.btn-active`) live in `dist/styled`, which the prefixer *does* rewrite — they become
   `.pc-btn.pc-glass`. So a glass button in this project must carry both class names:
   `<button class="pc-btn glass pc-glass">`. Plain (non-button) glass elements only need `glass`.
3. **`important: '#tw'`** — Tailwind CSS **utilities** are emitted as `#tw .flex { … }`,
   `#tw .bg-primary { … }`. Utilities therefore only apply inside an element with `id="tw"`.
   daisyUI **component** classes (`.pc-btn`, `.pc-menu`, …) are emitted unscoped and work anywhere,
   but since almost every component also needs utilities for layout, keep the markup inside `#tw`.
4. **`corePlugins.preflight: false`** — there is no Tailwind reset. Browser/WordPress default styles
   for `h1`–`h6`, `ul`, `p`, `button`, `table`, etc. are still present. Do not assume margins,
   list-style or font-size have been zeroed out; set them explicitly when it matters.
5. **Custom theme `power`** is the first entry of `daisyui.themes`, so it is the default theme.
   It spreads `require('daisyui/src/theming/themes')['light']` and overrides `primary` (`#1677ff`),
   `primary-content`, `secondary` (`#2db7f5`) and `secondary-content`. **29** of daisyUI 4's 32
   built-in themes are also enabled and can be applied with `data-theme="…"`.
   ⚠️ `acid`, `lemonade` and `coffee` are NOT in this project's `themes` array —
   `data-theme="acid"` silently does nothing. Add them to `tailwind.config.cjs` first if needed.
6. **`blocklist`** removes 10 utilities that clash with WordPress. Replacements:

   | Blocked | Use instead |
   |---|---|
   | `hidden` `block` `inline` `fixed` `blur` `columns-1` `columns-2` | `tw-hidden` `tw-block` `tw-inline` `tw-fixed` `tw-blur` `tw-columns-1` `tw-columns-2` |
   | `container` | `tw-container` |
   | `rtl` | `right-to-left` (custom utility, **no** `tw-` prefix) |
   | `size-full` | **no replacement** — write `w-full h-full` |

## Mandatory reference

| Task | Guide | Note |
|------|-------|------|
Installing daisyUI 4 | [./references/install.md](./references/install.md) | Use only if daisyUI is not already installed in the project.
Using daisyUI class names | [./references/usage.md](./references/usage.md) | MANDATORY. Read this before using any daisyUI class names in the code.
Configuring daisyUI | [./references/config.md](./references/config.md) | Use this if you need to configure daisyUI themes, prefix, logs, or other options. Not required for basic usage but important for advanced customization.
daisyUI colors and themes | [./references/colors.md](./references/colors.md) | MANDATORY. Read this to understand daisyUI color usage rules and how to use daisyUI colors in the code.
daisyUI components | [./references/components/](./references/components/) | MANDATORY. Read the relevant component docs when using daisyUI components in the code. Always read multiple candidate component docs before deciding which one to use.

## List of components

daisyUI 4 has 55 components in 7 groups.

### Actions
- [button](./references/components/button.md)
- [dropdown](./references/components/dropdown.md)
- [modal](./references/components/modal.md)
- [swap](./references/components/swap.md)
- [theme-controller](./references/components/theme-controller.md)

### Data display
- [accordion](./references/components/accordion.md)
- [avatar](./references/components/avatar.md)
- [badge](./references/components/badge.md)
- [card](./references/components/card.md)
- [carousel](./references/components/carousel.md)
- [chat (chat bubble)](./references/components/chat.md)
- [collapse](./references/components/collapse.md)
- [countdown](./references/components/countdown.md)
- [diff](./references/components/diff.md)
- [kbd](./references/components/kbd.md)
- [stat](./references/components/stat.md)
- [table](./references/components/table.md)
- [timeline](./references/components/timeline.md)

### Navigation
- [breadcrumbs](./references/components/breadcrumbs.md)
- [bottom-navigation (`btm-nav`)](./references/components/bottom-navigation.md)
- [link](./references/components/link.md)
- [menu](./references/components/menu.md)
- [navbar](./references/components/navbar.md)
- [pagination](./references/components/pagination.md)
- [steps](./references/components/steps.md)
- [tab](./references/components/tab.md)

### Feedback
- [alert](./references/components/alert.md)
- [loading](./references/components/loading.md)
- [progress](./references/components/progress.md)
- [radial-progress](./references/components/radial-progress.md)
- [skeleton](./references/components/skeleton.md)
- [toast](./references/components/toast.md)
- [tooltip](./references/components/tooltip.md)

### Data input
- [checkbox](./references/components/checkbox.md)
- [file-input](./references/components/file-input.md)
- [radio](./references/components/radio.md)
- [range (range slider)](./references/components/range.md)
- [rating](./references/components/rating.md)
- [select](./references/components/select.md)
- [input (text input)](./references/components/input.md)
- [textarea](./references/components/textarea.md)
- [toggle (switch)](./references/components/toggle.md)

### Layout
- [artboard](./references/components/artboard.md)
- [divider](./references/components/divider.md)
- [drawer (sidebar)](./references/components/drawer.md)
- [footer](./references/components/footer.md)
- [hero](./references/components/hero.md)
- [indicator](./references/components/indicator.md)
- [join (group)](./references/components/join.md)
- [mask](./references/components/mask.md)
- [stack](./references/components/stack.md)

### Mockup
- [mockup-browser](./references/components/mockup-browser.md)
- [mockup-code](./references/components/mockup-code.md)
- [mockup-phone](./references/components/mockup-phone.md)
- [mockup-window](./references/components/mockup-window.md)

### Deprecated in daisyUI 4

`btn-group` and `input-group` still ship in 4.12.x but are marked deprecated in the official docs and
are removed in the next major version. Prefer [join](./references/components/join.md) for new code.
Their full class lists and syntax are in [./references/usage.md](./references/usage.md#deprecated-components);
[button](./references/components/button.md) and [input](./references/components/input.md) each carry a pointer rule.

### Component discovery protocol

Before writing any daisyUI code, do this in order:

1. Read the request intent, behavior, and shape, not only literal words. Match on meaning.
2. Use the component list in this file to shortlist the best candidate components.
3. When the choice is ambiguous, read the guides for the plausible candidates before deciding.
4. Compare each candidate's description, behavior, syntax, and rules against the request.
5. Select the best component or combination of components and apply their constraints exactly.
6. Apply the chosen components' structure and constraints exactly.
7. Add the project's `pc-` prefix to every daisyUI component/part/modifier/responsive class.

Semantic matching is required even when wording differs from component names. A component name might be different from the request but still be the best match. Always consider intent and meaning, not only literal words.

If a user explicitly requests a named component and a same-named doc exists, read that component doc first.

Some daisyUI 4 components have no dedicated page — they are only documented inside other component
pages. Do not invent replacements for them:

- `form-control`, `label`, `label-text`, `label-text-alt` — the form field wrapper and its labels.
  Documented in [checkbox](./references/components/checkbox.md), [input](./references/components/input.md),
  [select](./references/components/select.md), [textarea](./references/components/textarea.md),
  [file-input](./references/components/file-input.md), [radio](./references/components/radio.md),
  [toggle](./references/components/toggle.md).
- `glass` and `no-animation` — global modifiers, see [button](./references/components/button.md) and
  [./references/usage.md](./references/usage.md).
- `rounded-box`, `rounded-btn`, `rounded-badge` — theme-aware radius utilities, see
  [./references/colors.md](./references/colors.md).
