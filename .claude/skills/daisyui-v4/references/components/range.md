### range
Range slider is used to select a value by sliding a handle

[range docs](https://v4.daisyui.com/components/range/)

#### Class names
- component: `range`
- color: `range-primary`, `range-secondary`, `range-accent`, `range-success`, `range-warning`, `range-info`, `range-error`
- size: `range-lg`, `range-md`, `range-sm`, `range-xs`

#### Syntax
```html
<input type="range" min="0" max="100" value="40" class="range {MODIFIER}" />
```

With steps and measure marks:
```html
<input type="range" min="0" max="100" value="25" class="range" step="25" />
<div class="flex w-full justify-between px-2 text-xs">
  <span>|</span>
  <span>|</span>
  <span>|</span>
  <span>|</span>
  <span>|</span>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of each color/size class names
- Use the native `<input type="range">` with `min`, `max`, `value` and optionally `step`
- The measure marks are plain markup you add yourself — daisyUI does not draw them
- The size class names are responsive, so `range-xs md:range-sm` works
- Override the thumb color with `[--range-shdw:…]`
