### countdown
Countdown gives you a transition effect of changing numbers

[countdown docs](https://v4.daisyui.com/components/countdown/)

#### Class names
- component: `countdown`

#### Syntax
```html
<span class="countdown">
  <span style="--value:59;"></span>
</span>
```

Clock:
```html
<span class="countdown font-mono text-2xl">
  <span style="--value:10;"></span>
  h
  <span style="--value:24;"></span>
  m
  <span style="--value:59;"></span>
  s
</span>
```

In JSX:
```jsx
<span className="countdown font-mono text-2xl">
  <span style={{ "--value": 10 }}></span>h
  <span style={{ "--value": 24 }}></span>m
  <span style={{ "--value": 59 }}></span>s
</span>
```

#### Rules
- The value is set with the `--value` CSS variable on an inner `<span>`, not as text content
- `--value` must be a number between 0 and 99
- The inner `<span>` is empty — the digits are rendered by CSS
- `countdown` only animates the digit change; the counting logic itself must come from your own JS
- Use `font-mono` and a text size utility to control the appearance
