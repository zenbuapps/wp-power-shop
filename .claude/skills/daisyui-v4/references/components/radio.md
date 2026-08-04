### radio
Radio buttons allow the user to select one option from a set

[radio docs](https://v4.daisyui.com/components/radio/)

#### Class names
- component: `radio`
- part: `form-control`, `label`, `label-text`, `label-text-alt`
- color: `radio-primary`, `radio-secondary`, `radio-accent`, `radio-success`, `radio-warning`, `radio-info`, `radio-error`
- size: `radio-lg`, `radio-md`, `radio-sm`, `radio-xs`

#### Syntax
```html
<input type="radio" name="radio-1" class="radio {MODIFIER}" checked="checked" />
<input type="radio" name="radio-1" class="radio {MODIFIER}" />
```

#### Rules
- {MODIFIER} is optional and can have one of each color/size class names
- All radio inputs of one group must share the same `name` attribute
- The size class names are responsive, so `radio-sm md:radio-md` works
- Disable it with the native `disabled` attribute
- Wrap it in `form-control` + `label` + `label-text` when it needs a label
- For a segmented-button look, use `join` with `join-item btn` on radio inputs instead
