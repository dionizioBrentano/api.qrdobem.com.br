# Manual de Consumo da API QrDoBem (Whitelabel)

Este documento descreve detalhadamente o consumo da API QrDoBem. Ele foi elaborado com base exclusivamente no código-fonte atual e serve como guia definitivo para integrações de frontends, humanos ou via IAs geradoras de código.

## 1. Visão Geral

- **Propósito da API:** Fornecer infraestrutura de backend (whitelabel) para a geração de QR Codes inteligentes de identificação, gestão de perfis organizacionais, e comunicação entre pessoas que encontram algo/alguém e o proprietário da entidade (via mensagens).
- **Base URL de Produção:** Tipicamente `https://api.qrdobem.com.br/api`. O domínio exato deve ser configurado no `.env` do cliente frontend.
- **Stack e Auth:** Backend Laravel utilizando autenticação via Firebase (JWT Bearer Token).
- **Modelo de Dados:** 
  `Tenant` (Usuário) -> `Organization` (Organização/Matriz) -> `Entity` (QR Code/Registro) -> `EntityMessage` (Mensagens recebidas). 
  Créditos para gerar QR codes são atribuídos à organização por meio de `CreditBatch`.

## 2. Princípios para Qualquer Frontend (Humano ou IA)

- **O Cliente NÃO gera QR Code:** Toda a lógica gráfica de geração do SVG do QR Code e a definição da URL embutida ficam no backend. O frontend apenas consome a URL da imagem ou seu base64 através da API.
- **Cadastro sem Fricção:** O cadastro inicial exige apenas o login do Firebase. O usuário nasce com `profile_status: 'incomplete'`. Dados sensíveis (CPF, telefone, endereço) são exigidos progressivamente em "Gates" no momento em que o usuário tenta realizar ações.
- **Gate 1 (Ativação de Perfil):** Requer Email Verificado + CPF + Telefone. O status muda para `active`.
- **Gate 2 (Criação de Entidades):** Requer Gate 1 + Endereço completo (Rua, Cidade, Estado, CEP) + Aceite de Termos específicos (`accept_term: true`).
- **Trilhas de Produto:** O campo `type` da Entidade aceita 3 valores suportados: `person`, `pet` ou `object`.
- **FORA DE ESCOPO / NÃO IMPLEMENTADO:** Portal de doações, causas sociais, aventura ativa (GPS tracking contínuo/detecção de queda), whitelabel de marca completo (cores dinâmicas vindas da API), e aplicativo nativo.

## 3. Autenticação

A autenticação é feita delegando a verificação de credenciais ao Firebase no frontend. A API apenas recebe e valida o JWT.

- **Como obter o token:** O frontend realiza o login (via SDK do Firebase) e extrai o JWT Token.
- **Header:** Enviar em todas as requisições protegidas: `Authorization: Bearer <jwt_aqui>`
- **Comportamento do Middleware (1º Acesso):** Se o Tenant não existir no banco (verificado pelo `firebase_uid`), a API o cria automaticamente como `role: 'ngo'` e `profile_status: 'incomplete'`. Também cria uma `Organization` "Matriz" vinculada a ele, para a qual ele é admin.
- **Erros 401 Típicos:**
  - `Token não fornecido`
  - `Token inválido ou expirado`
  - `Token sem identificação de usuário`
- **Endpoint de checagem:** `GET /api/auth/me` (retorna os dados atuais do Tenant logado).

## 4. Onboarding e Perfil

### Endpoints de Perfil e Documentos
- `GET /api/profile`: Retorna o Tenant, seus documentos, e arrays `missing_for_purchase` (Gate 1) e `missing_for_entity` (Gate 2). Use as flags `can_purchase` e `can_create_entity` para guiar a UX.
- `PUT /api/profile`: Atualiza dados básicos (`name`, `phone`, `address_street`, `address_number`, `address_complement`, `address_neighborhood`, `address_city`, `address_state`, `address_zipcode`). O backend checa automaticamente se os dados suprem o Gate 1 e altera o status para `active`.
- `POST /api/profile/documents`: Recebe `document_type` (ex: `cpf`) e `document_number`. Há validação matemática real de CPF. Pode alterar o status para `active` se suprir o requisito do Gate 1.

### Verificação de Email (OTP)
- `POST /api/auth/send-otp`: Dispara email. Body: `{"email": "...", "firebase_uid": "..."}`.
- `POST /api/auth/verify-otp`: Confirma código. Body: `{"firebase_uid": "...", "code": "123456"}`. O código expira em 15 minutos. Sucesso assinala `email_verified_at` no banco e tenta ativar o Gate 1.

## 4.1. Identidade da Pessoa: Contas Múltiplas e Vínculos

**Adicionado na Fase 0 (06/08/2026).** Referência: `PLANO_TRILHAS_2026-08.md`, fundação F10, requisitos TX-R02 a TX-R04.

