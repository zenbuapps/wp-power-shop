### artboard
Artboard provides fixed size container to display a demo content on mobile size

[artboard docs](https://v4.daisyui.com/components/artboard/)

#### Class names
- component: `artboard`
- style: `artboard-demo`
- size: `phone-1`, `phone-2`, `phone-3`, `phone-4`, `phone-5`, `phone-6`
- direction: `artboard-horizontal`

#### Syntax
```html
<div class="artboard phone-1">320×568</div>
```

Horizontal, with the demo look:
```html
<div class="artboard artboard-demo artboard-horizontal phone-1">568×320</div>
```

#### Rules
- A size class name is required — `artboard` alone has no dimensions
- The size class names are the bare words `phone-1` … `phone-6`, NOT `artboard-phone-1`
- `phone-1` is 320×568, `phone-2` is 375×667, `phone-3` is 414×736, `phone-4` is 375×812, `phone-5` is 414×896, `phone-6` is 320×1024
- `artboard-horizontal` swaps width and height, so `artboard-horizontal phone-1` is 568×320
- `artboard-demo` adds a shadow and radius and centers the items inside
- `artboard` is commonly used inside `mockup-phone` as the phone screen content
