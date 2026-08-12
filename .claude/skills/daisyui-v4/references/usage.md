## Contents

- **usage rules** · **Class name categories** — how to combine component / part / modifier classes
- **Responsive class names** — daisyUI 4 emits responsive variants for modifiers (`sm:btn-md`)
- **Form fields: `form-control` and `label`** · **Borders are opt-in on form fields** — the v4 form pattern
- **Deprecated components** — `btn-group`, `input-group` and their `join` replacements
- **Customizing a component in CSS** · **utilities and variables** · **Skeleton (unstyled) mode**
- **Prefix reminder for this project** — the `pc-` rule

## daisyUI 4 usage rules

[use guide](https://v4.daisyui.com/docs/use/) · [customize guide](https://v4.daisyui.com/docs/customize/)

1. We can give styles to an HTML element by adding daisyUI class names to it: a component class name,
   part class names (if there are any available for that component), and modifier class names (if
   there are any available for that component).
2. Instead of building a button out of utility classes:

```html
<button
  class="inline-block cursor-pointer rounded-md bg-gray-800 px-4 py-3 text-center text-sm font-semibold uppercase text-white transition duration-200 ease-in-out hover:bg-gray-900">
  Button
</button>
```

   use the component class:

```html
<button class="btn">Button</button>
```

3. Then modify the component with daisyUI's own modifier classes:

```html
<button class="btn btn-primary">Button</button>
```

4. Or modify the component with Tailwind CSS utility classes when daisyUI has no matching modifier:

```html
<button class="btn w-64 rounded-full">Button</button>
```

5. If customization with Tailwind CSS utility classes fails because of CSS specificity, use
   Tailwind CSS 3's `!` **prefix** (`!bg-red-500`, `!p-0`). In Tailwind CSS 3 the important marker
   goes at the **start** of the utility, not the end.
6. If a specific component (or something similar) doesn't exist in daisyUI, build it from Tailwind
   CSS utility classes.
7. When using Tailwind CSS `flex` and `grid` for layout, make it responsive with Tailwind CSS
   responsive prefixes.
8. Only allowed class names are existing daisyUI 4 class names or Tailwind CSS 3 utility classes.
9. Ideally you won't need to write any custom CSS. Using daisyUI class names or Tailwind CSS utility
   classes is preferred.
10. Suggested — if you need placeholder images, use `https://picsum.photos/200/300` with the size you
    want.
11. Suggested — when designing, don't add a custom font unless it's necessary.
12. Don't add `bg-base-100 text-base-content` to body unless it's necessary.
13. Always use the default variant of daisyUI components unless the user asked for a specific variant
    or color. For example when you need a button, prefer `btn` over `btn btn-primary`.

## Class name categories

daisyUI 4 class names fall into the categories below. These type names are only for reference in
this skill and are not used in the actual code.

- `component`: the required component class
- `part`: a child part of a component
- `style`: sets a specific style to component or part
- `behavior`: changes the behavior of component or part
- `color`: sets a specific color to component or part
- `size`: sets a specific size to component or part
- `placement`: sets a specific placement to component or part
- `direction`: sets a specific direction to component or part
- `modifier`: modifies the component or part in a specific way
- `variant`: prefixes for utility classes that conditionally apply styles. syntax is `variant:utility-class`

## Responsive class names (a daisyUI 4 specific)

daisyUI 4's `utils: true` config generates responsive variants for a subset of class names — sizes,
directions and placements. Those class names can be used with Tailwind CSS breakpoint prefixes:

```html
<button class="btn btn-xs sm:btn-sm md:btn-md lg:btn-lg">Responsive</button>
<ul class="menu menu-vertical lg:menu-horizontal">…</ul>
<div class="drawer lg:drawer-open">…</div>
<div class="card lg:card-side">…</div>
<div class="divider lg:divider-horizontal">OR</div>
<ul class="steps steps-vertical lg:steps-horizontal">…</ul>
<div class="stats stats-vertical lg:stats-horizontal">…</div>
<div class="join join-vertical lg:join-horizontal">…</div>
<dialog class="modal modal-bottom sm:modal-middle">…</dialog>
```

Each component doc marks which class names are responsive in its `#### Rules` section. Do not assume
a breakpoint prefix works on a class that is not marked responsive.

## Form fields: `form-control` and `label`

daisyUI 4 has no dedicated form-group component. Form fields are wrapped with `form-control` and
labelled with `label` + `label-text` / `label-text-alt`. These four class names have no page of their
own in the docs — they appear in the class tables of checkbox, input, select, textarea, file-input,
radio and toggle.

- `form-control` — `display: flex; flex-direction: column;` wrapper for one field
- `label` — a flex row that spreads its children apart (`justify-between`), used for helper text
- `label-text` — the main label text
- `label-text-alt` — the secondary/alt label text (smaller)

```html
<label class="form-control w-full max-w-xs">
  <div class="label">
    <span class="label-text">What is your name?</span>
    <span class="label-text-alt">Top Right label</span>
  </div>
  <input type="text" placeholder="Type here" class="input input-bordered w-full max-w-xs" />
  <div class="label">
    <span class="label-text-alt">Bottom Left label</span>
    <span class="label-text-alt">Bottom Right label</span>
  </div>
</label>
```

```html
<div class="form-control">
  <label class="label cursor-pointer">
    <span class="label-text">Remember me</span>
    <input type="checkbox" checked="checked" class="checkbox" />
  </label>
</div>
```

## Borders are opt-in on form fields

In daisyUI 4 the plain `input`, `select`, `textarea` and `file-input` classes render **without** a
border. Add the `-bordered` modifier when a visible border is wanted:
`input-bordered`, `select-bordered`, `textarea-bordered`, `file-input-bordered`.
`card-bordered` does the same for cards.

## Deprecated components

`btn-group` and `input-group` still work in 4.12.x but are marked deprecated in the official docs and
will be removed in the next major version. Use [join](./components/join.md) instead for new code.

[button-group docs](https://v4.daisyui.com/components/button-group/) ·
[input-group docs](https://v4.daisyui.com/components/input-group/)

- `btn-group` (component), `btn-group-horizontal` (direction, default), `btn-group-vertical` (direction)
- `input-group` (component), `input-group-vertical` (direction),
  `input-group-lg` / `input-group-md` / `input-group-sm` / `input-group-xs` (size)

```html
<div class="btn-group">
  <button class="btn btn-active">Button</button>
  <button class="btn">Button</button>
</div>
```

```html
<label class="input-group">
  <span>Email</span>
  <input type="text" placeholder="info@site.com" class="input input-bordered" />
</label>
```

## Customizing a component in CSS

Use Tailwind CSS 3's `@apply` directive in your CSS file:

```css
.btn {
  @apply rounded-full;
}
```

Or scope the override to one theme:

```css
[data-theme="mytheme"] .btn {
  border-width: 2px;
  border-color: black;
}
```

## daisyUI 4 utilities and variables

- Semantic colors work with Tailwind color utilities and opacity modifiers, for example
  `bg-primary`, `border-base-300`, and `text-base-content/60`.
- `rounded-box`, `rounded-btn` and `rounded-badge` use the active theme's radius variables, and work
  with every Tailwind radius side/corner form (`rounded-r-box`, `rounded-tr-btn`).
- `glass` applies the daisyUI matte-glass effect to any element.
- `no-animation` disables the click animation on a `btn`.
- Both `glass` and `no-animation` are shipped in daisyUI 4's **unprefixed** utilities bundle, so they
  stay `glass` and `no-animation` even when a daisyUI `prefix` is configured. (Verified against
  `daisyui@4.12.24`: `dist/utilities.js` is the one bundle the prefix transform does not touch.)
- Theme variables can be overridden inline with Tailwind CSS 3 arbitrary property syntax, e.g.
  `[--animation-btn:0]`, `[--tab-bg:yellow]`, `[--tooltip-color:red]`.
- Components expose their own CSS variables — `--value` on countdown, `--value` / `--size` /
  `--thickness` on radial-progress, `--tab-bg` / `--tab-border-color` on tab, `--tglbg` on toggle.
  See [./colors.md](./colors.md) for the full list.

## Skeleton (unstyled) mode

Setting `styled: false` in the daisyUI config strips daisyUI's colors and design decisions and leaves
only the structural CSS, so you can design your own look on top of the component skeletons. See
[./config.md](./config.md).

## Prefix reminder for this project

`tailwind.config.cjs` sets `daisyui.prefix: 'pc-'`. Every component/part/modifier/responsive class
name in `./components/` must be written with the `pc-` prefix in this project's code:

```html
<button class="pc-btn pc-btn-primary pc-btn-sm">Button</button>
<div class="pc-card pc-card-compact pc-card-bordered bg-base-100 rounded-box">
  <div class="pc-card-body">…</div>
</div>
```

Not prefixed:

- colors — `bg-primary`, `text-base-content`, `border-base-300`
- radius utilities — `rounded-box`, `rounded-btn`, `rounded-badge`
- `glass` and `no-animation`

Everything else is prefixed, including bare-word class names that belong to a component:
`swap-on` becomes `pc-swap-on`, `card-body` becomes `pc-card-body`, `active` inside a `pc-menu`
becomes `pc-active`, `hover` on a `pc-table` row becomes `pc-hover`, and `lg:card-side` becomes
`lg:pc-card-side`.