### Conceito

O sistema distingue três coisas que antes se confundiam:

| Entidade | Responde |
|---|---|
| `Person` | Quem é a pessoa natural (1 por CPF) |
| `Tenant` | Qual é a **conta** (várias por pessoa, e-mails diferentes) |
| `Space` | Em qual **contexto** se opera (família, causa, empresa, doação) |

Um CPF pode ter várias contas com e-mails diferentes. Isso é legítimo e suportado. A ligação conta → pessoa acontece automaticamente quando a conta cadastra o CPF em `POST /api/profile/documents` (Gate 1).

### Regra de segurança que o frontend precisa respeitar

**Nenhum endpoint aceita CPF vindo do cliente para consultar vínculos.** CPF não é segredo — está em nota fiscal, é pedido em farmácia e vaza em incidente de terceiro. Um endpoint "informe o CPF e veja os vínculos" seria um consultor de vínculos sociais de qualquer brasileiro, num sistema que também guarda alergia, medicação e endereço.

As contas aparecem porque **cada uma comprovou a posse do próprio CPF dentro dela mesma**. Não construa tela que peça CPF para "encontrar minhas contas": ela não vai funcionar, e não deve.

### Endpoints

- `GET /api/me/accounts` — contas da mesma pessoa, incluindo a atual. Cada item traz `id`, `name`, `nickname`, `email`, `role`, `profile_status`, `is_current`. Também devolve `linked` (booleano: a conta já está ligada a uma pessoa?) e `total`.
- `GET /api/me/links` — vínculos consolidados da pessoa. Cada item traz `space_id`, `space_name`, `space_type`, `space_slug`, `role`, `pending`, `permissions[]` e `through_account` (por qual conta o vínculo existe). Também devolve `by_type`, com a contagem por tipo de trilha.
- `POST /api/me/switch-account` — body `{ "target_tenant_id": 123 }`. Valida que a conta de destino é da mesma pessoa.

### Limitação conhecida do switch-account

A identidade neste sistema é o JWT do Firebase, e o backend **não possui credencial de serviço do Firebase Admin SDK** — portanto não consegue emitir token para outra conta. A troca de conta hoje é **assistida**, não silenciosa:

```json
{
  "target": { "id": 123, "name": "...", "email": "..." },
  "method": "reauth",
  "message": "Confirme a senha da conta de destino para concluir a troca."
}
```

O frontend deve tratar `method: "reauth"` levando o usuário ao login com o e-mail de destino preenchido. Para a troca ser silenciosa é preciso adotar o Firebase Admin SDK com service account (decisão D10 do plano) — é decisão de arquitetura e custo operacional, não linha de código faltando.

O campo `method` existe justamente para que o frontend não precise mudar quando essa decisão for tomada.

## 4.2. Trilha Família: Árvore, 2FA e Botão de Pânico

**Adicionado na Fase 1 (06/08/2026).** Requisitos T1-R01, T1-R02, T1-R05, T1-R07.

### Árvore genealógica (T1-R02)

A árvore é um **grafo**, não uma hierarquia. Cônjuges são ligação horizontal, noras e genros entram por afinidade, segundos casamentos criam meio-irmãos. Cada vínculo é uma aresta tipada entre duas entidades do mesmo espaço.

- `GET /api/spaces/{space}/family` — devolve `nodes` (perfis), `edges` (vínculos) e `relation_types` (dicionário para montar o seletor sem duplicar rótulo no frontend). Cada aresta traz `label`, `inverse_type` e `inverse_label` já calculados.
- `POST /api/spaces/{space}/family` — body `from_entity_id`, `to_entity_id`, `relation_type`, `note?`. Leitura: *(from) É (relation_type) DE (to)*.
- `DELETE /api/spaces/{space}/family/{relationship}`

**Tipos:** `parent_of`, `child_of`, `grandparent_of`, `grandchild_of`, `guardian_of`, `ward_of`, `spouse_of`, `sibling_of`, `parent_in_law_of`, `child_in_law_of`, `sibling_in_law_of`, `caretaker_of`, `pet_of`.

Simétricos (`spouse_of`, `sibling_of`, `sibling_in_law_of`) são gravados **uma vez** e valem nos dois sentidos. Os demais têm inverso derivado na leitura — não grave o inverso à mão, ou a árvore fica com vínculo duplicado.

`parent_of` cobre pai e mãe de propósito: o gênero está na entidade, não no parentesco. Assim adoção, duas mães e dois pais entram sem caso especial.

**Erros:** `SELF_RELATION` (422), `ENTITY_NOT_IN_SPACE` (422), `DUPLICATE_RELATION` (422), `CYCLE_DETECTED` (422 — o vínculo colocaria a mesma pessoa como ascendente e descendente).

