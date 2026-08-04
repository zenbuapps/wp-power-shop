### collapse
Collapse is used for showing and hiding content

[collapse docs](https://v4.daisyui.com/components/collapse/)

#### Class names
- component: `collapse`
- part: `collapse-title`, `collapse-content`
- style: `collapse-arrow`, `collapse-plus`
- behavior: `collapse-open`, `collapse-close`

#### Syntax
Using focus:
```html
<div tabindex="0" class="collapse {MODIFIER} bg-base-200">
  <div class="collapse-title text-xl font-medium">Focus me to see content</div>
  <div class="collapse-content"><p>content</p></div>
</div>
```

Using a checkbox (stays open on click):
```html
<div class="collapse bg-base-200">
  <input type="checkbox" />
  <div class="collapse-title text-xl font-medium">Click me to show/hide content</div>
  <div class="collapse-content"><p>content</p></div>
</div>
```

Using details and summary:
```html
<details class="collapse bg-base-200">
  <summary class="collapse-title text-xl font-medium">Click to open/close</summary>
  <div class="collapse-content"><p>content</p></div>
</details>
```

#### Rules
- {MODIFIER} is optional and can have one of the style class names and one of the behavior class names
- `tabindex="0"` is necessary to make the div focusable in the focus method
- The focus method closes when the element loses focus; use the checkbox or `<details>` method when the content must stay open
- `collapse-open` forces it open and `collapse-close` forces it closed
- The collapse has no background of its own — add `bg-base-200` and/or `border border-base-300`
- For a group where only one item can be open, use radio inputs — see the `accordion` doc
