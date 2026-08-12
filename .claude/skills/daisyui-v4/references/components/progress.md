### progress
Progress bar can be used to show the progress of a task or to show the passing of time

[progress docs](https://v4.daisyui.com/components/progress/)

#### Class names
- component: `progress`
- color: `progress-primary`, `progress-secondary`, `progress-accent`, `progress-info`, `progress-success`, `progress-warning`, `progress-error`

#### Syntax
```html
<progress class="progress {COLOR} w-56" value="40" max="100"></progress>
```

Indeterminate — omit the value attribute:
```html
<progress class="progress w-56"></progress>
```

#### Rules
- {COLOR} is optional and can have one of the color class names
- Use the native `<progress>` element with `value` and `max` attributes
- A `<progress>` without a `value` attribute renders the indeterminate animation
- Set the width with a Tailwind utility such as `w-56` or `w-full`
- daisyUI 4 has no size class names for progress — use height utilities if a thinner or thicker bar is needed