**Sem limite de perfis por conta (T1-R01).** A cobrança é por crédito de QR Code, não por perfil: pessoa da família pode existir na árvore sem ter QR próprio.

### Verificação em duas etapas (T1-R05)

TOTP padrão (SHA1, 6 dígitos, 30 s), compatível com Google Authenticator, Authy e Microsoft Authenticator. Implementado sem biblioteca externa — `composer require` em CPanel é ponto recorrente de falha de deploy, e o algoritmo é padrão fechado.

- `GET /api/2fa/status`
- `POST /api/2fa/setup` — gera segredo, devolve `secret` e `provisioning_uri`. **Não ativa nada ainda.**
- `POST /api/2fa/confirm` `{code}` — ativa e devolve os `recovery_codes` **uma única vez**.
- `POST /api/2fa/verify` `{code}` — aceita código do app ou de recuperação.
- `POST /api/2fa/disable` `{code}` — exige código válido.

O fluxo é de duas etapas de propósito: sem a confirmação, quem fecha a tela antes de escanear ficaria trancado fora da conta com um segredo que não guardou.

**Escopo honesto:** o 2FA **não é exigido no login** — o login é do Firebase, e interceptá-lo exigiria trocar o fluxo de autenticação inteiro. Ele protege as operações sensíveis do próprio sistema (repasse, alteração de permissão, revelação de dado).

### Botão de Pânico (T1-R07) — versão rústica

**Decisão do proprietário (06/08/2026):** sai agora, sem esperar o WhatsApp. O frontend é instalado como app (PWA) e funciona ele próprio como alarme; o backend registra o acionamento e avisa a família pelos canais que existem hoje (e-mail).

- `POST /api/spaces/{space}/panic` — autenticado, do app instalado. Body: `latitude?`, `longitude?`, `location_accuracy?`, `note?`, `entity_id?`. Exige permissão `panic.trigger`.
- `POST /api/entities/{unique_code}/panic` — **público**, para quem leu o QR. Sem autenticação por definição: quem encontrou a pessoa na rua não tem conta. Throttle aplicado.
- `GET /api/spaces/{space}/panic` — histórico com contagem de avisados e falhas.
- `POST /api/panic/{event}/resolve` `{false_alarm?}` — exige `panic.configure`.

**Princípio que governa o código: nada impede o alerta.** O evento é gravado **antes** de qualquer envio; falha de um destinatário não interrompe os outros; falha de todos ainda devolve 201 com o evento criado, e o app continua tocando o alarme local. Resposta de erro que faz o app desistir é pior que alerta parcial.

A resposta pública **não** revela quem nem quantos foram avisados — isso mapearia a família para um estranho.

**Alarme no frontend:** sirene por Web Audio (dois tons alternados), vibração e tela vermelha. Começa **antes** da chamada à API e não para se ela falhar. Limitação de navegador, não do código: som exige gesto do usuário, então não há acionamento automático em segundo plano — isso pede app nativo, que é a versão definitiva.

**Quando o WhatsApp entrar:** basta registrar o driver no `NotificationDispatcher`. A ordem de canais já é `['whatsapp', 'mail']` e o template já se chama `panic_alert`. Nenhuma linha do `PanicController` muda.

## 4.3. Trilha Grupos e Causas

**Adicionado na Fase 3 (06/08/2026).** Requisitos T2-R01 a T2-R05.

### Espaços (F1) e cadastro sem CNPJ (T2-R01)

- `GET /api/spaces` — espaços do usuário, com as permissões efetivas em cada um.
- `POST /api/spaces` — body `type` (`family|cause|company|donation`), `name`, `organization_id?` e, para causa, `headline?`, `story?`, `category?`, `city?`, `state?`, `goal_amount?`.
- `GET /api/spaces/{space}`, `PUT /api/spaces/{space}`
- `POST /api/spaces/{space}/children` — body `child_space_id`.

**`organization_id` é opcional de propósito.** Criar uma causa não exige CNPJ: pessoa física liderando iniciativa autônoma usa o próprio CPF, já validado no Gate 1. Exigir CNPJ excluiria exatamente quem a trilha existe para atender.

**Guarda-chuva (T2-R02):** quem chama `children` é o dono do espaço-mãe, **nunca** o grupo apoiado. Se o filho pudesse se pendurar sozinho numa OSCIP, qualquer um se declararia apoiado por ela — e é esse vínculo que dá lastro fiscal ao recibo do doador. Erros: `SELF_PARENT`, `CYCLE_DETECTED`.

### Vitrine da causa (T2-R04, T2-R05)

Públicos:
- `GET /api/causes` — filtros `?category=`, `?state=`, `?q=`. Só causas publicadas.
- `GET /api/causes/{slug}` — história, números, prestação de contas, mídia aprovada e o guarda-chuva quando existir.

