### diff
Diff component shows a side-by-side comparison of two items

[diff docs](https://v4.daisyui.com/components/diff/)

#### Class names
- component: `diff`
- part: `diff-item-1`, `diff-item-2`, `diff-resizer`

#### Syntax
```html
<div class="diff aspect-[16/9]">
  <div class="diff-item-1">
    <img alt="{alt-text}" src="{image-url}" />
  </div>
  <div class="diff-item-2">
    <img alt="{alt-text}" src="{image-url}" />
  </div>
  <div class="diff-resizer"></div>
</div>
```

#### Rules
- All three parts are required — `diff-item-1`, `diff-item-2` and the empty `diff-resizer`
- Give the `diff` element a size, usually with `aspect-[16/9]` or explicit width/height utilities
- The items can hold any content, not only images
- Browser support: Chrome 105+, Firefox 110+, Safari 16+. It does not work in iOS Safari
