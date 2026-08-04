### accordion
Accordion is used for showing and hiding content but only one item can stay open at a time

[accordion docs](https://v4.daisyui.com/components/accordion/)

#### Class names
- component: `collapse`
- part: `collapse-title`, `collapse-content`
- style: `collapse-arrow`, `collapse-plus`
- behavior: `collapse-open`, `collapse-close`

#### Syntax
```html
<div class="collapse {MODIFIER} bg-base-200">
  <input type="radio" name="my-accordion-1" checked="checked" />
  <div class="collapse-title text-xl font-medium">Click to open this one and close others</div>
  <div class="collapse-content">
    <p>hello</p>
  </div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the style class names and one of the behavior class names
- Accordion uses the same class names as `collapse`; the difference is the hidden `<input type="radio">`
- All radio inputs in one accordion must share the same `name` so only one item stays open
- `collapse-arrow` adds an arrow icon, `collapse-plus` adds a plus/minus icon
- Combine with `join join-vertical` and `join-item` to render the items as one connected block
