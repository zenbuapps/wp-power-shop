### mockup-code
Code mockup is used to show a block of code in a box that looks like a code editor

[mockup-code docs](https://v4.daisyui.com/components/mockup-code/)

#### Class names
- component: `mockup-code`

#### Syntax
```html
<div class="mockup-code">
  <pre data-prefix="$"><code>npm i daisyui</code></pre>
  <pre data-prefix=">" class="text-warning"><code>installing...</code></pre>
  <pre data-prefix=">" class="text-success"><code>Done!</code></pre>
</div>
```

#### Rules
- Every line is a `<pre>` with a `<code>` inside
- The line prefix (a `$`, a `>` or a line number) comes from the `data-prefix` attribute
- `data-prefix` is optional — omit it for a plain line
- Highlight a line with color utilities on the `<pre>`, for example `class="bg-warning text-warning-content"`
- Long lines scroll horizontally inside the box
- Recolor the whole block with `bg-primary text-primary-content` on the `mockup-code` element
