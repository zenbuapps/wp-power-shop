### toggle
Toggle is a checkbox that is styled to look like a switch button

[toggle docs](https://v4.daisyui.com/components/toggle/)

#### Class names
- component: `toggle`
- part: `form-control`, `label`, `label-text`, `label-text-alt`
- color: `toggle-primary`, `toggle-secondary`, `toggle-accent`, `toggle-success`, `toggle-warning`, `toggle-info`, `toggle-error`
- size: `toggle-lg`, `toggle-md`, `toggle-sm`, `toggle-xs`

#### Syntax
```html
<input type="checkbox" class="toggle {MODIFIER}" checked="checked" />
```

With label and form-control:
```html
<div class="form-control">
  <label class="label cursor-pointer">
    <span class="label-text">Remember me</span>
    <input type="checkbox" class="toggle" checked="checked" />
  </label>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of each color/size class names
- `toggle` is applied to an `<input type="checkbox">`
- The size class names are responsive, so `toggle-sm md:toggle-md` works
- Disable it with the native `disabled` attribute
- Set the indeterminate state from JS: `document.getElementById("id").indeterminate = true`
- Override the track color with `[--tglbg:…]` and the handle offset with `[--handleoffset:…]`
