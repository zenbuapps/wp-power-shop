### divider
Divider will be used to separate content vertically or horizontally

[divider docs](https://v4.daisyui.com/components/divider/)

#### Class names
- component: `divider`
- color: `divider-neutral`, `divider-primary`, `divider-secondary`, `divider-accent`, `divider-success`, `divider-warning`, `divider-info`, `divider-error`
- direction: `divider-vertical`, `divider-horizontal`
- placement: `divider-start`, `divider-end`

#### Syntax
```html
<div class="flex w-full flex-col">
  <div class="card bg-base-300 rounded-box grid h-20 place-items-center">content</div>
  <div class="divider {MODIFIER}">OR</div>
  <div class="card bg-base-300 rounded-box grid h-20 place-items-center">content</div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of each color/direction/placement class names
- `divider-vertical` (a horizontal line separating stacked content) is the default
- `divider-horizontal` draws a vertical line between side-by-side content — the parent must be a horizontal flex container
- The text inside the divider is optional; an empty divider draws just the line
- The direction and placement class names are responsive, so `divider lg:divider-horizontal` works
- `divider-start` and `divider-end` push the text to one side
