<?php
// logout.php
session_start();

// Guardar el nombre del usuario para mostrar en el mensaje de despedida
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : '';

// Destruir todas las variables de sesión
$_SESSION = array();

// Si se desea destruir la sesión completamente, borre también la cookie de sesión
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destruir la sesión
session_destroy();
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cerrar Sesión - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/logout.css">
</head>
<body>
    <div class="card">
        <div class="card-body">
            <div class="logo">
                <i class="bi bi-gem"></i>
                <h3>Joyería Sosa</h3>
            </div>
            
            <div class="alert alert-info">
                <h4>¡Sesión finalizada!</h4>
                <?php if (!empty($user_name)): ?>
                    <p>Hasta pronto, <strong><?php echo htmlspecialchars($user_name); ?></strong></p>
                <?php else: ?>
                    <p>Has cerrado sesión correctamente.</p>
                <?php endif; ?>
            </div>
            
            <div class="d-grid gap-2">
                <a href="login.php" class="btn btn-primary">Volver a Iniciar Sesión</a>
            </div>
            
            <div class="countdown">
                Redirigiendo en <span id="countdown">5</span> segundos...
            </div>
        </div>
    </div>

    <script>
        // Redirección automática después de 5 segundos
        let seconds = 5;
        const countdownElement = document.getElementById('countdown');
        
        const countdown = setInterval(function() {
            seconds--;
            countdownElement.textContent = seconds;
            
            if (seconds <= 0) {
                clearInterval(countdown);
                window.location.href = 'login.php';
            }
        }, 1000);
    </script>
</body>
</html>