Autenticados:
- `PUT /api/spaces/{space}/cause`
- `POST /api/spaces/{space}/cause/publish` — body `publish`.

Publicar exige `headline` e `story` preenchidos (erro `INCOMPLETE_SHOWCASE`): causa sem história contada não convence a doar e ocupa espaço na listagem de quem se deu ao trabalho.

`raised_amount` é denormalizado — a vitrine é pública e não pode somar a tabela de doações a cada visita. Atualizado quando a doação é confirmada (Fase 4).

### Mídia com moderação (T2-R05)

- `POST /api/spaces/{space}/media` — **multipart**, campo `file` e `caption?`. Máx. 20 MB. Aceita JPG, PNG, WEBP, MP4, MOV.
- `GET /api/spaces/{space}/media` — inclui pendentes, para quem modera.
- `POST /api/media/{media}/moderate` — body `approve`, `reason?`.
- `DELETE /api/media/{media}`
- `GET /api/media/{media}` — **público, mas só serve mídia aprovada.**

Três regras que não se negociam, e o frontend não tem como burlar:

1. **O MIME é lido do conteúdo do arquivo, não da extensão.** Um `.jpg` que na verdade é PHP, servido de diretório executável, é execução remota. O nome gravado é gerado pelo servidor — nome enviado pelo usuário é vetor de path traversal e extensão dupla.
2. **Storage privado** (disco `private`, em `storage/app/media`). Nada em `public/`. Exige a chave `private` em `config/filesystems.php` — já incluída.
3. **Nasce `pending`.** Foto de terceiro pode trazer rosto de menor, endereço legível ao fundo, documento sobre a mesa. Publicação automática é inaceitável.

### QR Codes em lote (T2-R03)

- `POST /api/spaces/{space}/qr-batches` — body `quantity` (1 a 500), `label?`.
- `GET /api/spaces/{space}/qr-batches`
- `GET /api/qr-batches/{batch}`
- `GET /api/qr-batches/{batch}/print` — **folha A4 em HTML**, 24 etiquetas por página, com guias de corte.

**Dois "lotes" no sistema, nomes parecidos, coisas diferentes:** `credit_batches` é o lote de CRÉDITO comprado; `qr_print_batches` é o lote de IMPRESSÃO. O segundo consome o primeiro.

**As entidades do lote nascem `pending_term`.** A etiqueta existe e pode ser colada, mas a página pública só abre depois que alguém assume a responsabilidade e aceita o termo. Gerar já ativo criaria centenas de páginas públicas sem responsável — exatamente o que a arquitetura de termos existe para impedir.

**HTML e não PDF de propósito:** gerar PDF exigiria dependência nova (dompdf/mpdf), e `composer require` em CPanel é ponto recorrente de falha de deploy. O navegador imprime HTML em PDF com o mesmo resultado prático.

A folha abre em aba nova, que não carrega o header `Authorization` — por isso o link leva o token em `?id_token=`, forma que o `FirebaseAuth` já aceita.

## 5. Catálogo de Endpoints

