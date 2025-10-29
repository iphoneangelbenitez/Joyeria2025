<?php
// create_admin.php
session_start();
require_once "config/database.php";

// Si ya hay sesión, redirigir al dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $nombre = trim($_POST['nombre']);
    
    // Validaciones
    if (empty($username) || empty($password) || empty($confirm_password) || empty($nombre)) {
        $error = "Todos los campos son obligatorios";
    } elseif ($password !== $confirm_password) {
        $error = "Las contraseñas no coinciden";
    } elseif (strlen($password) < 6) {
        $error = "La contraseña debe tener al menos 6 caracteres";
    } else {
        // Verificar si el usuario ya existe
        $check_query = "SELECT id FROM usuarios WHERE username = :username";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':username', $username);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            $error = "El nombre de usuario ya existe";
        } else {
            // Hash de la contraseña
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insertar el usuario administrador
            $insert_query = "INSERT INTO usuarios (username, password, tipo, nombre) 
                             VALUES (:username, :password, 'ADM', :nombre)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':username', $username);
            $insert_stmt->bindParam(':password', $hashed_password);
            $insert_stmt->bindParam(':nombre', $nombre);
            
            if ($insert_stmt->execute()) {
                $message = "Usuario administrador creado exitosamente. Ahora puedes <a href='login.php'>iniciar sesión</a>.";
            } else {
                $error = "Error al crear el usuario: " . implode(" ", $insert_stmt->errorInfo());
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Usuario Administrador - Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            width: 400px;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .logo {
            text-align: center;
            margin-bottom: 20px;
            color: #6c757d;
        }
        .logo i {
            font-size: 3rem;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-body">
            <div class="logo">
                <i class="bi bi-gem"></i>
                <h3>Joyería Luxury</h3>
                <p>Crear Usuario Administrador</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-success"><?php echo $message; ?></div>
            <?php else: ?>
                <form method="post" action="">
                    <div class="mb-3">
                        <label for="nombre" class="form-label">Nombre Completo</label>
                        <input type="text" class="form-control" id="nombre" name="nombre" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="username" class="form-label">Nombre de Usuario</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">Contraseña</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label">Confirmar Contraseña</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">Crear Usuario Administrador</button>
                </form>
            <?php endif; ?>
            
            <div class="mt-3 text-center">
                <a href="login.php">Volver al inicio de sesión</a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</body>
</html>