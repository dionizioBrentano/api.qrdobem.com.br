<!DOCTYPE html>
<html>
<head>
    <title>Emergência declarada</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h2 style="color: #b91c1c; text-align: center;">Uma emergência foi declarada</h2>
        <p style="color: #666666; font-size: 16px; line-height: 1.5;">
            Alguém escaneou o QR Code de <strong>{{ $entityName }}</strong> e declarou uma situação de emergência.
        </p>
        <p style="color: #666666; font-size: 16px; line-height: 1.5;">
            As informações de saúde restritas deste registro passaram a ficar visíveis para quem está na página, conforme o termo aceito no cadastro.
        </p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $link }}" style="display: inline-block; font-size: 18px; font-weight: bold; color: #ffffff; background: #b91c1c; padding: 15px 30px; border-radius: 8px; text-decoration: none;">
                Abrir a página do QR
            </a>
        </div>

        <p style="color: #666666; font-size: 14px;">Ou copie e cole o link no seu navegador:</p>
        <p style="color: #b91c1c; font-size: 12px; word-break: break-all;">{{ $link }}</p>

        <hr style="border: none; border-top: 1px solid #eeeeee; margin: 30px 0;" />
        <p style="color: #999999; font-size: 12px; text-align: center;">Equipe QR do Bem</p>
    </div>
</body>
</html>
