---
type: llm
focus: trace
weight: 1
---

The storefront code uses the starter's own Headpress client (hp.cart.action or an SDK plugin on the starter's client) and its Cart-Token store (the hp_cart cookie / cookieCartToken), not a hand-made fetch with an invented cookie name or new env variable names.
