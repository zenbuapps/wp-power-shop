### rating
Rating is a set of radio buttons that allow the user to rate something

[rating docs](https://v4.daisyui.com/components/rating/)

#### Class names
- component: `rating`
- modifier: `rating-half`, `rating-hidden`
- size: `rating-lg`, `rating-md`, `rating-sm`, `rating-xs`

#### Syntax
```html
<div class="rating {MODIFIER}">
  <input type="radio" name="rating-1" class="mask mask-star" />
  <input type="radio" name="rating-1" class="mask mask-star" checked="checked" />
  <input type="radio" name="rating-1" class="mask mask-star" />
</div>
```

Half stars:
```html
<div class="rating rating-lg rating-half">
  <input type="radio" name="rating-10" class="rating-hidden" />
  <input type="radio" name="rating-10" class="mask mask-star-2 mask-half-1 bg-green-500" />
  <input type="radio" name="rating-10" class="mask mask-star-2 mask-half-2 bg-green-500" />
</div>
```

#### Rules
- {MODIFIER} is optional and can have `rating-half` and one of the size class names
- Every item is an `<input type="radio">` with a `mask` shape class such as `mask-star`, `mask-star-2` or `mask-heart`
- All inputs of one rating must share the same `name`
- Set the shape color with a background utility on each input, for example `bg-orange-400`
- `rating-hidden` on a first, shapeless input gives the user a way to clear the rating
- For half stars use `rating-half` on the container plus alternating `mask-half-1` / `mask-half-2` on the inputs — each visible star then needs two inputs
- The size class names are responsive
