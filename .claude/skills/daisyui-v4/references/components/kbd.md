### kbd
Kbd is used to display keyboard shortcuts

[kbd docs](https://v4.daisyui.com/components/kbd/)

#### Class names
- component: `kbd`
- size: `kbd-lg`, `kbd-md`, `kbd-sm`, `kbd-xs`

#### Syntax
```html
<kbd class="kbd {SIZE}">A</kbd>
```

#### Rules
- {SIZE} is optional and can have one of the size class names
- The size class names are responsive, so `kbd-xs md:kbd-sm` works
- Use one `kbd` per key and plain text between them for a key combination
- `kbd` works inside a sentence, inside an `input` label, or laid out with flex utilities as a full keyboard