| Método | Path | Auth | Request (JSON/Body) | Response Sucesso | Erros Possíveis | Notas |
|---|---|---|---|---|---|---|
| POST | `/api/auth/send-otp` | Não | `email`, `firebase_uid` | 200: `{"message": "..."}` | 422, 500 (Falha SMTP) | Throttle aplicado. |
| POST | `/api/auth/verify-otp` | Não | `firebase_uid`, `code` (6 dígitos) | 200: E-mail verificado | 400 (Código inválido/expirado) | Marca verificação e checa Gate 1. |
| GET | `/api/auth/me` | Sim | N/A | 200: Objeto Tenant | 401 | |
| GET | `/api/profile` | Sim | N/A | 200: Dados do tenant e missing fields | 401 | Usar para montar onboarding UI. |
| PUT | `/api/profile` | Sim | `name`, `phone`, `address_*` | 200: Perfil atualizado | 422 | Ativa perfil se Gate 1 cumprido. |
| POST | `/api/profile/documents`| Sim | `document_type`, `document_number`, ... | 200: Documento criado | 422 (CPF inválido) | |
| GET | `/api/spaces` | Sim | N/A | 200: `spaces[]` com permissões | 401 | |
| POST | `/api/spaces` | Sim | `type`, `name`, `organization_id?` | 201: espaço criado | 403 (perfil incompleto) | CNPJ não é exigido (T2-R01). |
| POST | `/api/spaces/{id}/children` | Sim | `child_space_id` | 200 | 422 (`SELF_PARENT`, `CYCLE_DETECTED`) | Chamado pelo guarda-chuva. |
| GET | `/api/causes` | **Não** | `?category=`, `?state=`, `?q=` | 200: `causes[]` | — | Só publicadas. |
| GET | `/api/causes/{slug}` | **Não** | N/A | 200: vitrine + mídia aprovada | 404 | |
| PUT | `/api/spaces/{id}/cause` | Sim | `headline`, `story`, `accountability`... | 200 | 403, 404 | |
| POST | `/api/spaces/{id}/cause/publish` | Sim | `publish` | 200: `public_url` | 422 (`INCOMPLETE_SHOWCASE`) | Exige chamada e história. |
| POST | `/api/spaces/{id}/media` | Sim | multipart: `file`, `caption?` | 201: status `pending` | 422 (`INVALID_MIME`) | Máx. 20 MB. MIME lido do conteúdo. |
| POST | `/api/media/{id}/moderate` | Sim | `approve`, `reason?` | 200 | 403, 404 | Exige `space.edit`. |
| GET | `/api/media/{id}` | **Não** | N/A | 200: arquivo | 404 | Só serve mídia aprovada. |
| POST | `/api/spaces/{id}/qr-batches` | Sim | `quantity`, `label?` | 201: `print_url` | 402 (`INSUFFICIENT_CREDITS`) | Máx. 500 por lote. |
| GET | `/api/qr-batches/{id}/print` | Sim | `?id_token=` | 200: folha A4 em HTML | 403, 404 | Aba nova não leva header. |
| GET | `/api/spaces/{id}/family` | Sim | N/A | 200: `nodes[]`, `edges[]`, `relation_types[]` | 403, 404 | Grafo de parentesco. Ver §4.2. |
| POST | `/api/spaces/{id}/family` | Sim | `from_entity_id`, `to_entity_id`, `relation_type` | 201: vínculo criado | 422 (ciclo, duplicata, fora do espaço) | Exige `entity.edit`. |
| DELETE | `/api/spaces/{id}/family/{rel}` | Sim | N/A | 200 | 403, 404 | SoftDelete, mantém rastreabilidade. |
| GET | `/api/2fa/status` | Sim | N/A | 200: `enabled`, `pending_setup` | 401 | |
| POST | `/api/2fa/setup` | Sim | N/A | 200: `secret`, `provisioning_uri` | 422 (já ativo) | Não ativa ainda. |
| POST | `/api/2fa/confirm` | Sim | `code` | 200: `recovery_codes[]` | 422 | Códigos exibidos uma única vez. |
| POST | `/api/2fa/verify` | Sim | `code` | 200: `verified`, `method` | 422 | Aceita código do app ou de recuperação. |
| POST | `/api/2fa/disable` | Sim | `code` | 200 | 422 | Exige código válido. |
| POST | `/api/spaces/{id}/panic` | Sim | `latitude?`, `longitude?`, `note?` | 201: `event_id`, `notified`, `failed` | 403 | Exige `panic.trigger`. |
| POST | `/api/entities/{code}/panic` | **Não** | `latitude?`, `longitude?` | 201: `event_id`, `notified` | 404 | Público, com throttle. Não revela a família. |
| GET | `/api/spaces/{id}/panic` | Sim | N/A | 200: `events[]` | 403 | Histórico, últimos 50. |
| POST | `/api/panic/{id}/resolve` | Sim | `false_alarm?` | 200 | 403, 404 | Exige `panic.configure`. |
| GET | `/api/me/accounts` | Sim | N/A | 200: `accounts[]`, `linked`, `total` | 401 | Contas do mesmo CPF. Nunca recebe CPF. |
| GET | `/api/me/links` | Sim | N/A | 200: `links[]`, `by_type`, `total` | 401 | Vínculos da pessoa em espaços. |
| POST | `/api/me/switch-account` | Sim | `target_tenant_id` | 200: `target`, `method: "reauth"` | 404 (não é sua), 422 (sem CPF / já é a atual) | Troca assistida. Ver §4.1. |
| GET | `/api/entities` | Sim | `?organization_id=` (opcional) | 200: Lista de QRs e `quota` | 401, 403 (Nenhuma org vinculada) | Retorna entidades e cota ativa. |
| POST | `/api/entities` | Sim | `type`, `name`, `accept_term`, infos... | 201: URL e base64 do QR | 402, 403, 422 | Ver fluxo canônico (Gate 2). |
| GET | `/api/entities/{code}/qrcode` | Sim | `?format=svg|json`, `?size=512` | 200: SVG cru ou JSON c/ Base64 | 403, 404, 503 | Só o dono pode gerar/baixar a imagem. |
| GET | `/api/admin/tenants` | Sim | N/A | 200: Lista de Tenants e métricas | 403 (Não superadmin) | Apenas superadmin. |
| POST | `/api/admin/batches` | Sim | `organization_id`, `amount`, `expires_at` | 201: Lote de créditos criado | 403 (Não superadmin), 422 | Única forma real de dar créditos. |
| POST | `/api/admin/batches/{id}/toggle` | Sim | N/A | 200: Status alterado | 403, 404 | Alterna entre `active`/`suspended`. |
| GET | `/api/messages` | Sim | N/A | 200: Lista de mensagens recebidas | 401 | Mensagens de todas entidades do tenant. |
| POST | `/api/messages/{id}/read` | Sim | N/A | 200: `{"success": true}` | 401, 404 | Marca mensagem como lida. |
| GET | `/api/entities/{code}` | Não | N/A | 200: Dados públicos da entidade | 404 (Inativo/Inexistente) | Oculta dados sensíveis (Fix 10). |
| POST | `/api/entities/{code}/messages`| Não | `sender_name`, `message`, etc. | 201: `{"message": "..."}` | 404, 422 | Quem leu o QR Code manda mensagem. |
| GET | `/api/credits/mp-public-config` | Sim | N/A | 200: `{"public_key": "...", "mode": "..."}` | 401 | Retorna a chave pública para inicializar o Payment Brick. |
| GET | `/api/credits/pricing` | Sim | N/A | 200: Preços configurados | 401 | Retorna preço e limites (min/max). |
| POST | `/api/credits/checkout` | Sim | `quantity` | 201: `pix.qr_code`, `order_id` | 403, 422 | Só `profile_status=active`. Gera pedido PIX (Checkout API). |
| POST | `/api/credits/checkout/card`| Sim | `quantity`, `token`, `payment_method_id` | 201: `order_id`, `status` | 403, 422, 502 | Gera pagamento via Cartão. Aprova imediato se status approved. |
| GET | `/api/credits/orders/{id}` | Sim | N/A | 200: `status`, `mp_payment_id`, etc. | 404 | Verifica status do pedido de crédito (polling). |
| PUT | `/api/admin/credits/pricing` | Sim | `unit_price`, `min_quantity`, `max_quantity` | 200: Atualizado | 403 | Apenas superadmin. |
| POST | `/webhooks/mercadopago` | Não | Payload Mercado Pago | 200: ok | 401 | Valida `x-signature` e libera `CreditBatch`. |

