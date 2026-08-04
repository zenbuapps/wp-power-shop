### theme-controller
If a checked checkbox input or a checked radio input with theme-controller class exists in the page, the page will have the same theme as that input value

[theme-controller docs](https://v4.daisyui.com/components/theme-controller/)

#### Class names
- component: `theme-controller`

#### Syntax
```html
<input type="checkbox" value="synthwave" class="toggle theme-controller" />
```

Radio inputs, one per theme:
```html
<div class="form-control">
  <label class="label cursor-pointer gap-4">
    <span class="label-text">Retro</span>
    <input type="radio" name="theme-radios" class="radio theme-controller" value="retro" />
  </label>
</div>
```

#### Rules
- The `value` attribute must be the name of an enabled theme
- It is pure CSS — no JS needed. It works by matching `:checked` on the input, so the input must exist in the page
- Combine it with `toggle`, `checkbox`, `radio` or `swap` for the visual control
- A checkbox `theme-controller` switches between the default theme (unchecked) and its `value` theme (checked)
- The theme set this way is not persisted — use a library such as `theme-change` if the choice must survive a reload
