## Contents

| Section | What it covers |
|---|---|
| daisyUI 4 color names · How the variables are consumed · color rules · Color utility classes | The 20 semantic color names, the `--p` / `--b1` short-name variables and the `oklch(var(--p)/1)` consumption pattern |
| Border radius utilities | `rounded-box`, `rounded-btn`, `rounded-badge` |
| Theme CSS variables · Component specific CSS variables | Full variable list incl. `--rounded-*`, `--animation-*`, `--btn-focus-scale`, `--tab-*` |
| Built-in themes (32) · Nesting themes | Theme names and `data-theme` nesting |
| Custom theme · Customize an existing theme · Theme-scoped custom styles | Writing themes in `tailwind.config.js` |
| This project's theme (Powerhouse) | The `power` theme and its overrides |

## daisyUI 4 colors

[colors docs](https://v4.daisyui.com/docs/colors/) ·
[themes docs](https://v4.daisyui.com/docs/themes/) ·
[utilities docs](https://v4.daisyui.com/docs/utilities/)

### daisyUI 4 color names

Each color name maps to a short CSS variable. The variable holds **space-separated OKLCH components**
(for example `100% 0 0`), not a full `oklch()` function, so daisyUI can apply Tailwind's opacity
modifier to it.

| Color name | CSS variable | Required in a theme? | Utility example |
|---|---|---|---|
| `primary` | `--p` | required | `bg-primary` |
| `primary-content` | `--pc` | optional — a readable tone of primary if not specified | `bg-primary-content` |
| `secondary` | `--s` | required | `bg-secondary` |
| `secondary-content` | `--sc` | optional — a readable tone of secondary if not specified | `bg-secondary-content` |
| `accent` | `--a` | required | `bg-accent` |
| `accent-content` | `--ac` | optional — a readable tone of accent if not specified | `bg-accent-content` |
| `neutral` | `--n` | required | `bg-neutral` |
| `neutral-content` | `--nc` | optional — a readable tone of neutral if not specified | `bg-neutral-content` |
| `base-100` | `--b1` | required — base color of page, used for blank backgrounds | `bg-base-100` |
| `base-200` | `--b2` | optional — a darker tone of base-100 if not specified | `bg-base-200` |
| `base-300` | `--b3` | optional — a darker tone of base-200 if not specified | `bg-base-300` |
| `base-content` | `--bc` | optional — a readable tone of base-100 if not specified | `bg-base-content` |
| `info` | `--in` | optional — a default blue if not specified | `bg-info` |
| `info-content` | `--inc` | optional — a readable tone of info if not specified | `bg-info-content` |
| `success` | `--su` | optional — a default green if not specified | `bg-success` |
| `success-content` | `--suc` | optional — a readable tone of success if not specified | `bg-success-content` |
| `warning` | `--wa` | optional — a default orange if not specified | `bg-warning` |
| `warning-content` | `--wac` | optional — a readable tone of warning if not specified | `bg-warning-content` |
| `error` | `--er` | optional — a default red if not specified | `bg-error` |
| `error-content` | `--erc` | optional — a readable tone of error if not specified | `bg-error-content` |

### How the variables are consumed

daisyUI 4 registers each color in Tailwind's color palette with a fallback wrapper, so a browser
without OKLCH support still gets a usable hex value:

```css
primary:      var(--fallback-p,   oklch(var(--p)   / <alpha-value>))
base-100:     var(--fallback-b1,  oklch(var(--b1)  / <alpha-value>))
base-content: var(--fallback-bc,  oklch(var(--bc)  / <alpha-value>))
```

The `--fallback-*` variables are defined in an `@supports not (color: oklch(0% 0 0))` block, so they
only take effect on browsers without OKLCH support. Never write `--fallback-*` yourself and never
read `--p` directly with `var(--p)` in CSS — always go through `oklch(var(--p) / 1)`.

### daisyUI 4 color rules

1. daisyUI adds semantic color names to Tailwind CSS colors.
2. daisyUI color names can be used in utility classes, like other Tailwind CSS color names. For
   example, `bg-primary` will use the primary color for the background.
3. daisyUI color names include variables as value so they can change based on the theme.
4. There's no need to use `dark:` for daisyUI color names.
5. Ideally only daisyUI color names should be used for colors so the colors can change automatically
   based on the theme.
6. If a Tailwind CSS color name (like `red-500`) is used, it will be the same red color on all themes.
7. If a daisyUI color name (like `primary`) is used, it will change color based on the theme.
8. Using Tailwind CSS color names for text colors should be avoided because Tailwind CSS color
   `text-gray-800` on `bg-base-100` would be unreadable on a dark theme.
9. `*-content` colors should have a good contrast compared to their associated colors.
10. Use `base-*` colors for the majority of the page. Use the default variant for all elements. Use
    `primary` color once only, for the most important element on the page.
11. Rare exception where a Tailwind CSS color name is allowed: when specific content must be
    independent from the theme, for example an SVG icon or a chart series that must keep an exact
    color no matter the brand or theme colors.

### Color utility classes

Every daisyUI color name works with all Tailwind CSS 3 color utilities, including the opacity
modifier:

```css
bg-{COLOR_NAME}
to-{COLOR_NAME}
via-{COLOR_NAME}
from-{COLOR_NAME}
text-{COLOR_NAME}
ring-{COLOR_NAME}
fill-{COLOR_NAME}
caret-{COLOR_NAME}
stroke-{COLOR_NAME}
border-{COLOR_NAME}
divide-{COLOR_NAME}
accent-{COLOR_NAME}
shadow-{COLOR_NAME}
outline-{COLOR_NAME}
decoration-{COLOR_NAME}
placeholder-{COLOR_NAME}
ring-offset-{COLOR_NAME}

/* opacity modifier */
bg-primary/50
text-base-content/60
```

## Border radius utilities

These theme-aware radius utilities are added by daisyUI and work with every Tailwind CSS radius
side/corner form, for example `rounded-r-box` or `rounded-tr-btn`:

```css
rounded-box     /* var(--rounded-box)    for large components: card, modal, etc. */
rounded-btn     /* var(--rounded-btn)    for medium components: button, input, etc. */
rounded-badge   /* var(--rounded-badge)  for small components: badge, etc. */
```

## Theme CSS variables

These variables are set per theme and can be overridden in a custom theme, or inline with Tailwind
CSS 3 arbitrary property syntax such as `[--animation-btn:0]`:

```css
--rounded-box: 1rem;        /* border radius of rounded-box, used in card and other large boxes */
--rounded-btn: 0.5rem;      /* border radius of rounded-btn, used in buttons and similar elements */
--rounded-badge: 1.9rem;    /* border radius of rounded-badge, used in badges and similar */
--animation-btn: 0.25s;     /* duration of animation when you click on button */
--animation-input: 0.2s;    /* duration of animation for inputs like checkbox, toggle, radio, etc */
--btn-focus-scale: 0.95;    /* scale transform of button when you focus on it */
--border-btn: 1px;          /* border width of buttons */
--tab-border: 1px;          /* border width of tabs */
--tab-radius: 0.5rem;       /* border radius of tabs */
```

## Component specific CSS variables

```css
tab
  --tab-border          /* border width of tab */
  --tab-border-color    /* border color of tab */
  --tab-padding         /* horizontal padding of tab */
  --tab-bg              /* background color of tabs-lifted */
  --tab-radius          /* border radius of tabs-lifted */
  --tab-corner-bg       /* background color of tabs-lifted corner */
  --circle-pos          /* position of circle in the corner of tabs-lifted */
  --tab-grad            /* radial-gradient size in the corner of tabs-lifted */

countdown
  --value               /* value of countdown */

radial-progress
  --value               /* value of progress circle */
  --size                /* size of progress circle */
  --thickness           /* thickness of progress circle */

tooltip
  --tooltip-color              /* background color of tooltip */
  --tooltip-text-color         /* text color of tooltip */
  --tooltip-offset             /* offset of tooltip from target element */
  --tooltip-tail               /* size of tooltip tail */
  --tooltip-tail-offset        /* offset of tooltip tail from target element */

checkbox
  --chkbg               /* background color of checkbox */
  --chkfg               /* foreground color of checkbox */

toggle
  --tglbg               /* background color of toggle */
  --handleoffset        /* offset of toggle handle */

range
  --filler-size         /* size of range thumb */
  --filler-offset       /* offset of range thumb */
  --range-shdw          /* shadow color of range thumb */

glass
  --glass-blur                  /* blur value of glass effect */
  --glass-opacity               /* opacity of glass effect */
  --glass-border-opacity        /* opacity of glass border */
  --glass-reflex-degree         /* degree of glass reflex */
  --glass-reflex-opacity        /* opacity of glass reflex */
  --glass-text-shadow-opacity   /* opacity of text shadow */
```

## Built-in themes

daisyUI 4 ships 32 themes. Enable them in `tailwind.config.js` and activate one with the `data-theme`
attribute:

```js
module.exports = {
  //...
  daisyui: {
    themes: ["light", "dark", "cupcake"],
  },
}
```

```html
<html data-theme="cupcake"></html>
```

The full list:

```js
module.exports = {
  //...
  daisyui: {
    themes: [
      "light",
      "dark",
      "cupcake",
      "bumblebee",
      "emerald",
      "corporate",
      "synthwave",
      "retro",
      "cyberpunk",
      "valentine",
      "halloween",
      "garden",
      "forest",
      "aqua",
      "lofi",
      "pastel",
      "fantasy",
      "wireframe",
      "black",
      "luxury",
      "dracula",
      "cmyk",
      "autumn",
      "business",
      "acid",
      "lemonade",
      "night",
      "coffee",
      "winter",
      "dim",
      "nord",
      "sunset",
    ],
  },
}
```

The default theme is `light` (or `dark` for dark mode), but the first theme in the `themes` array
becomes the default, and `darkTheme` picks the dark-mode theme. Listing only the themes you need
reduces the CSS file size.

Setting `themes: false` keeps only light and dark. Setting `themes: []` includes no themes and
disables all colors.

## Nesting themes

Add `data-theme="THEME_NAME"` to any element and everything inside will use that theme. Themes can be
nested with no depth limit:

```html
<html data-theme="dark">
  <div data-theme="light">
    This div will always use light theme
    <span data-theme="retro">This span will always use retro theme!</span>
  </div>
</html>
```

## Custom theme

Add a new theme as an object inside the `daisyui.themes` array. Only the five required colors are
needed — every other color is generated automatically:

```js
module.exports = {
  //...
  daisyui: {
    themes: [
      {
        mytheme: {
          "primary": "#a991f7",
          "secondary": "#f6d860",
          "accent": "#37cdbe",
          "neutral": "#3d4451",
          "base-100": "#ffffff",
        },
      },
      "dark",
      "cupcake",
    ],
  },
}
```

The first theme (`mytheme`) is the default theme; `dark` is the default for dark mode.

Add the optional design-decision variables to the same object to change radius, animation and border
settings for that theme:

```js
module.exports = {
  //...
  daisyui: {
    themes: [
      {
        mytheme: {
          "primary": "#a991f7",
          "secondary": "#f6d860",
          "accent": "#37cdbe",
          "neutral": "#3d4451",
          "base-100": "#ffffff",

          "--rounded-box": "1rem",
          "--rounded-btn": "0.5rem",
          "--rounded-badge": "1.9rem",
          "--animation-btn": "0.25s",
          "--animation-input": "0.2s",
          "--btn-focus-scale": "0.95",
          "--border-btn": "1px",
          "--tab-border": "1px",
          "--tab-radius": "0.5rem",
        },
      },
    ],
  },
}
```

#### Rules
- Color values can be hex, OKLCH (`oklch(49.12% 0.3096 275.75)`), or any other CSS color format —
  daisyUI converts them to the space-separated OKLCH components it stores in `--p`, `--s`, etc.
- Only `primary`, `secondary`, `accent`, `neutral` and `base-100` are required. Supply the optional
  ones only when the generated value is not good enough.
- If you're generating a custom theme, do not include the explanatory comments. Just provide the code.

## Customize an existing theme

Spread a built-in theme and override only what needs to change. All other values are inherited:

```js
module.exports = {
  //...
  daisyui: {
    themes: [
      {
        light: {
          ...require("daisyui/src/theming/themes")["light"],
          primary: "blue",
          secondary: "teal",
        },
      },
    ],
  },
}
```

## Theme-scoped custom styles

Custom CSS for one theme:

```css
[data-theme="mytheme"] .btn {
  border-width: 2px;
  border-color: black;
}
```

Or declare the rules inside the theme object itself — here `.btn-twitter` only gets this style on the
`light` theme:

```js
module.exports = {
  //...
  daisyui: {
    themes: [
      {
        light: {
          ...require("daisyui/src/theming/themes")["light"],
          ".btn-twitter": {
            "background-color": "#1EA1F1",
            "border-color": "#1EA1F1",
          },
          ".btn-twitter:hover": {
            "background-color": "#1C96E1",
            "border-color": "#1C96E1",
          },
        },
      },
    ],
  },
}
```

There is a visual theme generator at <https://v4.daisyui.com/theme-generator/>.

## This project's theme (Powerhouse)

`tailwind.config.cjs` defines a custom `power` theme as the first entry, so it is the default:

```js
daisyui: {
  themes: [
    {
      power: {
        ...require('daisyui/src/theming/themes')['light'],
        primary: '#1677ff',
        'primary-content': '#ffffff',
        secondary: '#2db7f5',
        'secondary-content': '#ffffff',
      },
    },
    'light', 'dark', 'cupcake', /* … 26 more built-in themes … */
  ],
  prefix: 'pc-', // prefix for daisyUI classnames (components, modifiers and responsive class names. Not colors)
}
```

29 of the 32 built-in themes are enabled. `acid`, `lemonade` and `coffee` are **not** in this
project's config — do not emit `data-theme="acid"`, `data-theme="lemonade"` or `data-theme="coffee"`.

- Use `bg-primary` / `text-primary-content` and the other semantic names — never hard-code `#1677ff`.
- Color and radius utilities are **not** affected by `prefix: 'pc-'`.
- The plugin's own Theme system (`J7\Powerhouse\Theme`) can override these variables at runtime —
  including a "follow Blocksy" mode that reads the Blocksy palette and converts it to OKLCH before
  writing the daisyUI color variables. Generated markup must therefore rely on semantic color names
  so it keeps working when those variables change.
