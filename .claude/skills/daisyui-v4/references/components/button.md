### button
Buttons allow the user to take actions or make choices

[button docs](https://v4.daisyui.com/components/button/)

#### Class names
- component: `btn`
- color: `btn-neutral`, `btn-primary`, `btn-secondary`, `btn-accent`, `btn-info`, `btn-success`, `btn-warning`, `btn-error`
- style: `btn-outline`, `btn-ghost`, `btn-link`, `glass`
- behavior: `btn-active`, `btn-disabled`, `no-animation`
- size: `btn-lg`, `btn-md`, `btn-sm`, `btn-xs`
- modifier: `btn-wide`, `btn-block`, `btn-circle`, `btn-square`

#### Syntax
```html
<button class="btn {MODIFIER}">Button</button>
```

Disabled:
```html
<button class="btn" disabled="disabled">Disabled using attribute</button>
<button class="btn btn-disabled" tabindex="-1" role="button" aria-disabled="true">
  Disabled using class name
</button>
```

#### Rules
- {MODIFIER} is optional and can have one of each color/style/behavior/size/modifier class names
- btn can be used on any html tag such as `<button>`, `<a>`, `<input>`
- btn can have an icon before or after the text
- `btn-disabled` only changes the look. When disabling with the class name instead of the `disabled` attribute, also set `tabindex="-1" role="button" aria-disabled="true"`
- `btn-lg`, `btn-md`, `btn-sm`, `btn-xs`, `btn-wide`, `btn-block`, `btn-circle` and `btn-square` are responsive, so `btn-xs sm:btn-sm md:btn-md lg:btn-lg` works
- `glass` gives the button a matte glass effect, `no-animation` disables the click animation
- For a loading button put `<span class="loading loading-spinner"></span>` inside the btn
- `btn-group` exists in daisyUI 4 but is deprecated — use `join` and `join-item` instead
