---
type: llm
focus: trace
weight: 1
---

The answer identifies that since WooCommerce 11.3.1 the Store API no longer sends Access-Control-Allow-Credentials to the storefront origin, so credentialed requests (credentials: "include", cookies) fail CORS.
