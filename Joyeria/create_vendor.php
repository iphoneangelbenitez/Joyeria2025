<?php
// create_vendedor.php
session_start();
require_once "config/database.php";

// 1. CONTROL DE SEGURIDAD
// Si NO hay sesión iniciada, lo mandamos al login. 
// Solo un usuario logueado (Administrador) puede ver esto.
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
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
            
            // 2. INSERTAR CON ROL DE VENDEDOR ('USER')
            // Aquí cambiamos 'ADM' por 'USER'
            $insert_query = "INSERT INTO usuarios (username, password, tipo, nombre) 
                             VALUES (:username, :password, 'USER', :nombre)";
            $insert_stmt = $db->prepare($insert_query);
            $insert_stmt->bindParam(':username', $username);
            $insert_stmt->bindParam(':password', $hashed_password);
            $insert_stmt->bindParam(':nombre', $nombre);
            
            if ($insert_stmt->execute()) {
                $message = "Vendedor registrado exitosamente.";
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
    <title>Registrar Vendedor - Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body {
            background-color: #e9ecef; /* Un gris un poco diferente para distinguir del login */
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            width: 450px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            border: none;
        }
        .card-header {
            background-color: #0d6efd; /* Azul para diferenciarlo */
            color: white;
            text-align: center;
            padding: 20px;
            border-radius: 5px 5px 0 0 !important;
        }
        .btn-primary {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-header">
            <h3><i class="bi bi-person-badge"></i> Nuevo Vendedor</h3>
            <small>Sistema de Gestión</small>
        </div>
        <div class="card-body p-4">
            
            <?php if ($error): ?>
                <div class="alert alert-danger d-flex align-items-center" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <div><?php echo $error; ?></div>
                </div>
            <?php endif; ?>
            
            <?php if ($message): ?>
                <div class="alert alert-success d-flex align-items-center" role="alert">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <div><?php echo $message; ?></div>
                </div>
            <?php endif; ?>
            
            <form method="post" action="">
                <div class="mb-3">
                    <label for="nombre" class="form-label">Nombre del Empleado</label>
                    <input type="text" class="form-control" id="nombre" name="nombre" placeholder="Ej: Juan Pérez" required>
                </div>
                
                <div class="mb-3">
                    <label for="username" class="form-label">Usuario para Login</label>
                    <input type="text" class="form-control" id="username" name="username" required>
                </div>
                
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="password" class="form-label">Contraseña</label>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                    
                    <div class="col-md-6 mb-3">
                        <label for="confirm_password" class="form-label">Confirmar</label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary w-100 py-2">
                    <i class="bi bi-save me-2"></i> Registrar Vendedor
                </button>
            </form>
            
            <div class="mt-4 text-center border-top pt-3">
                <a href="index.php" class="text-decoration-none text-secondary">
                    <i class="bi bi-arrow-left"></i> Volver al Panel Principal
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>