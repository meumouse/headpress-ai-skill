# Headpress AI skill

[English](README.md) · **Português (Brasil)** · [Español](README.es-ES.md)

Uma skill de agente (`headpress-store`) que ensina assistentes de IA a construir uma **loja
WooCommerce headless** na stack do Headpress **do zero ao deploy**. Também ensina a estendê-la com
plugins add-on sob medida para necessidades específicas de cada cliente.

A stack coberta:

| Peça | Repositório | Papel |
|---|---|---|
| **Headpress** | [`meumouse/headpress`](https://github.com/meumouse/headpress) | Plugin WordPress: REST `headpress/v1`, extensões da Store API (`extensions.headpress`), modo headless, webhooks de revalidação, módulos |
| **SDK** `@meumouse.com/headpress` | [`meumouse/headpress-sdk-js`](https://github.com/meumouse/headpress-sdk-js) | Cliente TypeScript, helpers para Next.js, API de plugins, registry shadcn |
| **Storefront starter** | [`meumouse/storefront-starter-headpress-nextjs`](https://github.com/meumouse/storefront-starter-headpress-nextjs) | Storefront em Next.js 16 + React 19 + Tailwind 4 |

A skill resume a documentação oficial desses três repositórios. Ela também traz as lições de uma
migração real em produção: uma loja WooCommerce com tema clássico que passou para headless. Os
repositórios continuam sendo a fonte da verdade. A skill diz ao agente **o que ler, em que ordem,
onde construir cada coisa e o que costuma dar errado**.

Versões vigentes quando a skill foi escrita (nov/2026): Headpress 1.8.0, SDK 0.1.2, starter 0.2.1.

---

## Sumário

- [O que a skill faz](#o-que-a-skill-faz)
- [Instalação](#instalação)
- [Uso](#uso)
- [Estrutura do repositório](#estrutura-do-repositório)
- [O fluxo de trabalho](#o-fluxo-de-trabalho)
- [Regras de ouro](#regras-de-ouro)
- [Template de add-on](#template-de-add-on)
- [Evals](#evals)
- [Empacotamento](#empacotamento)
- [Manutenção da skill](#manutenção-da-skill)

---

## O que a skill faz

Quando uma tarefa envolve a stack do Headpress, o agente carrega o `SKILL.md`. Se precisar, ele
abre também o arquivo de referência da parte em que está trabalhando. Com isso, o agente consegue:

- **Planejar o projeto:** domínios, idioma, mapa de rotas, gateways, frete, parcelamento, campos do
  checkout, SEO, analytics e consentimento, hospedagem. Uma tabela indica onde cada funcionalidade
  deve ficar: configuração pública, extensão da Store API, ação de carrinho, campo de checkout,
  rota REST, módulo de integração, storefront ou núcleo do Headpress.
- **Configurar o backend:** plugins, configurações em Headpress → Vitrine (URL da vitrine, origens,
  mapa de rotas, namespaces de CORS, webhook, chaves de API), módulos, constantes do
  `wp-config.php`, ajuste do servidor, segurança e um modo headless que nunca prejudica o SEO.
- **Construir o storefront:** estrutura do starter e regras de camadas, variáveis de ambiente (de
  build e de runtime), integração com o SDK, páginas e rotas, tema, i18n, registro de pagamentos,
  e-mails, cache, comportamento em queda do servidor, SEO e analytics.
- **Escrever plugins add-on sob medida** que registram módulos do Headpress com configurações,
  rotas, extensões da Store API, ações de carrinho, eventos, jobs, health checks, captcha, rate
  limits e telas no admin. O plugin correspondente do SDK (`definePlugin()` com declaration
  merging) é criado junto.
- **Integrar gateways de pagamento** com o checkout da Store API, tanto no WordPress quanto no
  storefront: redirecionamento hospedado, Pix ou boleto com polling e campos de cartão tokenizados
  no navegador.
- **Fazer o deploy e colocar no ar:** imagem Docker e CI, proxy reverso e Cloudflare, migração em
  ondas, runbook de virada, smoke test, plano de rollback, monitoramento e desativação do legado.
- **Evitar falhas conhecidas:** um catálogo de incidentes reais agrupados por sintoma (CORS,
  sessões, redirecionamentos, SEO, cache, pagamentos, deploy, segurança).

A skill é acionada quando o usuário menciona Headpress, uma loja WooCommerce ou Next.js headless, o
starter, o SDK, `extensions.headpress`, `headpress/v1`, Cart-Token, uma migração de storefront ou um
plugin "integrado ao Headpress". Ela também é acionada por uma pergunta sobre uma única peça, como
um checkout, um meio de pagamento, um deploy, um erro de CORS ou um add-on.

---

## Instalação

A pasta da skill precisa ter o nome da skill: **`headpress-store`**.

### Claude Code: todos os projetos (nível do usuário)

```powershell
git clone https://github.com/meumouse/headpress-ai-skill.git "$env:USERPROFILE\.claude\skills\headpress-store"
```

```bash
git clone https://github.com/meumouse/headpress-ai-skill.git ~/.claude/skills/headpress-store
```

### Claude Code: um projeto só

Clone ou copie em `.claude/skills/headpress-store/` na raiz do projeto, por exemplo no repositório
do storefront ou do add-on. Faça commit lá se o time inteiro deve recebê-la.

### Apps Claude (claude.ai / desktop)

Gere o pacote `.skill` (veja [Empacotamento](#empacotamento)) e envie nas configurações de Skills do
app.

### Outros agentes (Codex, Cursor, Copilot…)

Aponte o agente para a skill a partir do `AGENTS.md` do projeto (ou do arquivo de regras da
ferramenta), por exemplo:

```markdown
For anything about the Headpress stack, read `path/to/headpress-store/SKILL.md` first and the
reference file it points to for the task.
```

### Atualização

```powershell
git -C "$env:USERPROFILE\.claude\skills\headpress-store" pull
```

---

## Uso

Não é preciso chamar nada: descreva a tarefa e o agente carrega a skill. Exemplos de pedidos:

- "Comece uma loja headless nova com Headpress para `loja.com.br`, com Pix e cartão via Mercado Pago."
- "Crie um plugin integrado ao Headpress que permita ao cliente escrever uma mensagem para presente
  no carrinho."
- "Escreva o runbook de deploy e virada: WordPress atrás da Cloudflare, storefront em Docker."
- "Depois de atualizar o WooCommerce, o carrinho do storefront dá erro de CORS. Como corrijo?"
- "Migre esta loja WooCommerce com tema clássico para o storefront do Headpress sem perder SEO."
- "Faça o gateway da PagBank funcionar no checkout headless."

No Claude Code também dá para chamá-la diretamente com `/headpress-store`.

---

## Estrutura do repositório

```
SKILL.md                         ponto de entrada: topologia, repositórios, regras de ouro, fases 0–7, definição de pronto
references/
  contracts.md                   contratos WP ↔ storefront: escopos e chaves REST, store config,
                                 extensions.headpress, ações de carrinho, dados do checkout, Cart-Token,
                                 CORS, rate limits, webhook de revalidação e vocabulário de tags, eventos
  wordpress-setup.md             instalação e configuração do backend, módulos, constantes do wp-config,
                                 modo headless e SEO, ajuste do servidor, segurança, verificações com curl
  storefront.md                  estrutura e regras do starter, variáveis de ambiente, SDK, rotas, cache,
                                 SEO, analytics, e-mails, componentes do registry, plugins do SDK, testes
  addon-plugins.md               plugins add-on: esqueleto, API de módulos, schema de configurações,
                                 rotas, Store API, eventos, jobs, health checks, UI no admin, integração
                                 com plugins de terceiros, importador de dados legados, plugin do SDK,
                                 testes e build
  payment-gateways.md            processamento de pagamento na Store API, config pública do método,
                                 rotas extras, webhooks, providers de pagamento no storefront, polling
                                 e nova tentativa, segurança
  deploy-golive.md               Docker/CI, proxy e Cloudflare, cache, monitoramento, ondas de migração,
                                 runbook de virada, smoke test, rollback e desativação do legado
  pitfalls.md                    incidentes reais e suas correções, agrupados por sintoma
assets/
  addon-template/                um add-on "Acme" funcional (pedidos de orçamento) para copiar em novos add-ons
evals/                           casos de teste da skill (veja Evals)
```

O `SKILL.md` é curto e diz ao agente qual referência abrir. As referências só são carregadas quando
a tarefa precisa delas, então a skill ocupa pouco contexto em perguntas pequenas.

---

## O fluxo de trabalho

O `SKILL.md` conduz todo projeto por oito fases. Cada fase termina com uma verificação que o agente
consegue rodar:

| Fase | O que acontece | Verificação |
|---|---|---|
| 0. Descoberta | Domínios, idioma, mapa de rotas, gateways, frete, campos do checkout, SEO, analytics, hospedagem; onde cada funcionalidade fica | Decisões registradas no arquivo de plano do projeto |
| 1. Backend | WordPress + WooCommerce + Headpress + integrações, configurações da Vitrine, módulos, chaves de API | `GET /wp-json/headpress/v1/store/config` retorna o mapa de rotas; health checks sem erros |
| 2. Storefront | Starter, `npm run demo`, env, rotas, marca via tokens, páginas da loja | `check:routes` e `npm run verify` passam; nenhum erro de CORS |
| 3. Integrações | Pagamentos, frete, parcelamento, analytics, e-mails | Um pedido de teste em sandbox por gateway; uma compra por ferramenta de analytics |
| 4. Add-on | Funcionalidades específicas do cliente como módulos do Headpress num plugin separado, mais o plugin do SDK | Os módulos ligam e desligam as rotas; o ZIP de release instala num site limpo |
| 5. SEO e migração | Head de SEO, redirecionamentos, robots e sitemap, mapa de URLs legadas, importação de dados | Crawl das URLs antigas: toda URL dá 200 ou um único 301 |
| 6. Deploy | Imagem Docker, env de build e de runtime, tags no CI, proxies confiáveis, Redis | A imagem roda só com segredos de runtime |
| 7. Go-live | Runbook de virada, smoke test, 302 → 301, rastreamento legado desligado, monitoramento, desativação do legado | Smoke test verde; webhooks entregues |

A skill termina com um checklist de **definição de pronto** para o projeto inteiro.

---

## Regras de ouro

Valem em todas as fases. O texto completo está no `SKILL.md`.

1. **O servidor decide, o storefront mostra.** Preços, descontos, parcelas, frete e estoque são
   calculados pelo WordPress e apenas exibidos pelo storefront.
2. **API primeiro.** Toda capacidade é uma rota REST ou uma extensão da Store API antes de virar
   uma tela.
3. **Não duplique o WooCommerce.** Estenda a Store API (`extensions.headpress.<module>`); nunca a
   reimplemente.
4. **Estenda, não edite o núcleo.** Funcionalidades específicas do cliente vão num plugin add-on.
   Um ponto de extensão que falta é adicionado ao próprio Headpress, numa mudança separada e
   versionada.
5. **Contratos atravessam repositórios.** IDs de módulos, formato da config, formato das rotas,
   chaves das extensões e tags do webhook são consumidos por outros repositórios. Mudar um formato
   é uma mudança incompatível.
6. **Nunca prejudique o SEO do storefront.** Nunca `blog_public = 0` no host do WordPress.
7. **Dinheiro é inteiro em unidades menores** (centavos), de ponta a ponta.
8. **Segredos nunca chegam ao navegador**, e dados de cartão nunca passam pelos servidores.
9. **Queda não é 404.** Um objeto que não existe dá `notFound()`; um backend fora do ar dá um estado
   de indisponibilidade.
10. **Higiene dos repositórios.** Escreva em inglês, não adicione atribuição a IA, siga o estilo de
    cada repositório e não rode comandos destrutivos sem pedido.

---

## Template de add-on

`assets/addon-template/` é um add-on mínimo com um módulo (**pedidos de orçamento**). Ele mostra
todos os pontos de extensão mais comuns:

| Arquivo | Mostra |
|---|---|
| `acme-store-addon.php` | Cabeçalho do plugin com `Requires Plugins: headpress, woocommerce`, autoloader PSR-4, declaração de HPOS, boot em `plugins_loaded` (11), aviso quando o Headpress não está ativo |
| `src/Plugin.php` | Registro de módulos em `Headpress/Modules/Register`, com try/catch por módulo para que um módulo quebrado nunca derrube o site |
| `src/Modules/Quotes/Quotes_Module.php` | Schema de configurações (pública + privada), `public_config` calculado, extensão de produto na Store API, evento próprio, job em segundo plano, health check, rota com captcha |
| `src/Modules/Quotes/Rest/Quote_Post.php` | Rota de escopo `store` com `args()` + `schema()`, rate limits, status de erro reais (404 / 422 / 429), `Cache-Control: no-store` |
| `storefront/acme-plugin.ts` | `definePlugin()` do SDK com método tipado, mensagens de i18n e declaration merging em `ModuleConfigMap` |
| `scripts/build.mjs` | ZIP de release: confere a versão (cabeçalho = constante = `package.json`), confere o CHANGELOG, usa uma allowlist e separadores `/` |
| `tests/smoke.php` | Smoke test via `wp eval-file`: visibilidade da config, caminhos 400/404/201, extensão da Store API, limpeza no final |

Para começar um add-on de verdade, siga o `assets/addon-template/README.md` (em inglês). Em resumo:
renomeie o namespace `Acme`, o prefixo `acme`, o slug `acme-store-addon` e as constantes
`ACME_STORE_ADDON_*`. Depois troque o módulo Quotes pelos módulos da loja e rode o smoke test num
site de desenvolvimento.

> O template passa no `php -l`, mas ainda não foi rodado num site WordPress de verdade. Rode o
> `tests/smoke.php` num site de desenvolvimento antes de confiar nele.

---

## Evals

`evals/` guarda casos de teste no formato lido pelo `claude plugin eval`. Cada caso é uma pasta com:

```
evals/<case-name>/
  prompt.md      configurações da execução + o pedido do usuário
  case.yaml      nome e descrição de um bom resultado
  graders/       uma afirmação verificável por arquivo
```

| Caso | Pedido (resumo) | O que os graders verificam |
|---|---|---|
| `gift-message-addon` | Criar um plugin integrado ao Headpress para uma mensagem para presente de 200 caracteres no carrinho, exibida no pedido e nos e-mails, mais o código do storefront | Registro como módulo do Headpress, escrita no carrinho via Store API / Cart-Token, leitura via `extensions.headpress`, validação no servidor, meta do pedido compatível com HPOS no admin e nos e-mails, nenhum cookie enviado à Store API, código em inglês, uso do cliente / Cart-Token / componentes / i18n do próprio starter |
| `cloudflare-docker-golive-runbook` | Runbook de deploy e virada: WordPress atrás da Cloudflare, storefront em Docker | `blog_public` intocado, proxies confiáveis, `NEXT_PUBLIC_*` de build, segredo do webhook de revalidação, mapa de 301, Cloudflare sem cache nas rotas dinâmicas, smoke test com pedido, rollback, origem do storefront no CORS, loja clássica intacta até a virada, links de e-mails já enviados, nenhuma origem de desenvolvimento em produção |
| `store-api-cors-after-woo-update` | Erro de CORS no carrinho depois de atualizar o WooCommerce para a 11.3.1 | Diagnóstico correto (sem credentials para a origem do storefront), `credentials: "omit"` + Cart-Token, nenhuma "correção" insegura, origem do storefront no Headpress, Cart-Token nos headers permitidos e expostos, uma forma de confirmar a correção |

Rode com o Claude Code (v2.1.269 ou mais recente) na raiz do repositório. Cada caso roda com e sem
a skill, e o relatório fica em `evals/results/`:

```powershell
claude plugin eval . --allow-tools Write,Edit,Bash
```

São execuções reais e cobradas do modelo. Quando um caso roda dentro do repositório do Headpress, o
agente consegue ler a documentação do próprio repositório, então um caso pode passar mesmo sem a
skill. Use a comparação com/sem skill para ver o que ela acrescenta.

Também dá para rodar os casos com a skill `skill-creator`. Ela grava os resultados numa pasta irmã
`headpress-store-workspace/` e abre um visualizador para revisão.

---

## Empacotamento

Um arquivo `.skill` é um ZIP com a pasta da skill na raiz. Gere pelo Git para incluir só arquivos
versionados, deixando de fora os READMEs e os evals:

```powershell
git archive --format=zip --prefix=headpress-store/ -o headpress-store.skill HEAD SKILL.md references assets
```

---

## Manutenção da skill

- **Mantenha em sincronia com os repositórios.** Quando um contrato do Headpress, do SDK ou do
  starter mudar, atualize o arquivo de referência correspondente. Isso inclui IDs de módulos,
  formato da store config, rotas, chaves de `extensions.headpress`, tags do webhook e variáveis de
  ambiente. Atualize também as versões citadas no topo do `SKILL.md`.
- **Divergências conhecidas** na documentação dos repositórios estão listadas no `SKILL.md`
  ("Where the knowledge lives"). Remova o item quando o repositório de origem for corrigido:
  - O `AGENTS.md` do Headpress chama o SDK de `@headpress/sdk`; o nome publicado é `@meumouse.com/headpress`.
  - O `AGENTS.md` do Headpress cita um diretório `Addons/` e `headpress_register_event()`, que não existem.
  - O `AGENTS.md` do starter aponta para `src/i18n/messages/`; os dicionários ficam em `src/i18n/<domain>.ts`.
- **Incidentes novos vão para `references/pitfalls.md`.** Agrupe cada um por sintoma, com a causa e
  a correção. Escreva de forma genérica: sem nomes de clientes, domínios, prefixos ou credenciais,
  porque a skill é compartilhada.
- **Confira antes de escrever.** Todo hook, filtro, função, rota e variável de ambiente citado na
  skill precisa existir no código-fonte. Confira com `grep` no repositório em vez de escrever de
  memória.
- **Mantenha o `SKILL.md` enxuto.** Ele é carregado em todo acionamento. Os detalhes ficam em
  `references/`, e o `SKILL.md` só diz quando abrir cada um.
- **Rode os evals de novo** depois de uma mudança relevante, e crie um caso novo quando um tipo de
  tarefa começar a se repetir.
- Tudo neste repositório é escrito em inglês, sem linhas de atribuição a IA nos arquivos ou nos
  commits. As únicas exceções são os READMEs traduzidos (`README.pt-BR.md`, `README.es-ES.md`).
  Mantenha-os em sincronia sempre que o `README.md` mudar.
