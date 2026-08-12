### footer
Footer can contain logo, copyright notice, and links to other pages

[footer docs](https://v4.daisyui.com/components/footer/)

#### Class names
- component: `footer`
- part: `footer-title`
- placement: `footer-center`

#### Syntax
```html
<footer class="footer {MODIFIER} bg-neutral text-neutral-content p-10">
  <nav>
    <h6 class="footer-title">Services</h6>
    <a class="link link-hover">Branding</a>
    <a class="link link-hover">Design</a>
  </nav>
  <nav>
    <h6 class="footer-title">Company</h6>
    <a class="link link-hover">About us</a>
    <a class="link link-hover">Contact</a>
  </nav>
</footer>
```

Centered copyright footer:
```html
<footer class="footer footer-center bg-base-300 text-base-content p-4">
  <aside>
    <p>Copyright © 2024 - All right reserved by ACME Industries Ltd</p>
  </aside>
</footer>
```

#### Rules
- {MODIFIER} is optional and can be `footer-center`
- `footer` is a grid — its direct children become the columns. Use `<nav>` for link columns, `<aside>` for the logo/copyright block and `<form>` for a newsletter column
- `footer-title` goes on the heading of a column
- The footer has no background of its own — add `bg-neutral text-neutral-content`, `bg-base-200` or another pair
- Add padding explicitly, usually `p-10` or `p-4`
- Use `grid-rows-2` or `items-center` on the footer for multi-row and single-line layouts
