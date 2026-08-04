### radial-progress
Radial progress can be used to show the progress of a task or to show the passing of time

[radial-progress docs](https://v4.daisyui.com/components/radial-progress/)

#### Class names
- component: `radial-progress`

#### Syntax
```html
<div class="radial-progress" style="--value:70;" role="progressbar">70%</div>
```

Custom size and thickness:
```html
<div class="radial-progress" style="--value:70; --size:12rem; --thickness:2px;" role="progressbar">
  70%
</div>
```

In JSX:
```jsx
<div className="radial-progress" style={{ "--value": 70 }} role="progressbar">
  70%
</div>
```

#### Rules
- `radial-progress` uses a `<div>`, not a `<progress>` element, because browsers cannot render text or pseudo elements inside `<progress>`
- Always set `role="progressbar"` for accessibility — a `<div>` does not get this role implicitly
- `--value` is the percentage (0 to 100) and is required
- `--size` sets the diameter and `--thickness` sets the ring width; both are optional
- Set the ring color with a text color utility such as `text-primary`
- The text inside the div is written by you — it is not generated from `--value`
