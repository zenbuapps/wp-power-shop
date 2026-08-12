### swap
Swap allows you to toggle the visibility of two elements using a checkbox or a class name

[swap docs](https://v4.daisyui.com/components/swap/)

#### Class names
- component: `swap`
- part: `swap-on`, `swap-off`, `swap-indeterminate`
- behavior: `swap-active`
- style: `swap-rotate`, `swap-flip`

#### Syntax
```html
<label class="swap {MODIFIER}">
  <input type="checkbox" />
  <div class="swap-on">ON</div>
  <div class="swap-off">OFF</div>
</label>
```

#### Rules
- {MODIFIER} is optional and can have `swap-active` and/or one of the style class names
- The hidden `<input type="checkbox">` controls the state — put it directly inside the `swap` element
- `swap-on` shows when the checkbox is checked, `swap-off` shows when it is not, `swap-indeterminate` shows when the checkbox is indeterminate
- Use `swap-active` to control the state with a class name instead of a checkbox — then the `<input>` is not needed
- `swap` can be combined with other components, for example `btn btn-circle swap swap-rotate` for a hamburger toggle
