# QR do Bem — Arquitetura de Registro e Validação

**Data:** 01/07/2026  
**Status:** Aprovado em sessão de trabalho  

---

## 1. Princípio: Separação de Camadas

| Camada | Responsável | Escopo |
|---|---|---|
| Autenticação | Firebase | "Quem é você?" — valida email+senha ou Google. Devolve token JWT. Acabou. |
| Registro/Onboarding | Backend QR do Bem | Coleta progressiva de dados do usuário, sem fricção. |
| Autorização | Backend QR do Bem | Gates por operação: o que o usuário pode fazer depende dos dados que já forneceu. |

**Regra fundamental:** O `email_verified` do Firebase é IGNORADO. A validação de email é feita pelo sistema próprio via OTP enviado pelo Brevo.

---

## 2. Fluxo de Login Sem Fricção

1. Usuário faz login (email+senha ou Google) → Firebase devolve token
2. Middleware `FirebaseAuth` verifica token → busca ou cria tenant
3. Auto-cadastro cria: Tenant (`profile_status='incomplete'`) + Organização Matriz + vínculo `organization_user`
4. Usuário acessa o dashboard imediatamente
5. Pode navegar, explorar, ver mensagens — **NÃO pode comprar créditos nem criar entidades (QR Codes)**

---

## 3. Coleta Progressiva de Dados

Dados coletados conforme necessidade, nunca como barreira de entrada:

| Momento | Dados | Como |
|---|---|---|
| Login | Email | Vem do Firebase (Google ou cadastro) |
| Perfil (voluntário) | Nome, Telefone | Usuário preenche quando quiser no perfil |
| Verificação de email | Email validado via OTP | Sistema envia código pelo Brevo, usuário confirma |
| Compra via PIX | CPF | Exigido no momento da compra |
| Compra via Cartão | CPF + Endereço completo | Mercado Pago exige para cartão |
| Uso do QR Code | Endereço + Termo de responsabilidade | Exigido ao criar entidade |

---

## 4. Gates (Portas de Validação)

### Gate 1 — Pode Comprar (`profile_status = 'active'`)

Requisitos obrigatórios:
- Email verificado pelo OTP do sistema (campo `email_verified_at` preenchido)
- CPF cadastrado e válido (dígito verificador)
- Telefone cadastrado

Quando todos estiverem preenchidos, `profile_status` muda automaticamente para `active`.

### Gate 2 — Pode Usar QR Code (criar entidade)

Requisitos obrigatórios (além de ser `active` e ter crédito):
- Endereço completo cadastrado (CEP, rua, número, bairro, cidade, estado)
- Termo de responsabilidade aceito para o tipo de entidade (pessoa, pet ou objeto)
- Aceite registrado com: IP, timestamp, versão do termo, CPF do aceitante

---

## 5. Termos de Responsabilidade

Cada tipo de entidade tem um termo específico:

| Tipo | Risco | Termo cobre |
|---|---|---|
| Objeto | Baixo | Responsabilidade sobre o conteúdo vinculado ao QR |
| Pet | Médio | Declaração de propriedade/guarda do animal |
| Pessoa | Alto | Declaração de responsabilidade legal (tutela, curatela, filiação) sobre pessoa vulnerável |

O aceite é registrado por entidade, não por perfil. Cada entidade criada exige aceite do termo correspondente ao tipo.

---

## 6. Rastreabilidade

O que protege o QR do Bem juridicamente:

- **Quem cadastrou:** tenant_id (vinculado a CPF real)
- **Quando:** timestamps em todas as operações
- **De onde:** IP registrado no audit_log e no aceite do termo
- **O que fez:** histórico de entidades criadas, termos aceitos, créditos consumidos
- **Versão do termo:** versionamento dos termos para garantir que o usuário aceitou a versão vigente

---

## 7. Tabela `tenant_documents`

Armazena todos os documentos de identificação do usuário com criptografia AES-256-CBC:

