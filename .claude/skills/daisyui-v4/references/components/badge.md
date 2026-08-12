### badge
Badges are used to inform the user of the status of specific data

[badge docs](https://v4.daisyui.com/components/badge/)

#### Class names
- component: `badge`
- color: `badge-neutral`, `badge-primary`, `badge-secondary`, `badge-accent`, `badge-ghost`, `badge-info`, `badge-success`, `badge-warning`, `badge-error`
- style: `badge-outline`
- size: `badge-lg`, `badge-md`, `badge-sm`, `badge-xs`

#### Syntax
```html
<span class="badge {MODIFIER}">Badge</span>
```

#### Rules
- {MODIFIER} is optional and can have one of each color/style/size class names
- badge can be used on any inline element, usually `<span>` or `<div>`
- The size class names are responsive, so `badge-sm md:badge-md` works
- An empty badge renders as a dot — useful together with `indicator-item`
- A badge can be placed inside a heading, a `btn` or a `card-title`
