### tooltip
Tooltip can be used to show a message when hovering over an element

[tooltip docs](https://v4.daisyui.com/components/tooltip/)

#### Class names
- component: `tooltip`
- behavior: `tooltip-open`
- placement: `tooltip-top`, `tooltip-bottom`, `tooltip-left`, `tooltip-right`
- color: `tooltip-primary`, `tooltip-secondary`, `tooltip-accent`, `tooltip-info`, `tooltip-success`, `tooltip-warning`, `tooltip-error`

#### Syntax
```html
<div class="tooltip {MODIFIER}" data-tip="hello">
  <button class="btn">Hover me</button>
</div>
```

#### Rules
- The tooltip text comes from the `data-tip` attribute — it is required
- {MODIFIER} is optional and can have one placement class name, one color class name and `tooltip-open`
- `tooltip-top` is the default placement
- `tooltip-open` forces the tooltip visible
- `tooltip` and the placement class names are responsive, so `lg:tooltip lg:tooltip-right` shows a tooltip only on large screens
- The tooltip wraps the target element — put the `tooltip` class on a wrapper, or directly on the element that should trigger it
- Fine-tune with `[--tooltip-color:…]`, `[--tooltip-text-color:…]`, `[--tooltip-offset:…]`, `[--tooltip-tail:…]`
