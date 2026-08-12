### timeline
Timeline component shows a list of events in chronological order

[timeline docs](https://v4.daisyui.com/components/timeline/)

#### Class names
- component: `timeline`
- part: `timeline-start`, `timeline-middle`, `timeline-end`
- style: `timeline-box`
- modifier: `timeline-snap-icon`, `timeline-compact`
- direction: `timeline-vertical`, `timeline-horizontal`

#### Syntax
```html
<ul class="timeline {MODIFIER}">
  <li>
    <div class="timeline-start">1984</div>
    <div class="timeline-middle">{icon}</div>
    <div class="timeline-end timeline-box">First Macintosh computer</div>
    <hr />
  </li>
  <li>
    <hr />
    <div class="timeline-start">1998</div>
    <div class="timeline-middle">{icon}</div>
    <div class="timeline-end timeline-box">iMac</div>
  </li>
</ul>
```

#### Rules
- {MODIFIER} is optional and can have one of the direction class names plus `timeline-compact` and/or `timeline-snap-icon`
- The timeline is a `<ul>` and every event is an `<li>`
- `<hr />` inside an `<li>` draws the connecting line — put one before the content to draw the line towards the previous item and one after it to draw the line towards the next item. The first item has no leading `<hr />` and the last item has no trailing one
- `timeline-start` and `timeline-end` are the two sides; use only one of them for a single-sided timeline
- `timeline-box` gives the start/end content a bordered box look
- Color a line with a background utility on the `<hr />`, for example `<hr class="bg-primary" />`
- `timeline-compact` forces all items to one side; `timeline-snap-icon` snaps the icon to the start instead of the middle
- `timeline-vertical`, `timeline-horizontal` and `timeline-compact` are responsive, so `timeline-vertical lg:timeline-horizontal` works
