### textarea
Textarea allows users to enter text in multiple lines

[textarea docs](https://v4.daisyui.com/components/textarea/)

#### Class names
- component: `textarea`
- part: `form-control`, `label`, `label-text`, `label-text-alt`
- style: `textarea-bordered`, `textarea-ghost`
- color: `textarea-primary`, `textarea-secondary`, `textarea-accent`, `textarea-info`, `textarea-success`, `textarea-warning`, `textarea-error`
- size: `textarea-lg`, `textarea-md`, `textarea-sm`, `textarea-xs`

#### Syntax
```html
<textarea class="textarea textarea-bordered {MODIFIER}" placeholder="Bio"></textarea>
```

With form-control and labels:
```html
<label class="form-control">
  <div class="label">
    <span class="label-text">Your bio</span>
    <span class="label-text-alt">Alt label</span>
  </div>
  <textarea class="textarea textarea-bordered h-24" placeholder="Bio"></textarea>
</label>
```

#### Rules
- {MODIFIER} is optional and can have one of each style/color/size class names
- In daisyUI 4 the plain `textarea` has NO border — add `textarea-bordered` when a border is wanted
- `textarea-ghost` removes the background
- Set the height with a Tailwind utility such as `h-24`
- The size class names are responsive, so `textarea-sm md:textarea-md` works
