<!DOCTYPE html>
<html>
<head>
    <title>Link de Cadastro</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h2 style="color: #333333; text-align: center;">Bem-vindo ao QR do Bem!</h2>
        <p style="color: #666666; font-size: 16px; line-height: 1.5;">Para iniciar o seu cadastro na plataforma, clique no botão abaixo:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $link }}" style="display: inline-block; font-size: 18px; font-weight: bold; color: #ffffff; background: #4285F4; padding: 15px 30px; border-radius: 8px; text-decoration: none;">
                Completar Cadastro
            </a>
        </div>

        <p style="color: #666666; font-size: 14px;">Ou copie e cole o link no seu navegador:</p>
        <p style="color: #4285F4; font-size: 12px; word-break: break-all;">{{ $link }}</p>

        <p style="color: #666666; font-size: 14px;">Este link expira em 24 horas. Se você não solicitou este e-mail, pode ignorá-lo com segurança.</p>
        
        <hr style="border: none; border-top: 1px solid #eeeeee; margin: 30px 0;" />
        <p style="color: #999999; font-size: 12px; text-align: center;">Equipe QR do Bem</p>
    </div>
</body>
</html>
