## Contents

- **Config** — where the `daisyui: {}` block lives in `tailwind.config.js`
- **Config values explained** — `styled` · `themes` · `base` · `utils` · `logs` · `darkTheme` · `prefix` · `themeRoot`
- **Example config** — a full annotated block
- **Tailwind CSS 3 `dark:` variant for a daisyUI theme**
- **This project's config (Powerhouse)** — the actual `tailwind.config.cjs` settings

## Config

daisyUI 4 config docs: <https://v4.daisyui.com/docs/config/>

daisyUI 4 is configured from the `tailwind.config.js` file — never from CSS.

daisyUI without config (defaults apply):

```js
module.exports = {
  //...
  plugins: [require("daisyui")],
}
```

daisyUI with all the default configs written out explicitly:

```js
module.exports = {
  //...

  // add daisyUI plugin
  plugins: [require("daisyui")],

  // daisyUI config (optional - here are the default values)
  daisyui: {
    themes: false, // false: only light + dark | true: all themes | array: specific themes like this ["light", "dark", "cupcake"]
    darkTheme: "dark", // name of one of the included themes for dark mode
    base: true, // applies background color and foreground color for root element by default
    styled: true, // include daisyUI colors and design decisions for all components
    utils: true, // adds responsive and modifier utility classes
    prefix: "", // prefix for daisyUI classnames (components, modifiers and responsive class names. Not colors)
    logs: true, // Shows info about daisyUI version and used config in the console when building your CSS
    themeRoot: ":root", // The element that receives theme color CSS variables
  },

  //...
}
```

## Config values explained

### `styled`

`Boolean (default: true)`

If it's true, components will have colors and style so you won't need to design anything.
If it's false, components will have no color and no visual style so you can design your own style on
a basic skeleton.

### `themes`

`Boolean or array (default: false)`

If it's true, all themes will be included.
If it's false, only light and dark themes will be available.
If it's an array, only themes in the array will be included and **the first theme will be the default
theme**.
An empty array (`[]`) includes no themes and disables all colors.
See [./colors.md](./colors.md) for theme definitions.

### `base`

`Boolean (default: true)`

If it's true, a few base styles will be added — most importantly `background-color: base-100` and
`color: base-content` on `:root` and on every `[data-theme]` element.

### `utils`

`Boolean (default: true)`

If it's true, responsive and utility classes will be added. This is what generates the responsive
variants of size/direction/placement class names (`lg:menu-horizontal`, `sm:btn-sm`, …) plus
`glass`, `rounded-box`, `rounded-btn`, `rounded-badge`.

### `logs`

`Boolean (default: true)`

If it's true, daisyUI shows logs in the terminal while CSS is building.

### `darkTheme`

`String (default: "dark")`

Allows us to pick another theme for the system's auto dark mode. By default, the `dark` theme (or a
custom theme named `dark`) will be the default theme if no theme is specified and the user is using
dark mode on their system. With this config, you can set another theme to be the default dark mode
theme.

### `prefix`

`String (default: "")`

Adds a prefix to the class name for all daisyUI classes (including component classes, modifier
classes and responsive classes). For example: `btn` will become `prefix-btn`.
If you're using a second CSS library that has similar class names, you can use this config to avoid
conflicts.
Utility classes like color names (e.g. `bg-primary`) or border-radius (e.g. `rounded-box`) will
**not** be affected by this config because they're being added as extensions to Tailwind CSS classes.
If you use the daisyUI `prefix` option (like `daisy-`) and the Tailwind CSS `prefix` option (like
`tw-`) together, classnames will be prefixed like this: `tw-daisy-btn`.

### `themeRoot`

`String (default: ":root")`

Which element to attach the theme CSS variables to.
In certain situations (such as embedding daisyUI in a shadow root) it may be useful to set this to
e.g. `*`, so all components will have access to the required CSS variables.

## Example config

All built-in themes enabled, `bumblebee` as the default (first in the array), `synthwave` as the dark
mode theme, `daisy-` prefix, logs off:

```js
module.exports = {
  //...
  plugins: [require("daisyui")],
  daisyui: {
    themes: [
      "bumblebee",
      "light",
      "dark",
      "cupcake",
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
    darkTheme: "synthwave",
    prefix: "daisy-",
    logs: false,
  },
}
```

## Tailwind CSS 3 `dark:` variant for a daisyUI theme

daisyUI can be configured to use Tailwind's `dark:` selector. Set Tailwind's `darkMode` to the
selector strategy pointing at the theme you designate as dark. In this example `winter` and `night`
are enabled and `night` is the dark theme:

```js
module.exports = {
  content: ["./src/**/*.{astro,html,svelte,vue,js,ts,jsx,tsx}"],
  plugins: [require("daisyui")],
  theme: {},
  daisyui: {
    themes: ["winter", "night"],
  },
  darkMode: ["selector", '[data-theme="night"]'],
}
```

## This project's config (Powerhouse)

`tailwind.config.cjs`:

```js
module.exports = {
  important: '#tw',
  corePlugins: { preflight: false },
  content: [
    './js/src/**/*.{js,ts,jsx,tsx}',
    './inc/**/*.{php,js,ts,jsx,tsx}',
    '../power-*/js/src/**/*.{js,ts,jsx,tsx}',
    '../power-*/inc/**/*.{php,js,ts,jsx,tsx}',
  ],
  plugins: [require('daisyui') /* … project plugins … */],
  blocklist: [
    'hidden', 'columns-1', 'columns-2', 'fixed', 'block', 'inline',
    'blur', 'size-full', 'container', 'rtl',
  ],
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
      'light', 'dark', 'cupcake', 'bumblebee', 'emerald', 'corporate',
      'synthwave', 'retro', 'cyberpunk', 'valentine', 'halloween', 'garden',
      'forest', 'aqua', 'lofi', 'pastel', 'fantasy', 'wireframe', 'black',
      'luxury', 'dracula', 'cmyk', 'autumn', 'business', 'night', 'winter',
      'dim', 'nord', 'sunset',
    ],
    prefix: 'pc-', // prefix for daisyUI classnames (components, modifiers and responsive class names. Not colors)
  },
}
```

Consequences you must respect when generating code:

- `prefix: 'pc-'` → write `pc-btn`, `pc-card-body`, `lg:pc-menu-horizontal`
- colors and radius utilities stay unprefixed → `bg-primary`, `rounded-box`
- `important: '#tw'` → Tailwind utilities only apply inside an element with `id="tw"`
- `preflight: false` → no CSS reset; browser/WordPress defaults still apply
- `power` is first in `themes`, so it is the default theme
- the blocked utilities have `tw-`-prefixed replacements added by a local plugin
- there is a second config, `tailwind.config.front.cjs`, for the front-end CSS pipeline
