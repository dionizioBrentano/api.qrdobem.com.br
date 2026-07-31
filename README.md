# QR do Bem — API

Backend Laravel 13 do QR do Bem. Autenticação via Firebase (JWT RS256), banco MySQL.

A API é whitelabel: vários frontends consomem os mesmos endpoints. Por isso a URL pública do QR Code e a geração da imagem ficam aqui, não no cliente.

---

## Rodando em outra máquina

Requisitos: PHP 8.3+, Composer, MySQL.

```bash
git clone https://github.com/dionizioBrentano/api.qrdobem.com.br
cd api.qrdobem.com.br
composer install
cp .env.example .env
php artisan key:generate
```

Preencha no `.env`: `DB_*`, `MAIL_*`, `FIREBASE_PROJECT_ID` e o bloco `QR_*`. Os valores de produção **não** estão no repositório — pegue no `.env` do servidor.

```bash
php artisan migrate
php artisan serve
```

---

## Estrutura

```
app/Http/Middleware/FirebaseAuth.php   Valida o JWT e cria tenant + organização no 1º acesso
app/Http/Controllers/
  ├── EntityController      CRUD de entidades (QR Codes) + geração do QR
  ├── ProfileController     Coleta progressiva de perfil (CPF, telefone, endereço)
  ├── MessageController     Inbox e mensagens públicas
  ├── OtpController         Envio e verificação do código por email
  └── AdminController       Superadmin: tenants e lotes de crédito
app/Services/QrCodeService.php         Geração do SVG e montagem da URL pública
config/qrdobem.php                     Configuração do QR (URL, tamanho, correção de erro)
```

Modelo de dados: `Tenant` → `Organization` → `Entity` → `EntityMessage`. Créditos vivem em `CreditBatch`, ligados à organização.

---

## As duas gates

O cadastro não tem fricção na entrada: o usuário loga e navega com `profile_status = incomplete`. Os dados são pedidos só quando fazem falta.

**Gate 1 — comprar créditos:** email verificado por OTP + CPF válido + telefone.

**Gate 2 — criar QR Code:** tudo do Gate 1 + endereço completo + aceite do termo de responsabilidade (registrado com IP, user-agent, versão e timestamp).

---

## Documentos

- `ESTADO_ATUAL_E_ROADMAP.md` — o que existe, o que falta
- `ARQUITETURA_REGISTRO_VALIDACAO.md` — decisões sobre registro e validação
- `DEPLOY_2026-07-31.md` — passo a passo do deploy das correções de QR Code

---

## Deploy

cPanel → Git Version Control → Deploy HEAD Commit. As tarefas estão no `.cpanel.yml`.

Dependência nova no `composer.json` exige um passo manual via SSH antes do deploy:

```bash
cd ~/api.qrdobem.com.br && composer install --no-dev -o
```
