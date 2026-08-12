### drawer
Drawer is a grid layout that can show/hide a sidebar on the left or right side of the page

[drawer docs](https://v4.daisyui.com/components/drawer/)

#### Class names
- component: `drawer`
- part: `drawer-toggle`, `drawer-content`, `drawer-side`, `drawer-overlay`
- placement: `drawer-end`
- behavior: `drawer-open`

#### Syntax
```html
<div class="drawer {MODIFIER}">
  <input id="my-drawer" type="checkbox" class="drawer-toggle" />
  <div class="drawer-content">
    <!-- Page content here -->
    <label for="my-drawer" class="btn btn-primary drawer-button">Open drawer</label>
  </div>
  <div class="drawer-side">
    <label for="my-drawer" aria-label="close sidebar" class="drawer-overlay"></label>
    <ul class="menu bg-base-200 text-base-content min-h-full w-80 p-4">
      <!-- Sidebar content here -->
      <li><a>Sidebar Item 1</a></li>
      <li><a>Sidebar Item 2</a></li>
    </ul>
  </div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have `drawer-end` and/or `drawer-open`
- The three children are required in this order: the `drawer-toggle` checkbox, `drawer-content`, then `drawer-side`
- The checkbox `id` and the `for` attribute of every opening/closing `<label>` must match
- `drawer-end` puts the sidebar on the right side
- `drawer-open` forces the sidebar open and is responsive, so `drawer lg:drawer-open` gives a sidebar that is permanent on large screens and toggleable on small ones
- `drawer-overlay` is the label that covers the content and closes the drawer when clicked
- `drawer-button` is not a daisyUI class — it is just a hook name used in the official examples
