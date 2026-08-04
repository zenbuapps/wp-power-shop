### input
Text Input is a simple input field

[input docs](https://v4.daisyui.com/components/input/)

#### Class names
- component: `input`
- part: `form-control`, `label`, `label-text`, `label-text-alt`
- style: `input-bordered`, `input-ghost`
- color: `input-primary`, `input-secondary`, `input-accent`, `input-info`, `input-success`, `input-warning`, `input-error`
- size: `input-lg`, `input-md`, `input-sm`, `input-xs`

#### Syntax
```html
<input type="text" placeholder="Type here" class="input input-bordered {MODIFIER} w-full max-w-xs" />
```

With form-control and labels:
```html
<label class="form-control w-full max-w-xs">
  <div class="label">
    <span class="label-text">What is your name?</span>
    <span class="label-text-alt">Top Right label</span>
  </div>
  <input type="text" placeholder="Type here" class="input input-bordered w-full max-w-xs" />
  <div class="label">
    <span class="label-text-alt">Bottom Left label</span>
    <span class="label-text-alt">Bottom Right label</span>
  </div>
</label>
```

With an icon or text inside:
```html
<label class="input input-bordered flex items-center gap-2">
  Name
  <input type="text" class="grow" placeholder="Daisy" />
</label>
<label class="input input-bordered flex items-center gap-2">
  <input type="text" class="grow" placeholder="Search" />
  <kbd class="kbd kbd-sm">K</kbd>
</label>
```

#### Rules
- {MODIFIER} is optional and can have one of each style/color/size class names
- In daisyUI 4 the plain `input` has NO border — add `input-bordered` when a border is wanted
- `input-ghost` removes the background
- Works with any input type (text, password, email, number, …)
- When the field needs more than one element inside, put the `input` class on a `<label>` wrapper and give the inner `<input>` the `grow` utility
- The size class names are responsive, so `input-sm md:input-md` works
- `input-group` exists in daisyUI 4 but is deprecated — use `join` and `join-item` instead
