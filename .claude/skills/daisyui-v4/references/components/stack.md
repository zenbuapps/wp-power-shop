### stack
Stack visually puts elements on top of each other

[stack docs](https://v4.daisyui.com/components/stack/)

#### Class names
- component: `stack`

#### Syntax
```html
<div class="stack">
  <div class="bg-primary text-primary-content grid h-20 w-32 place-content-center rounded">1</div>
  <div class="bg-accent text-accent-content grid h-20 w-32 place-content-center rounded">2</div>
  <div class="bg-secondary text-secondary-content grid h-20 w-32 place-content-center rounded">3</div>
</div>
```

#### Rules
- All direct children are stacked on top of each other, with the FIRST child on top
- The children should be roughly the same size — the stack takes the size of the largest one
- Common uses are stacked images, stacked `card` elements and stacked notifications
- Vary `shadow-md` / `shadow` / `shadow-sm` across the children to make the depth readable
