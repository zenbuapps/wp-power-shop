### avatar
Avatars are used to show a thumbnail representation of an individual or business in the interface

[avatar docs](https://v4.daisyui.com/components/avatar/)

#### Class names
- component: `avatar`, `avatar-group`
- modifier: `online`, `offline`, `placeholder`

#### Syntax
```html
<div class="avatar {MODIFIER}">
  <div class="w-24 rounded">
    <img src="{image-url}" />
  </div>
</div>
```

Avatar group:
```html
<div class="avatar-group -space-x-6 rtl:space-x-reverse">
  <div class="avatar">
    <div class="w-12"><img src="{image-url}" /></div>
  </div>
  <div class="avatar placeholder">
    <div class="bg-neutral text-neutral-content w-12"><span>+99</span></div>
  </div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the modifier class names
- The modifier class names in daisyUI 4 are the bare words `online`, `offline` and `placeholder` — they are NOT prefixed with `avatar-`
- `avatar` must contain an inner wrapper `<div>` that carries the size and shape utilities
- Set the size with Tailwind `w-*` utilities on the inner div — the height follows
- Use `rounded`, `rounded-xl` or `rounded-full` on the inner div for the shape, or a mask class such as `mask mask-squircle`, `mask mask-hexagon`, `mask mask-triangle`
- `placeholder` shows letters instead of an image — put a `<span>` inside a colored inner div
- Use `avatar-group` with a negative `-space-x-*` to overlap multiple avatars
