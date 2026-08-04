### card
Cards are used to group and display content in a way that is easily readable

[card docs](https://v4.daisyui.com/components/card/)

#### Class names
- component: `card`
- part: `card-title`, `card-body`, `card-actions`
- style: `card-bordered`, `glass`
- modifier: `image-full`
- size: `card-normal`, `card-compact`
- direction: `card-side`

#### Syntax
```html
<div class="card {MODIFIER} bg-base-100 w-96 shadow-xl">
  <figure><img src="{image-url}" alt="{alt-text}" /></figure>
  <div class="card-body">
    <h2 class="card-title">{title}</h2>
    <p>{CONTENT}</p>
    <div class="card-actions justify-end">{actions}</div>
  </div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the style/size/direction class names plus `image-full`
- `<figure>` and `<div class="card-body">` are optional
- `card-normal`, `card-compact` and `card-side` are responsive, so `card-compact lg:card-normal` and `lg:card-side` work
- If the image is placed after `card-body`, the image will be at the bottom
- `image-full` turns the `<figure>` image into the card background
- The card has no background of its own — add `bg-base-100` (or another color) and usually a `shadow-*`
- daisyUI 4 also accepts the bare `bordered` and `compact` class names on a card, but prefer `card-bordered` and `card-compact`
