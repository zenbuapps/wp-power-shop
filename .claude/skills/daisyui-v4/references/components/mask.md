### mask
Mask crops the content of the element to common shapes

[mask docs](https://v4.daisyui.com/components/mask/)

#### Class names
- component: `mask`
- style: `mask-squircle`, `mask-heart`, `mask-hexagon`, `mask-hexagon-2`, `mask-decagon`, `mask-pentagon`, `mask-diamond`, `mask-square`, `mask-circle`, `mask-parallelogram`, `mask-parallelogram-2`, `mask-parallelogram-3`, `mask-parallelogram-4`, `mask-star`, `mask-star-2`, `mask-triangle`, `mask-triangle-2`, `mask-triangle-3`, `mask-triangle-4`
- modifier: `mask-half-1`, `mask-half-2`

#### Syntax
```html
<img class="mask mask-squircle" src="{image-url}" />
```

#### Rules
- A shape class name is required — `mask` alone does nothing
- `mask` works on images and on any other element
- Set the size with Tailwind width/height utilities
- `mask-half-1` crops only the first half of the shape and `mask-half-2` only the second half — used together they build half-star ratings
- The `-2`, `-3` and `-4` suffixes are alternative variants of the same shape, not sizes
- Common combinations: `avatar` with `mask mask-squircle`, and `rating` with `mask mask-star-2`
