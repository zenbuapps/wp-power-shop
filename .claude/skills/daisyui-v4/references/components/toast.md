### toast
Toast is a wrapper to stack elements, positioned on the corner of page

[toast docs](https://v4.daisyui.com/components/toast/)

#### Class names
- component: `toast`
- placement: `toast-start`, `toast-center`, `toast-end`, `toast-top`, `toast-middle`, `toast-bottom`

#### Syntax
```html
<div class="toast {PLACEMENT}">
  <div class="alert alert-info">
    <span>New message arrived.</span>
  </div>
</div>
```

#### Rules
- {PLACEMENT} is optional and can have one horizontal (`toast-start`, `toast-center`, `toast-end`) and one vertical (`toast-top`, `toast-middle`, `toast-bottom`) class name
- The defaults are `toast-end` and `toast-bottom`
- The placement class names are responsive, so `toast-center md:toast-end` works
- `toast` is a fixed-position stack — its children are usually `alert` components
- daisyUI has no auto-dismiss behavior; showing and hiding the toast is up to your own JS
