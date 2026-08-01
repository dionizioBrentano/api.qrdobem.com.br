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
- **FORA DE ESCOPO / NÃO IMPLEMENTADO:** Integração com Mercado Pago (ou qualquer checkout de compra no frontend final), portal de doações, causas sociais, aventura ativa (GPS tracking contínuo/detecção de queda), whitelabel de marca completo (cores dinâmicas vindas da API), e aplicativo nativo.

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

## 5. Catálogo de Endpoints

| Método | Path | Auth | Request (JSON/Body) | Response Sucesso | Erros Possíveis | Notas |
|---|---|---|---|---|---|---|
| POST | `/api/auth/send-otp` | Não | `email`, `firebase_uid` | 200: `{"message": "..."}` | 422, 500 (Falha SMTP) | Throttle aplicado. |
| POST | `/api/auth/verify-otp` | Não | `firebase_uid`, `code` (6 dígitos) | 200: E-mail verificado | 400 (Código inválido/expirado) | Marca verificação e checa Gate 1. |
| GET | `/api/auth/me` | Sim | N/A | 200: Objeto Tenant | 401 | |
| GET | `/api/profile` | Sim | N/A | 200: Dados do tenant e missing fields | 401 | Usar para montar onboarding UI. |
| PUT | `/api/profile` | Sim | `name`, `phone`, `address_*` | 200: Perfil atualizado | 422 | Ativa perfil se Gate 1 cumprido. |
| POST | `/api/profile/documents`| Sim | `document_type`, `document_number`, ... | 200: Documento criado | 422 (CPF inválido) | |
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
- **Créditos Adicionais (Batches):** Toda entidade criada subtrai 1 de cota de um `CreditBatch` ativo da Organização. O único endpoint atual que injeta cotas manuais no sistema é o endpoint de superadmin: `POST /api/admin/batches`.
- **Ponto Importante:** Não existe fluxo de carrinho de compras / checkout implementado na API nesta versão para o usuário final comprar créditos por conta própria. A cota é gerida administrativamente ou fora do sistema.

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
5. **Painel de Créditos:** Dado que a API não possui endpoint de compra via cartão de crédito para usuários finais (somente o `/admin/batches` para superadmins), como você deseja que a interface trate a "Falta de Créditos" (Erro 402)?
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
- **Compra de Crédito pelo Usuário Final:** A API **NÃO IMPLEMENTA** endpoints abertos para o usuário comprar pacotes de QR Codes (Mercado Pago, Stripe, etc). Os lotes de crédito existem (`CreditBatch`), mas atualmente só superadmins podem inserir um lote. Qualquer promessa comercial de "Compre mais tags no app" necessitará de desenvolvimento backend, não apenas frontend.
- **Múltiplas Organizações (UX B2B):** A modelagem permite que um tenant pertença a várias organizações. Contudo, nas rotas atuais como `/entities`, a API escolhe silenciosamente a `organizations()->first()` caso o frontend não mande `organization_id` no Request. O Frontend idealmente deve listar e permitir a troca de organização ativa.
- **Notificações:** O envio de mensagens via `POST /entities/{code}/messages` apenas persiste no banco (`EntityMessage`). Não há envio de Push Notification ou E-mail para o proprietário avisando da chegada da mensagem (apenas o que está no escopo do controller lido).
