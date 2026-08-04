### link
Link adds the missing underline style to links

[link docs](https://v4.daisyui.com/components/link/)

#### Class names
- component: `link`
- color: `link-neutral`, `link-primary`, `link-secondary`, `link-accent`, `link-success`, `link-info`, `link-warning`, `link-error`
- style: `link-hover`

#### Syntax
```html
<a class="link {MODIFIER}">Click me</a>
```

#### Rules
- {MODIFIER} is optional and can have one of the color class names and `link-hover`
- Tailwind CSS resets the style of links by default, so `link` is what brings the underline back
- `link-hover` shows the underline only on hover
- `link` can be used on any element, not only `<a>`
