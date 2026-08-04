### tab
Tabs can be used to show a list of links in a tabbed format

[tab docs](https://v4.daisyui.com/components/tab/)

#### Class names
- component: `tabs`
- part: `tab`, `tab-content`
- style: `tabs-boxed`, `tabs-bordered`, `tabs-lifted`
- behavior: `tab-active`, `tab-disabled`
- size: `tabs-xs`, `tabs-sm`, `tabs-md`, `tabs-lg`

#### Syntax
Using links or buttons:
```html
<div role="tablist" class="tabs {MODIFIER}">
  <a role="tab" class="tab">Tab 1</a>
  <a role="tab" class="tab tab-active">Tab 2</a>
  <a role="tab" class="tab">Tab 3</a>
</div>
```

Using radio inputs with tab content:
```html
<div role="tablist" class="tabs tabs-lifted">
  <input type="radio" name="my_tabs" role="tab" class="tab" aria-label="Tab 1" />
  <div role="tabpanel" class="tab-content bg-base-100 border-base-300 rounded-box p-6">Tab content 1</div>

  <input type="radio" name="my_tabs" role="tab" class="tab" aria-label="Tab 2" checked="checked" />
  <div role="tabpanel" class="tab-content bg-base-100 border-base-300 rounded-box p-6">Tab content 2</div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the style class names and one of the size class names
- The daisyUI 4 style class names are `tabs-boxed`, `tabs-bordered` and `tabs-lifted` — nothing else
- `tabs` goes on the container with `role="tablist"`; `tab` goes on every item with `role="tab"`
- Mark the current tab with `tab-active`, and a visually disabled tab with `tab-disabled`
- Radio inputs are needed for tab content to switch on click. Each `tab-content` must come immediately after its `tab` input, and all inputs must share the same `name`
- Use `aria-label` on a radio `tab` to set its visible text
- The size class names are responsive, so `tabs-sm lg:tabs-lg` works
- Override tab colors with the arbitrary property syntax, for example `[--tab-bg:yellow] [--tab-border-color:orange]`
