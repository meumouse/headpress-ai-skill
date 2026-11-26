/**
 * Storefront side of the Acme add-on: typed access to its routes through an SDK plugin.
 * Copy to src/lib/headpress/acme-plugin.ts in the storefront and add `acme` to
 * clientOptions().plugins in src/lib/headpress/options.ts. Then: `hp.acme.requestQuote(...)`.
 */
import { definePlugin } from "@meumouse.com/headpress";

export interface QuoteRequest {
  name: string;
  email: string;
  product_id: number;
  quantity: number;
  message?: string;
}

export const acme = definePlugin({
  name: "acme",
  setup: ({ request }) => ({
    /** POST /headpress/v1/store/acme/quotes. Fails fast with headpress_module_disabled when the module is off. */
    requestQuote: (body: QuoteRequest, captcha?: string) =>
      request<{ id: number }>("/store/acme/quotes", { method: "POST", body, captcha, module: "acme_quotes" }),
  }),
  messages: {
    en: {
      acme_quote_unavailable: "This product does not take quote requests.",
      acme_rate_limited: "Too many requests. Try again later.",
    },
    "pt-BR": {
      acme_quote_unavailable: "Este produto não aceita pedidos de orçamento.",
      acme_rate_limited: "Muitas tentativas. Tente novamente mais tarde.",
    },
  },
});

// Config and Store API data of the add-on's modules, typed by declaration merging.
declare module "@meumouse.com/headpress" {
  interface ModuleConfigMap {
    acme_quotes: { enabled: true; min_quantity: number; endpoint: string };
  }
}
