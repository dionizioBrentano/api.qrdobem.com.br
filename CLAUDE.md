# Regras do projeto — API QR do Bem

Plano de execução: `C:\qrdobem\qrdobemDev\PLANO_EXECUCAO_AVENTURA_FAMILIA.md`

0. CODIFIQUE. Não faça diagnóstico, auditoria, exploração nem verificação de
   ambiente. Não rode git status, git log, git diff, php -v, npm ls, which,
   ls de reconhecimento. Abra apenas os arquivos que o passo manda alterar.
   Não peça confirmação no meio: execute o passo inteiro e feche com o commit.
1. NÃO invente endpoints, campos, rotas ou comportamentos. Se algo do escopo
   não existir no código atual, PARE e diga o que falta.
2. NÃO hardcode URLs de API, tokens, project IDs ou textos de termo.
3. Siga os padrões existentes: $request->tenant, canAccessEntity(),
   response()->json().
4. NÃO altere arquivos fora do escopo do passo.
5. NÃO refatore por estética. Mudança mínima e local.
6. NÃO deixe console.log/dd() de debug, código comentado morto ou TODO genérico.
7. Respeite os códigos reais da API (PROFILE_INCOMPLETE, ADDRESS_REQUIRED,
   TERM_REQUIRED, 402 saldo).
8. NÃO rode migration, seeder ou teste local. NÃO instale pacote PHP nem
   extensão. A validação lógica acontece no servidor.

## NÃO MEXER, EM NENHUM PASSO
- PanicController e o fluxo de pânico que funciona
- MessageController
- EntityRead, entity_reads, histórico de leituras
- qr_caption e o fluxo de impressão/legenda
- O CRUD paralelo por trilha (registration_tokens.trail)

## Fecho de cada passo
1. php -l em cada arquivo .php alterado (se `php` não existir, pule)
2. git add .
3. git commit -m "<referência significativa>"
4. git push
5. NÃO rode migrate. Isso é feito no servidor pelo dono.
6. Liste os arquivos alterados, 1 linha por arquivo.
