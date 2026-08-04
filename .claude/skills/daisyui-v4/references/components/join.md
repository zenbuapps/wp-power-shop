### join
Join is a container for grouping multiple items, it can be used to group buttons, inputs, or any other element. Join applies border radius to the first and last item

[join docs](https://v4.daisyui.com/components/join/)

#### Class names
- component: `join`
- part: `join-item`
- direction: `join-vertical`, `join-horizontal`

#### Syntax
```html
<div class="join {MODIFIER}">
  <button class="btn join-item">Button</button>
  <button class="btn join-item">Button</button>
  <button class="btn join-item">Button</button>
</div>
```

Mixed elements:
```html
<div class="join">
  <input class="input input-bordered join-item" placeholder="Email" />
  <button class="btn join-item">Subscribe</button>
</div>
```

Radio inputs styled as buttons:
```html
<div class="join">
  <input class="join-item btn" type="radio" name="options" aria-label="Radio 1" />
  <input class="join-item btn" type="radio" name="options" aria-label="Radio 2" />
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the direction class names
- `join-horizontal` is the default
- Every direct child that should be joined needs `join-item`
- The direction class names are responsive, so `join-vertical lg:join-horizontal` works
- Override the radius of an end item with a Tailwind utility, for example `join-item rounded-r-full`
- `join` replaces the deprecated `btn-group` and `input-group` of daisyUI 4
- When an item is wrapped in another element, put `join-item` on the inner element that renders the border