## 6. Fluxo Canônico — Criar Entity (QR Code)

Para gerar uma nova entidade (QR Code), o frontend deve lidar com o status do usuário progressivamente:

1. Autenticar no Firebase. Obter Token.
2. Chamar `GET /api/profile`. Verificar `can_create_entity`. Se for falso, inspecionar `missing_for_purchase` e `missing_for_entity`.
3. Pedir os dados faltantes ao usuário em telas de onboarding:
   - Fazer verificação de e-mail (OTP).
   - Enviar CPF via `POST /api/profile/documents`.
   - Enviar Telefone e Endereço via `PUT /api/profile`.
4. Garantir que a organização do usuário tem créditos ativos. (*Nota: Sem integração de compra no frontend atual, o crédito deve ter sido concedido via admin*).
5. Exibir Termo de Responsabilidade para o `type` escolhido.
6. Enviar `POST /api/entities` com Payload completo:
   ```json
   {
     "type": "pet",
     "name": "Rex",
     "accept_term": true,
     "contact_phone": "11999999999",
     "contact_email": "dono@email.com",
     "medical_info": "Alérgico a dipirona",
     "additional_info": "Dócil",
     "custom_attributes": {
       "Raca": "Golden Retriever"
     }
   }
   ```
7. Tratar os retornos:
   - **403 - PROFILE_INCOMPLETE**: O usuário não passou no Gate 1.
   - **403 - ADDRESS_REQUIRED**: Endereço faltando. Redirecionar para form de endereço.
   - **403 - TERM_REQUIRED**: Checkbox de termo obrigatória.
   - **402 - Saldo Insuficiente**: Lote da organização esgotado.
   - **201 - Sucesso**: Retorna `unique_code`, `url` pública e a string gráfica `qr_code_base64`.

## 7. Página Pública e Mensagens

Quando alguém escaneia o QR Code físico, aterra no frontend web do cliente.
O Frontend deve consultar os dados: `GET /api/entities/{unique_code}`.

- **Privacidade da Entidade Pública (Fix 10):** A API **NÃO retorna** `contact_phone`, `contact_email`, nem `medical_info` no endpoint público. O que retorna é: `type`, `name`, `additional_info`, `custom_attributes`, e o nome da `organization`.
- Se a entidade estiver bloqueada, pendente ou suspensa, a API retorna **404**.
- O frontend público deve exibir os dados disponíveis e um formulário para entrar em contato com o proprietário: `POST /api/entities/{unique_code}/messages`. 
- Pelo lado do proprietário, ele verá essas mensagens no dashboard usando `GET /api/messages` e pode marcá-las como lidas usando `POST /api/messages/{id}/read`.

## 8. QR Code (Whitelabel de Infraestrutura)

