### checkbox
Checkboxes are used to select or deselect a value

[checkbox docs](https://v4.daisyui.com/components/checkbox/)

#### Class names
- component: `checkbox`
- part: `form-control`, `label`, `label-text`, `label-text-alt`
- color: `checkbox-primary`, `checkbox-secondary`, `checkbox-accent`, `checkbox-success`, `checkbox-warning`, `checkbox-info`, `checkbox-error`
- size: `checkbox-lg`, `checkbox-md`, `checkbox-sm`, `checkbox-xs`

#### Syntax
```html
<input type="checkbox" checked="checked" class="checkbox {MODIFIER}" />
```

With label and form-control:
```html
<div class="form-control">
  <label class="label cursor-pointer">
    <span class="label-text">Remember me</span>
    <input type="checkbox" checked="checked" class="checkbox" />
  </label>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of each color/size class names
- The size class names are responsive, so `checkbox-sm md:checkbox-md` works
- Disable it with the native `disabled` attribute
- Set the indeterminate state from JS: `document.getElementById("id").indeterminate = true`
- Override colors with `[--chkbg:…]` and `[--chkfg:…]`
- `form-control` is the flex-column wrapper, `label` is the flex row, `label-text` is the text — see ../usage.md
