### hero
Hero is a component for displaying a large box or image with a title and description

[hero docs](https://v4.daisyui.com/components/hero/)

#### Class names
- component: `hero`
- part: `hero-content`, `hero-overlay`

#### Syntax
```html
<div class="hero bg-base-200 min-h-screen">
  <div class="hero-content text-center">
    <div class="max-w-md">
      <h1 class="text-5xl font-bold">Hello there</h1>
      <p class="py-6">{description}</p>
      <button class="btn btn-primary">Get Started</button>
    </div>
  </div>
</div>
```

With a background image and overlay:
```html
<div class="hero min-h-screen" style="background-image: url({image-url});">
  <div class="hero-overlay bg-opacity-60"></div>
  <div class="hero-content text-neutral-content text-center">
    <div class="max-w-md">
      <h1 class="mb-5 text-5xl font-bold">Hello there</h1>
      <p class="mb-5">{description}</p>
      <button class="btn btn-primary">Get Started</button>
    </div>
  </div>
</div>
```

#### Rules
- `hero` centers `hero-content` both horizontally and vertically
- Give the hero a height, usually `min-h-screen`
- `hero-overlay` must come before `hero-content` and is only used with a background image; control its darkness with `bg-opacity-*`
- For a two-column hero use `hero-content flex-col lg:flex-row` (or `lg:flex-row-reverse`)
- The hero has no background of its own — add `bg-base-200` or an inline `background-image`