```
tenant_documents
├── id
├── tenant_id (FK)
├── document_type (string: 'cpf', 'cin', 'rg', 'cnh', 'passport', etc.)
├── document_number (encrypted)
├── document_country (string: 'BR', 'US', etc. — default 'BR')
├── is_primary (boolean)
├── verified_at (timestamp, nullable)
├── timestamps
```

Tipos suportados (Brasil): CPF, CIN, RG, CNH, Passaporte, Certidão de Nascimento, Certidão de Casamento, Título de Eleitor, CTPS, Carteira Profissional, CRNM, Certificado de Reservista, Cartão SUS, PIS/PASEP, CNPJ.

Tipos suportados (Internacional): Passport, National ID, Driver's License, SSN, TIN, Aadhaar, DNI, NIE.

---

## 8. Integração Mercado Pago

### PIX
Dados necessários do pagador (já temos quando `active`):
- Email
- Nome completo (first_name + last_name)  
- CPF (type: "CPF", number: "XXXXXXXXXXX")

### Cartão de Crédito
Dados adicionais (coletamos para Gate 2):
- Endereço completo (CEP, rua, número, bairro, cidade, estado)

**Dados do cartão:** Mercado Pago cuida no formulário deles (PCI compliance é deles). Nós NUNCA armazenamos número de cartão.

**Fricção:** Zero adicional. Os dados que o Mercado Pago exige são os mesmos que já coletamos para as Gates 1 e 2.

---

## 9. Campos do Tenant (tabela `tenants`)

Campos existentes + novos campos necessários:

```
tenants
├── id
├── name
├── email (NOVO — salvar email do Firebase para uso interno)
├── phone (existe, nullable)
├── document_number (legado, manter por compatibilidade)
├── firebase_uid (unique)
├── role (enum)
├── qr_quota (legado, créditos agora são via credit_batches)
├── profile_status (enum: incomplete, active)
├── email_verified_at (NOVO — timestamp da verificação OTP)
├── address_street (NOVO, nullable)
├── address_number (NOVO, nullable)
├── address_complement (NOVO, nullable)
├── address_neighborhood (NOVO, nullable)
├── address_city (NOVO, nullable)
├── address_state (NOVO, nullable)
├── address_zipcode (NOVO, nullable)
├── is_active
├── timestamps
├── softDeletes
```

---

## 10. Tabela `tenant_term_acceptances`

Registra cada aceite de termo de responsabilidade:

```
tenant_term_acceptances
├── id
├── tenant_id (FK)
├── entity_id (FK, nullable — preenchido quando vinculado a uma entidade)
├── term_type (string: 'person', 'pet', 'object')
├── term_version (string: '1.0', '1.1', etc.)
├── ip_address (string)
├── user_agent (string)
├── accepted_at (timestamp)
├── timestamps
```

---

## 11. Status da Entidade

```
entities.status (NOVO campo)
├── pending_term — entidade criada, crédito debitado, termo não aceito
├── active — termo aceito, QR code operacional
├── suspended — desativado (denúncia, pedido do usuário)
```

O endpoint público `show()` só retorna dados se `status = 'active'`.

---

## 12. Pendências para Implementação

1. ~~Remover `email_verified` do FirebaseAuth.php~~ (feito no local)
2. Migration: adicionar campos novos ao `tenants`
3. Migration: criar `tenant_documents`
4. Migration: criar `tenant_term_acceptances`
5. Migration: adicionar `status` à tabela `entities`
6. Model: `TenantDocument` com encrypted cast
7. Model: `TenantTermAcceptance`
8. Controller: `ProfileController` (coleta progressiva)
9. Atualizar `OtpController.verifyOtp()` — setar `email_verified_at` em vez de `profile_status`
10. Atualizar `EntityController.store()` — implementar Gate 2
11. Criar endpoint de compra com Gate 1
12. Rota de aceite de termo por tipo
13. Termos de responsabilidade (textos jurídicos por tipo)