- **A URL gravada fisicamente no QR** é controlada no backend pelos configs (`QR_PUBLIC_BASE_URL` e `QR_PUBLIC_PATH_PREFIX`).
  - *Exemplo de URL gravada:* `https://qrdobem.com.br/q/uuid-da-entidade`.
- **Como pegar a imagem:** 
  - Na resposta da criação, o campo `qr_code_base64` já traz o `data:image/svg+xml;base64,...` pronto para exibir em tag `<img>`.
  - Para re-obter ou baixar em outro tamanho: `GET /api/entities/{code}/qrcode?format=svg&size=1024`.

## 9. Admin e Créditos

- **Quem é admin:** Tenants com `role: 'superadmin'`. O código não oferece rota de auto-promoção; precisa ser setado via DB diretamente.
- **Crédito de Onboarding:** Quando o Tenant atinge o `profile_status: 'active'` (ao concluir o Gate 1), a API concede automaticamente um lote inicial de créditos (default: 3) para a sua organização Matriz. A quantidade é configurada no backend (`config/qrdobem.php` / `.env` `QR_ONBOARDING_CREDITS`). Essa ação é **idempotente**; a API identifica lotes com `source: 'onboarding'` e garante que o bônus seja dado apenas uma vez.
- **Créditos Adicionais (Batches):** Toda entidade criada subtrai 1 de cota de um `CreditBatch` ativo da Organização. Cotas podem ser inseridas manualmente via admin (`POST /api/admin/batches`) ou adquiridas pelo usuário final via checkout. Os sources do `CreditBatch` podem ser `onboarding` ou `mercadopago`.
- **Compra de Créditos (Checkout Transparente):** O frontend consulta o preço base (`GET /api/credits/pricing`) e envia **apenas** a quantidade para gerar o pedido PIX (`POST /api/credits/checkout`) ou processar cartão (`POST /api/credits/checkout/card`). O frontend **nunca envia preço**, pois o total é calculado no backend. A resposta do PIX traz o `qr_code` copia-e-cola e o base64 da imagem. O frontend não usa mais redirecionamento externo. O crédito (CreditBatch) é liberado no recebimento do Webhook validado (`POST /webhooks/mercadopago`) para PIX ou de forma síncrona na aprovação do Cartão. O webhook e a rota síncrona compartilham a mesma regra de aprovação. Variáveis exigidas no backend: `MERCADOPAGO_PUBLIC_KEY_*`, `MERCADOPAGO_ACCESS_TOKEN_*`, `MERCADOPAGO_WEBHOOK_SECRET`, `FRONTEND_URL`, `CREDITS_*`.

## 10. CORS, Ambientes e Configuração do Cliente

- **CORS:** O arquivo `config/cors.php` está configurado para aceitar tráfego de `https://*.qrdobem.com.br`, `http://localhost:5173` e `http://localhost:3000`. Headers customizados não devem ser criados arbitrariamente nas rotas.
- **Integração no Frontend (.env):** O frontend deve obrigatoriamente possuir em seu `.env`:
  - A Base URL desta API.
  - As credenciais do Firebase Client (apiKey, authDomain, projectId, etc.) para autenticar e gerar o JWT.

## 11. Códigos de Erro Específicos e UX Recomendada

| Código HTTP | Campo `code` / Conteúdo | Significado (Código Real) | Ação de UX Sugerida |
|---|---|---|---|
| 401 | N/A | Token ausente, inválido ou expirado. | Fazer Logout silencioso, redirecionar para tela de Login (Firebase). |
| 403 | `PROFILE_INCOMPLETE` | Gate 1 (Nome/CPF/Tel/Email) incompleto. | Exibir modal ou redirecionar para finalização de cadastro. |
| 403 | `ADDRESS_REQUIRED` | Gate 2 (Endereço) não preenchido. | Mostrar formulário de Endereço do usuário. |
| 403 | `TERM_REQUIRED` | Consentimento não foi enviado no payload. | Exibir caixa de consentimento ("Eu aceito...") e reenviar Request. |
| 402 | Mensagem de saldo | Organização sem saldo no CreditBatch. | Bloquear form de criação e orientar a contatar o administrador. |
| 404 | Registro não encontrado | Entidade não existe ou `is_active=false`. | Mostrar página de "Perfil indisponível ou inativo". |
| 422 | Erros do Laravel (`errors`) | Validação comum ou CPF matematicamente incorreto. | Realçar campos vermelhos no formulário com a mensagem de erro. |

## 12. Checklist "Novo Frontend em Domínio X" (Para IAs Futuras)

Se uma IA for instruída a construir um cliente visual (Frontend/PWA) do zero usando esta API, ela DEVE realizar as seguintes perguntas ao desenvolvedor responsável ANTES de escrever código:

