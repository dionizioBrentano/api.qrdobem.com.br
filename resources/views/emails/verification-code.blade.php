<!DOCTYPE html>
<html>
<head>
    <title>Código de Verificação</title>
</head>
<body style="font-family: Arial, sans-serif; background-color: #f4f4f4; padding: 20px;">
    <div style="max-width: 600px; margin: 0 auto; background: #ffffff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1);">
        <h2 style="color: #333333; text-align: center;">Bem-vindo ao QR do Bem!</h2>
        <p style="color: #666666; font-size: 16px; line-height: 1.5;">Para confirmar o seu cadastro e acessar a plataforma, utilize o código de segurança abaixo:</p>
        
        <div style="text-align: center; margin: 30px 0;">
            <span style="display: inline-block; font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #4285F4; background: #F8F9FA; padding: 15px 30px; border-radius: 8px; border: 2px dashed #4285F4;">
                {{ $code }}
            </span>
        </div>

        <p style="color: #666666; font-size: 14px;">Este código expira em 15 minutos. Se você não solicitou este e-mail, pode ignorá-lo com segurança.</p>
        
        <hr style="border: none; border-top: 1px solid #eeeeee; margin: 30px 0;" />
        <p style="color: #999999; font-size: 12px; text-align: center;">Equipe QR do Bem</p>
    </div>
</body>
</html>
