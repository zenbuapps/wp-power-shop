### select
Select is used to pick a value from a list of options

[select docs](https://v4.daisyui.com/components/select/)

#### Class names
- component: `select`
- part: `form-control`, `label`, `label-text`, `label-text-alt`
- style: `select-bordered`, `select-ghost`
- color: `select-primary`, `select-secondary`, `select-accent`, `select-info`, `select-success`, `select-warning`, `select-error`
- size: `select-lg`, `select-md`, `select-sm`, `select-xs`

#### Syntax
```html
<select class="select select-bordered {MODIFIER} w-full max-w-xs">
  <option disabled selected>Pick one</option>
  <option>Option 1</option>
  <option>Option 2</option>
</select>
```

With form-control and labels:
```html
<label class="form-control w-full max-w-xs">
  <div class="label">
    <span class="label-text">Pick one</span>
    <span class="label-text-alt">Alt label</span>
  </div>
  <select class="select select-bordered">
    <option disabled selected>Pick one</option>
    <option>Option 1</option>
  </select>
</label>
```

#### Rules
- {MODIFIER} is optional and can have one of each style/color/size class names
- In daisyUI 4 the plain `select` has NO border — add `select-bordered` when a border is wanted
- `select-ghost` removes the background
- Use `<option disabled selected>` for a placeholder row
- The size class names are responsive, so `select-sm md:select-md` works
- Set the width with a Tailwind utility such as `w-full max-w-xs`
