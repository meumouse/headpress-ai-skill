---
type: llm
focus: trace
weight: 1
---

The storefront writes the message through the Store API cart (POST /wc/store/v1/cart/extensions with namespace "headpress" and an action registered on Headpress's store_api.router) or a store-scope headpress/v1 route identified by the Cart-Token, not through admin-ajax, cookies or a WordPress nonce.
