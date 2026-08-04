### navbar
Navbar is used to show a navigation bar on the top of the page

[navbar docs](https://v4.daisyui.com/components/navbar/)

#### Class names
- component: `navbar`
- part: `navbar-start`, `navbar-center`, `navbar-end`

#### Syntax
```html
<div class="navbar bg-base-100">
  <div class="navbar-start">{start}</div>
  <div class="navbar-center">{center}</div>
  <div class="navbar-end">{end}</div>
</div>
```

Simple two-part navbar using flex utilities:
```html
<div class="navbar bg-base-100">
  <div class="flex-1">
    <a class="btn btn-ghost text-xl">daisyUI</a>
  </div>
  <div class="flex-none">{actions}</div>
</div>
```

#### Rules
- `navbar-start` and `navbar-end` each fill 50% of the width; `navbar-center` fills the remaining space in the middle
- For a simple layout you can skip the three parts and use Tailwind `flex-1` / `flex-none` children instead
- The navbar has no background of its own — add `bg-base-100`, `bg-base-300` or another color
- Common contents are a `btn btn-ghost` brand link, a `menu menu-horizontal`, a `dropdown`, an `avatar` and an `indicator`
- For a responsive navbar, show a `dropdown` with `lg:hidden` on small screens and a `menu menu-horizontal` with `hidden lg:flex` on large screens
