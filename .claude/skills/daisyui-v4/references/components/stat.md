### stat
Stat is used to show numbers and data in a box

[stat docs](https://v4.daisyui.com/components/stat/)

#### Class names
- component: `stats`, `stat`
- part: `stat-title`, `stat-value`, `stat-desc`, `stat-figure`, `stat-actions`
- direction: `stats-horizontal`, `stats-vertical`

#### Syntax
```html
<div class="stats {MODIFIER} shadow">
  <div class="stat">
    <div class="stat-figure text-primary">{icon}</div>
    <div class="stat-title">Total Page Views</div>
    <div class="stat-value">89,400</div>
    <div class="stat-desc">21% more than last month</div>
    <div class="stat-actions">{buttons}</div>
  </div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the direction class names
- `stats` is the container and `stat` is one item — both are required
- `stats-horizontal` is the default; the direction class names are responsive, so `stats-vertical lg:stats-horizontal` works
- `stat-figure`, `stat-actions`, `stat-title`, `stat-value` and `stat-desc` are all optional
- Use `place-items-center` on a `stat` to center its content
