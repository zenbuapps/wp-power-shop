### indicator
Indicators are used to place an element on the corner of another element

[indicator docs](https://v4.daisyui.com/components/indicator/)

#### Class names
- component: `indicator`
- part: `indicator-item`
- placement: `indicator-start`, `indicator-center`, `indicator-end`, `indicator-top`, `indicator-middle`, `indicator-bottom`

#### Syntax
```html
<div class="indicator">
  <span class="indicator-item {PLACEMENT} badge badge-secondary">99+</span>
  <button class="btn">inbox</button>
</div>
```

#### Rules
- The `indicator-item` must be the FIRST child; the element it decorates comes after it
- {PLACEMENT} is optional and can have one horizontal (`indicator-start`, `indicator-center`, `indicator-end`) and one vertical (`indicator-top`, `indicator-middle`, `indicator-bottom`) class name
- The defaults are `indicator-end` and `indicator-top`
- The placement class names are responsive, so `indicator-center md:indicator-end` works
- The `indicator-item` is usually a `badge`, but it can be a `btn` or any other element
- One `indicator` can contain multiple `indicator-item` elements at different placements
- `indicator` can be combined with another component on the same element, for example `class="avatar indicator"`
