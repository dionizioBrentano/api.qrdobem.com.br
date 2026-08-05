# API Pública QR do Bem — v1

**Versão:** 1.0 · **Base URL:** `https://api.qrdobem.com.br/api/v1`
**Referência:** `PLANO_TRILHAS_2026-08.md`, T3-R01

Documentação da API aberta para parceiros corporativos. Para a API interna
(consumida pelo frontend do QR do Bem), ver `API_CONSUMO.md`.

---

## 1. Autenticação

Dois headers em toda requisição:

```
X-Api-Key: qdb_xxxxxxxxxxxxxxxxxxxxxxxx
X-Api-Secret: <segredo>
```

O par é emitido no painel do espaço (**Empresas → Chaves de API**). O
**segredo aparece uma única vez**, na criação — depois disso nem nós
conseguimos recuperá-lo, porque só o hash fica no banco. Perdeu, emita
outra chave e revogue a anterior.

### Escopos

| Escopo | Permite |
|---|---|
| `entities.read` | Listar e consultar QR Codes |
| `entities.write` | Criar QR Codes |
| `confirmations.read` | Consultar confirmações |
| `confirmations.write` | Registrar confirmações |
| `reports.read` | Relatórios de consumo |

Curinga por prefixo é aceito: `entities.*` cobre leitura e escrita.

### Limite de requisições

Configurado por chave (padrão: 60/min). É **por parceiro**, não global —
um integrador mal configurado não derruba a API dos outros. Excedido,
devolve `429` com `code: RATE_LIMITED`.

### Isolamento

Nenhum endpoint aceita `space_id` do cliente. A chave determina o espaço, e
o parceiro só enxerga os próprios dados.

---

## 2. Endpoints

### `GET /entities` — escopo `entities.read`

Parâmetros: `limit` (padrão 100).

```json
{
  "data": [
    {
      "code": "9b1f...uuid",
      "type": "object",
      "name": "Capacete 001",
      "status": "active",
      "url": "https://qrdobem.com.br/q/9b1f...uuid",
      "created_at": "2026-08-06T14:32:00-03:00"
    }
  ],
  "meta": { "count": 1 }
}
```

### `POST /entities` — escopo `entities.write`

```json
{
  "name": "Capacete 002",
  "type": "object",
  "contact_phone": "5551999999999"
}
```

Resposta `201`: `code` e `url`.

O QR nasce `active`: no B2B quem responde pelo uso é a empresa parceira,
que já aceitou o contrato de integração. O aceite de termo por entidade é
do fluxo B2C.

### `GET /entities/{code}` — escopo `entities.read`

`404` com `code: NOT_FOUND` quando o QR não pertence ao espaço da chave.

### `POST /confirmations` — escopo `confirmations.write`

```json
{
  "entity_code": "9b1f...uuid",
  "template_slug": "entrega-de-epi",
  "actor_external_id": "MAT-4471",
  "password": "senha-do-funcionario",
  "payload": {
    "equipment": "Capacete classe B",
    "ca_number": "31469",
    "quantity": 1
  }
}
```

**Se o template exige senha, sem senha válida não há registro.** A API não
é caminho de contorno da prova — é a mesma regra do motor interno.

Erros: `NOT_FOUND`, `TEMPLATE_NOT_FOUND`, `ACTOR_NOT_FOUND`,
`INVALID_PASSWORD`, `INVALID_PAYLOAD` (com o mapa de campos que faltaram).

### `GET /confirmations` — escopo `confirmations.read`

Parâmetros: `from`, `to` (datas ISO), `limit` (padrão 200).

---

## 3. Os três casos B2B

Certificação de EPI, liberação de material para terceirizado e portaria de
condomínio **usam os mesmos endpoints**. O que muda é o *template*.

Isso é decisão de arquitetura, não economia: os três são o mesmo primitivo
— evento de confirmação autenticada vinculado a um QR (quem, o quê, quando,
onde, com qual prova). Codificar como três módulos triplicaria a manutenção
e deixaria de fora o quarto caso que aparecer.

Moldes prontos, disponíveis na criação do template:

| `use_case` | Campos | Senha | Foto |
|---|---|---|---|
| `epi` | equipamento, nº do CA, quantidade, estado | sim | não |
| `logistics` | material, quantidade, destino, empresa terceira | sim | não |
| `concierge` | unidade/apto, transportadora, volumes | sim | sim |
| `custom` | livre | configurável | configurável |

---

## 4. Fora de escopo (T3-R08)

**Faturamento e emissão de nota fiscal não fazem parte do sistema.** É
responsabilidade da contabilidade externa. O que o QR do Bem oferece é a
**exportação dos dados de consumo**:

```
GET /api/spaces/{space}/confirmations?format=csv
```

CSV com separador `;` e BOM UTF-8 — o formato que o Excel em português
abre corretamente sem quebrar acentuação.

---

## 5. Códigos de erro

| HTTP | `code` | Significado |
|---|---|---|
| 401 | `MISSING_CREDENTIALS` | Faltam os headers |
| 401 | `INVALID_CREDENTIALS` | Chave ou segredo incorretos |
| 403 | `KEY_NOT_USABLE` | Chave revogada ou expirada |
| 403 | `INSUFFICIENT_SCOPE` | A chave não tem o escopo exigido |
| 404 | `NOT_FOUND` | Recurso inexistente ou de outro espaço |
| 422 | `INVALID_PASSWORD` | Senha do confirmador incorreta |
| 422 | `INVALID_PAYLOAD` | Campos obrigatórios do template faltando |
| 429 | `RATE_LIMITED` | Limite por minuto excedido |

A mensagem de `INVALID_CREDENTIALS` é idêntica para chave inexistente e
segredo errado — diferenciar permitiria descobrir quais `key_id` existem.

---

## 6. Versionamento

O caminho `/api/v1` é fixo. Quando houver v2, as duas convivem: parceiro
corporativo não atualiza integração no nosso ritmo, e quebrar a dele sem
aviso é a forma mais rápida de perder o contrato.
