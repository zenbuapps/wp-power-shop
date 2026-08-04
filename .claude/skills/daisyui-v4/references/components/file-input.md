### file-input
File Input is an input field for uploading files

[file-input docs](https://v4.daisyui.com/components/file-input/)

#### Class names
- component: `file-input`
- part: `form-control`, `label`, `label-text`, `label-text-alt`
- style: `file-input-bordered`, `file-input-ghost`
- color: `file-input-primary`, `file-input-secondary`, `file-input-accent`, `file-input-info`, `file-input-success`, `file-input-warning`, `file-input-error`
- size: `file-input-lg`, `file-input-md`, `file-input-sm`, `file-input-xs`

#### Syntax
```html
<input type="file" class="file-input file-input-bordered {MODIFIER} w-full max-w-xs" />
```

With form-control and labels:
```html
<label class="form-control w-full max-w-xs">
  <div class="label">
    <span class="label-text">Pick a file</span>
    <span class="label-text-alt">Alt label</span>
  </div>
  <input type="file" class="file-input file-input-bordered w-full max-w-xs" />
</label>
```

#### Rules
- {MODIFIER} is optional and can have one of each style/color/size class names
- In daisyUI 4 the plain `file-input` has NO border — add `file-input-bordered` when a border is wanted
- `file-input-ghost` removes the background
- The size class names are responsive, so `file-input-sm md:file-input-md` works
- Set the width with a Tailwind utility such as `w-full max-w-xs`
