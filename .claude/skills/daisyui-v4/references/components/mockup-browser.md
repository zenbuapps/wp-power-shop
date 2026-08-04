### mockup-browser
Browser mockup shows a box that looks like a browser window

[mockup-browser docs](https://v4.daisyui.com/components/mockup-browser/)

#### Class names
- component: `mockup-browser`
- part: `mockup-browser-toolbar`

#### Syntax
```html
<div class="mockup-browser border-base-300 border">
  <div class="mockup-browser-toolbar">
    <div class="input border-base-300 border">https://example.com</div>
  </div>
  <div class="border-base-300 flex justify-center border-t px-4 py-16">Hello!</div>
</div>
```

#### Rules
- `mockup-browser-toolbar` renders the traffic-light dots automatically
- The address bar is an `input` component inside the toolbar — it is plain markup, not a real input
- Add `border-base-300 border` for the outlined look, or `bg-base-300 border` for the filled look
- The page content is a normal `<div>` after the toolbar
