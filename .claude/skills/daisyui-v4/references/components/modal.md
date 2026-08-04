### modal
Modal is used to show a dialog or a box when you click a button

[modal docs](https://v4.daisyui.com/components/modal/)

#### Class names
- component: `modal`
- part: `modal-box`, `modal-action`, `modal-backdrop`, `modal-toggle`
- behavior: `modal-open`
- placement: `modal-top`, `modal-middle`, `modal-bottom`

#### Syntax
Method 1 (recommended) — using the dialog element:
```html
<button class="btn" onclick="my_modal_1.showModal()">open modal</button>
<dialog id="my_modal_1" class="modal {MODIFIER}">
  <div class="modal-box">
    <h3 class="text-lg font-bold">Hello!</h3>
    <p class="py-4">Press ESC key or click the button below to close</p>
    <div class="modal-action">
      <form method="dialog">
        <button class="btn">Close</button>
      </form>
    </div>
  </div>
</dialog>
```

Method 2 (legacy) — using a hidden checkbox:
```html
<label for="my_modal_6" class="btn">open modal</label>
<input type="checkbox" id="my_modal_6" class="modal-toggle" />
<div class="modal" role="dialog">
  <div class="modal-box">
    <h3 class="text-lg font-bold">Hello!</h3>
  </div>
  <label class="modal-backdrop" for="my_modal_6">Close</label>
</div>
```

Method 3 (legacy) — using an anchor link:
```html
<a href="#my_modal_8" class="btn">open modal</a>
<div class="modal" role="dialog" id="my_modal_8">
  <div class="modal-box">
    <h3 class="text-lg font-bold">Hello!</h3>
    <div class="modal-action"><a href="#" class="btn">Yay!</a></div>
  </div>
</div>
```

#### Rules
- {MODIFIER} is optional and can have one of the placement class names
- The `<dialog>` method is the recommended one — open it with `ID.showModal()` and close it with `ID.close()` or ESC
- A `<button>` inside a `<form method="dialog">` closes the dialog
- Add `<form method="dialog" class="modal-backdrop"><button>close</button></form>` after `modal-box` to close on outside click
- `modal-open` opens the modal from JS when the checkbox/anchor methods are used
- `modal-top`, `modal-middle` and `modal-bottom` are responsive, so `modal-bottom sm:modal-middle` works
- In React open the dialog with `document.getElementById("ID").showModal()`
