### carousel
Carousel show images or content in a scrollable area

[carousel docs](https://v4.daisyui.com/components/carousel/)

#### Class names
- component: `carousel`
- part: `carousel-item`
- placement: `carousel-start`, `carousel-center`, `carousel-end`
- direction: `carousel-vertical`

#### Syntax
```html
<div class="carousel {MODIFIER} rounded-box">
  <div class="carousel-item">
    <img src="{image-url}" alt="{alt-text}" />
  </div>
  <div class="carousel-item">
    <img src="{image-url}" alt="{alt-text}" />
  </div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the placement class names and `carousel-vertical`
- `carousel-start` is the default snap alignment
- Give `carousel-item` a width such as `w-full` or `w-1/2` to control how many items are visible
- For a vertical carousel use `carousel-vertical` and give the carousel a height plus `h-full` on the items
- For indicator or next/prev buttons, give each `carousel-item` an `id` and link to it with `<a href="#item1">`
- Use `space-x-4 p-4` on the carousel for a gapped, full-bleed look
