### steps
Steps can be used to show a list of steps in a process

[steps docs](https://v4.daisyui.com/components/steps/)

#### Class names
- component: `steps`
- part: `step`
- color: `step-neutral`, `step-primary`, `step-secondary`, `step-accent`, `step-info`, `step-success`, `step-warning`, `step-error`
- direction: `steps-vertical`, `steps-horizontal`

#### Syntax
```html
<ul class="steps {MODIFIER}">
  <li class="step step-primary">Register</li>
  <li class="step step-primary">Choose plan</li>
  <li class="step">Purchase</li>
  <li class="step">Receive Product</li>
</ul>
```

#### Rules
- {MODIFIER} is optional and can have one of the direction class names
- `steps` must be a `<ul>` and every step must be an `<li class="step">`
- Mark the completed steps by adding a color class name to them
- The direction class names are responsive, so `steps-vertical lg:steps-horizontal` works
- Set the bubble content with the `data-content` attribute on the `<li>`, for example `data-content="?"` or `data-content="✓"`. `data-content=""` renders an empty bubble
- Wrap a long horizontal steps in `<div class="overflow-x-auto">` so it can scroll
