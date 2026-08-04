### menu
Menu is used to display a list of links vertically or horizontally

[menu docs](https://v4.daisyui.com/components/menu/)

#### Class names
- component: `menu`
- part: `menu-title`, `menu-dropdown`, `menu-dropdown-toggle`
- behavior: `active`, `disabled`, `focus`, `menu-dropdown-show`
- size: `menu-xs`, `menu-sm`, `menu-md`, `menu-lg`
- direction: `menu-vertical`, `menu-horizontal`

#### Syntax
Vertical menu:
```html
<ul class="menu {MODIFIER} bg-base-200 rounded-box w-56">
  <li><a>Item 1</a></li>
  <li><a class="active">Item 2</a></li>
  <li class="disabled"><a>Item 3</a></li>
</ul>
```

Horizontal menu:
```html
<ul class="menu menu-horizontal bg-base-200 rounded-box">
  <li><a>Item 1</a></li>
  <li><a>Item 2</a></li>
</ul>
```

Collapsible submenu:
```html
<ul class="menu bg-base-200 rounded-box w-56">
  <li>
    <details open>
      <summary>Parent</summary>
      <ul>
        <li><a>Submenu 1</a></li>
        <li><a>Submenu 2</a></li>
      </ul>
    </details>
  </li>
</ul>
```

#### Rules
- {MODIFIER} is optional and can have one of the size class names and one of the direction class names
- The state class names in daisyUI 4 are the bare words `active`, `disabled` and `focus` — they are NOT prefixed with `menu-`
- `active` and `focus` go on the element INSIDE the `<li>` (the `<a>` or `<button>`); `disabled` goes on the `<li>` itself
- `menu` must be a `<ul>` and every item must be an `<li>`
- The direction and size class names are responsive, so `menu-vertical lg:menu-horizontal` works
- Use `menu-title` on an `<li>` for a section title, or wrap a `<span class="menu-title">` plus a nested `<ul>` for a titled group
- Use `<details>` and `<summary>` inside an `<li>` for a collapsible submenu, or `menu-dropdown-toggle` + `menu-dropdown` + `menu-dropdown-show` to drive it from JS
- The menu has no background of its own — add `bg-base-200` and `rounded-box` when it needs one
