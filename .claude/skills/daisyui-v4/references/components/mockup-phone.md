### mockup-phone
Phone mockup shows a mockup of an iPhone

[mockup-phone docs](https://v4.daisyui.com/components/mockup-phone/)

#### Class names
- component: `mockup-phone`
- part: `camera`, `display`

#### Syntax
```html
<div class="mockup-phone">
  <div class="camera"></div>
  <div class="display">
    <div class="artboard artboard-demo phone-1">Hi.</div>
  </div>
</div>
```

#### Rules
- The part class names are the bare words `camera` and `display` — they are NOT prefixed with `mockup-phone-`
- `camera` must be empty; it draws the notch
- The screen content goes inside `display`, normally as an `artboard` with a `phone-*` size
- Change the frame color with a border utility, for example `border-primary`