1. **URL Base:** Qual é a URL exata em produção desta API (ex: api.meudominio.com)?
2. **Configuração Firebase:** Por favor, forneça as variáveis do SDK cliente do Firebase (apiKey, projectId, etc.) para configurar a autenticação.
3. **Escopo das Trilhas:** O seu frontend vai oferecer a gestão completa dos 3 tipos (`person`, `pet`, `object`), ou será especializado em apenas um?
4. **Resolução Externa de URL:** Qual domínio as pessoas verão ao ler o QR Code (para certificar que a env da API `QR_PUBLIC_BASE_URL` confere com seu roteamento web frontend)?
5. **Painel de Créditos:** A API possui checkout embarcado para PIX (`/checkout`) e Cartão (`/checkout/card` via Payment Brick). Como a interface exibirá a opção de recarga quando houver "Falta de Créditos" (Erro 402)?
6. **Layout Superadmin:** O novo frontend precisará de telas para acessar as rotas `/api/admin/*`?

### Ordem de Implementação Sugerida (Frontend)
1. Firebase Auth UI (Login/Registro).
2. Interceptor HTTP para injetar JWT.
3. Tela de Perfil e Onboarding progressivo (Consumindo `GET/PUT /profile` e `POST /documents`).
4. Verificação OTP (Envio e Confirmação de e-mail).
5. Dashboard da Organização (Listagem de Entidades do `GET /entities`).
6. Formulário de Criação de Entidade com Tratamento de Erros 403 (Termos e Endereço).
7. Inbox de Mensagens (para o Proprietário ler).
8. Página Pública Não Autenticada (Rota lida pelo QR Code real na rua) e form para enviar mensagem.

### Critérios de Aceite Front-end
- O form de Entidade DEVE refletir as pré-condições reais da API antes de deixar tentar criar (conferindo flags `can_create_entity`).
- Se houver `403 ADDRESS_REQUIRED`, a UI não pode quebrar, deve guiar graciosamente ao form de endereço.
- Privacidade na tela pública DEVE estar de acordo com o código: telefones e emails não aparecem no endpoint público.

## 13. Exemplos JSON Reais

### `POST /auth/verify-otp` (Sucesso 200)
```json
{
  "message": "E-mail verificado com sucesso.",
  "profile_status": "active"
}
```

### `GET /profile` (Usuário Incompleto)
```json
{
  "tenant": {
    "id": 1,
    "name": "Maria Silva",
    "email": "maria@email.com",
    "profile_status": "incomplete",
    ...
  },
  "documents": [],
  "missing_for_purchase": ["cpf", "phone"],
  "missing_for_entity": ["cpf", "phone", "address"],
  "can_purchase": false,
  "can_create_entity": false
}
```

### `POST /entities` (Sucesso 201)
```json
{
  "message": "Entidade registrada com sucesso.",
  "unique_code": "550e8400-e29b-41d4-a716-446655440000",
  "url": "https://qrdobem.com.br/q/550e8400...",
  "qr_code_base64": "data:image/svg+xml;base64,PHN2ZyB...",
  "qr_code_url": "https://api.qrdobem.com.br/api/entities/550e8400.../qrcode"
}
```

### `GET /entities/{code}` (Página Pública 200)
```json
{
  "type": "pet",
  "name": "uG1+34z...", 
  "additional_info": "u1C...", 
  "custom_attributes": {
    "Raça": "Poodle"
  },
  "organization": "Matriz de Maria Silva"
}
```
*(Obs: `name` e `additional_info` estão criptografados via Model Caster no backend e serão decodificados na saída dependendo das traits de criptografia. Os campos protegidos não são emitidos de acordo com o código.)*

### `POST /entities` (Erro 403 - Termo Necessário)
```json
{
  "error": "É necessário aceitar o termo de responsabilidade.",
  "code": "TERM_REQUIRED",
  "term_type": "responsibility_pet",
  "term_version": "1.0"
}
```

## 14. Limitações Conhecidas e Dívidas da API

- **Criptografia na API Pública:** Embora a query busque os campos `$entity->encrypted_name`, a model possui Castings que podem descriptografar magicamente se estiver usando a Trait correta. O Frontend público lidará com o texto limpo, mas a documentação nota que os dados em repouso estão cifrados.
- **Múltiplas Organizações (UX B2B):** A modelagem permite que um tenant pertença a várias organizações. Contudo, nas rotas atuais como `/entities`, a API escolhe silenciosamente a `organizations()->first()` caso o frontend não mande `organization_id` no Request. O Frontend idealmente deve listar e permitir a troca de organização ativa.
- **Notificações:** O envio de mensagens via `POST /entities/{code}/messages` apenas persiste no banco (`EntityMessage`). Não há envio de Push Notification ou E-mail para o proprietário avisando da chegada da mensagem (apenas o que está no escopo do controller lido).
