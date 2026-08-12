## daisyUI 4 install notes

[install guide](https://v4.daisyui.com/docs/install/)

1. daisyUI 4 requires **Node.js** and **Tailwind CSS 3**. It is a Tailwind CSS plugin, so Tailwind
   CSS must already be installed and working.
2. daisyUI 4 is configured from `tailwind.config.js` (or `tailwind.config.cjs`). This file is
   required — daisyUI 4 has no CSS-based configuration.
3. Install daisyUI 4 as a dev dependency. Pin the major version so a v5 install cannot happen by
   accident:

```bash
npm i -D daisyui@v4
```

```bash
pnpm add -D daisyui@v4
```

```bash
yarn add -D daisyui@v4
```

```bash
bun add -d daisyui@v4
```

4. Add daisyUI to the `plugins` array in `tailwind.config.js`:

```js
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: ["./src/**/*.{html,js,jsx,ts,tsx,vue,svelte,php}"],
  plugins: [require("daisyui")],
}
```

5. The CSS entry file is a normal Tailwind CSS 3 entry file — daisyUI needs nothing extra there:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

6. If `@tailwindcss/typography` is also used, **require `daisyui` after it** so daisyUI can restyle
   `prose` with the active theme:

```js
module.exports = {
  //...
  plugins: [require("@tailwindcss/typography"), require("daisyui")],
}
```

### CDN

[CDN guide](https://v4.daisyui.com/docs/cdn/)

CDN files are not recommended for production because unused styles cannot be purged and the file
size will be large. For a no-build browser setup, add the precompiled daisyUI 4 CSS plus the
Tailwind CSS 3 browser script to the `head` tag:

```html
<link href="https://cdn.jsdelivr.net/npm/daisyui@4.12.24/dist/full.min.css" rel="stylesheet" type="text/css" />
<script src="https://cdn.tailwindcss.com"></script>
```

### Framework examples

The official docs link example repositories that show a working daisyUI 4 + Tailwind CSS 3 setup for
each framework and build tool (Next.js, Nuxt, SvelteKit, Astro, Remix, Vite, Laravel, Rails, Angular,
Solid, Qwik, and more). See <https://v4.daisyui.com/docs/install/>. Follow the selected repo's file
paths and integration steps instead of assuming every framework uses the same CSS entry.

### This project (Powerhouse)

daisyUI 4 is already installed here — do not reinstall or upgrade it.

- `package.json`: `"daisyui": "^4.12.23"`, `"tailwindcss": "^3.4.4"`
- Config file: `tailwind.config.cjs` (CommonJS, because `package.json` has `"type": "module"`)
- A second config, `tailwind.config.front.cjs`, is used for the front-end CSS pipeline
- CSS is built with the Tailwind CLI, not through Vite:
  `pnpm build-css:admin`, `pnpm build-css:front`, `pnpm watch-css:admin`
