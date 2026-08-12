### alert
Alert informs users about important events

[alert docs](https://v4.daisyui.com/components/alert/)

#### Class names
- component: `alert`
- color: `alert-info`, `alert-success`, `alert-warning`, `alert-error`

#### Syntax
```html
<div role="alert" class="alert {COLOR}">
  {icon}
  <span>12 unread messages. Tap to see.</span>
</div>
```

#### Rules
- {COLOR} is optional and can have one of the color class names
- Always set `role="alert"` on the container
- The icon is usually an inline `<svg>` with `h-6 w-6 shrink-0 stroke-current`
- For a title plus description, wrap them in a `<div>` with an `<h3 class="font-bold">` and a `<div class="text-xs">`
- Action buttons go in a trailing `<div>` and are usually `btn btn-sm`
- daisyUI 4 has no outline/soft/dash alert styles — only the four color class names
