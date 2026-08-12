### table
Table can be used to show a list of data in a table format

[table docs](https://v4.daisyui.com/components/table/)

#### Class names
- component: `table`
- modifier: `table-zebra`, `table-pin-rows`, `table-pin-cols`
- behavior: `hover`
- size: `table-xs`, `table-sm`, `table-md`, `table-lg`

#### Syntax
```html
<div class="overflow-x-auto">
  <table class="table {MODIFIER}">
    <thead>
      <tr>
        <th></th>
        <th>Name</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <th>1</th>
        <td>Cy Ganderton</td>
      </tr>
      <tr class="hover">
        <th>2</th>
        <td>Hart Hagerty</td>
      </tr>
    </tbody>
  </table>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of each modifier/size class names
- Wrap the table in `<div class="overflow-x-auto">` so it scrolls horizontally on small screens
- The row highlight class in daisyUI 4 is the bare word `hover` on the `<tr>` — it is NOT `table-hover` or `row-hover`
- Mark the active row with a background utility such as `bg-base-200` on the `<tr>`
- The size class names are responsive, so `table-xs md:table-md` works
- `table-pin-rows` makes every `<thead>` and `<tfoot>` row sticky; the wrapper needs a height such as `h-96`
- `table-pin-cols` makes every `<th>` column sticky
