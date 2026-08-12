### pagination
Pagination is a group of buttons that allow the user to navigate between a set of related content

[pagination docs](https://v4.daisyui.com/components/pagination/)

#### Class names
- component: `join`
- part: `join-item`

#### Syntax
```html
<div class="join">
  <button class="join-item btn">1</button>
  <button class="join-item btn btn-active">2</button>
  <button class="join-item btn">3</button>
  <button class="join-item btn">4</button>
</div>
```

Using radio inputs:
```html
<div class="join">
  <input class="join-item btn btn-square" type="radio" name="options" aria-label="1" checked="checked" />
  <input class="join-item btn btn-square" type="radio" name="options" aria-label="2" />
  <input class="join-item btn btn-square" type="radio" name="options" aria-label="3" />
</div>
```

#### Rules
- daisyUI 4 has no dedicated pagination class — pagination is built from `join` + `join-item` + `btn`
- Mark the current page with `btn-active`
- Use `btn-disabled` for a non-clickable spacer such as an ellipsis
- Resize the whole pagination with the `btn` size class names (`btn-xs`, `btn-sm`, `btn-lg`)
- Use `join grid grid-cols-2` for equal-width previous/next buttons
- See the `join` doc for the full list of `join` class names
