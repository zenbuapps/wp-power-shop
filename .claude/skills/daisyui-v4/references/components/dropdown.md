### dropdown
Dropdown can open a menu or any other element when the button is clicked

[dropdown docs](https://v4.daisyui.com/components/dropdown/)

#### Class names
- component: `dropdown`
- part: `dropdown-content`
- placement: `dropdown-end`, `dropdown-top`, `dropdown-bottom`, `dropdown-left`, `dropdown-right`
- behavior: `dropdown-hover`, `dropdown-open`

#### Syntax
Method 1 — using details and summary:
```html
<details class="dropdown">
  <summary class="btn m-1">open or close</summary>
  <ul class="menu dropdown-content bg-base-100 rounded-box z-[1] w-52 p-2 shadow">
    <li><a>Item 1</a></li>
    <li><a>Item 2</a></li>
  </ul>
</details>
```

Method 2 — using CSS focus:
```html
<div class="dropdown {MODIFIER}">
  <div tabindex="0" role="button" class="btn m-1">Click</div>
  <ul tabindex="0" class="dropdown-content menu bg-base-100 rounded-box z-[1] w-52 p-2 shadow">
    <li><a>Item 1</a></li>
    <li><a>Item 2</a></li>
  </ul>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the placement class names plus `dropdown-hover` and/or `dropdown-open`
- In the CSS-focus method do not use `<button>` for the trigger — Safari has a bug that prevents the button from being focused. Use `<div role="button" tabindex="0">` instead; it is accessible and works in all browsers
- The `dropdown-content` element needs `tabindex="0"` in the CSS-focus method
- `dropdown-content` is usually a `menu`, but it can be a `card` or any other element
- Give `dropdown-content` a z-index (for example `z-[1]`) so it stacks above the following content
- `dropdown-open` forces the dropdown open, `dropdown-hover` also opens it on hover
