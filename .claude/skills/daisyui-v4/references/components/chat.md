### chat
Chat bubbles are used to show one line of conversation and all its data, including the author image, author name, time, etc

[chat docs](https://v4.daisyui.com/components/chat/)

#### Class names
- component: `chat`
- part: `chat-image`, `chat-header`, `chat-footer`, `chat-bubble`
- placement: `chat-start`, `chat-end`
- color: `chat-bubble-primary`, `chat-bubble-secondary`, `chat-bubble-accent`, `chat-bubble-info`, `chat-bubble-success`, `chat-bubble-warning`, `chat-bubble-error`

#### Syntax
```html
<div class="chat chat-start">
  <div class="chat-image avatar">
    <div class="w-10 rounded-full"><img alt="{alt-text}" src="{image-url}" /></div>
  </div>
  <div class="chat-header">
    {author}
    <time class="text-xs opacity-50">12:45</time>
  </div>
  <div class="chat-bubble {COLOR}">{message}</div>
  <div class="chat-footer opacity-50">Delivered</div>
</div>
```

#### Rules
- `chat-start` or `chat-end` is REQUIRED on the `chat` element — it sets the alignment
- {COLOR} is optional and can have one of the chat-bubble color class names
- `chat-image`, `chat-header` and `chat-footer` are optional
- `chat-image` is usually combined with the `avatar` component
