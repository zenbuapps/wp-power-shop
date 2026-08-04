### bottom-navigation
Bottom navigation bar allows navigation between primary screens

[bottom-navigation docs](https://v4.daisyui.com/components/bottom-navigation/)

#### Class names
- component: `btm-nav`
- part: `btm-nav-label`
- behavior: `active`, `disabled`
- size: `btm-nav-xs`, `btm-nav-sm`, `btm-nav-md`, `btm-nav-lg`

#### Syntax
```html
<div class="btm-nav {SIZE}">
  <button>
    {icon}
    <span class="btm-nav-label">Home</span>
  </button>
  <button class="active">
    {icon}
    <span class="btm-nav-label">Search</span>
  </button>
  <button disabled>
    {icon}
  </button>
</div>
```

#### Rules
- The component class name in daisyUI 4 is `btm-nav` — the abbreviated form. Do not derive a class name from the page title: it is not `bottom-nav`, and it is not the name this component was renamed to in a later major version
- {SIZE} is optional and can have one of the size class names
- The active and disabled class names are the bare words `active` and `disabled` on the child element — they are NOT prefixed with `btm-nav-`
- `btm-nav` is `position: fixed` at the bottom of the page and spans the full width
- Direct children are usually `<button>` or `<a>` elements; each can hold an icon and a `btm-nav-label`
- Color a single item with text/background utilities on the child, for example `class="text-primary active"`
