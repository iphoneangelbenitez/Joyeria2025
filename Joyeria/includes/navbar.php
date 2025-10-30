<?php



// Función para determinar si un enlace está activo
function isActive($page) {
    return (basename($_SERVER['PHP_SELF']) == $page) ? 'active' : '';
}

// Solo cargar datos de notificaciones si el usuario es administrador
$productos_bajo_stock = [];
if ($_SESSION['user_type'] == 'ADM') {
    require_once "config/database.php";
    try {
        $database = new Database();
        $db = $database->getConnection();
        
        // Consulta optimizada para obtener solo productos con stock bajo
        $query = "SELECT nombre, stock, stock_minimo FROM productos 
                 WHERE stock <= stock_minimo AND oculto = 0 LIMIT 5";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $productos_bajo_stock = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        // Log error pero no romper la navegación
        error_log("Error al cargar notificaciones: " . $e->getMessage());
    }
}

// Asegurarse de que las variables de sesión sean strings
$user_name = isset($_SESSION['user_name']) ? (string)$_SESSION['user_name'] : 'Usuario';
$user_type = isset($_SESSION['user_type']) ? (string)$_SESSION['user_type'] : 'USR';
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">
            <i class="bi bi-gem me-2"></i>Joyería Sosa
        </a>
        
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" 
                aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto">
                <li class="nav-item">
                    <a class="nav-link <?= isActive('index.php') ?>" 
                       href="index.php">
                        <i class="bi bi-house-door me-1"></i>Inicio
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?= isActive('ventas.php') ?>" 
                       href="ventas.php">
                        <i class="bi bi-cash-coin me-1"></i>Ventas
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?= isActive('inventario.php') ?>" 
                       href="inventario.php">
                        <i class="bi bi-boxes me-1"></i>Inventario
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?= isActive('servicios.php') ?>" 
                       href="servicios.php">
                        <i class="bi bi-tools me-1"></i>Servicios
                    </a>
                </li>
                
                <?php if ($user_type == 'ADM'): ?>
                <li class="nav-item">
                    <a class="nav-link <?= isActive('proveedores.php') ?>" 
                       href="proveedores.php">
                        <i class="bi bi-truck me-1"></i>Proveedores
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?= isActive('clientes.php') ?>" 
                       href="clientes.php">
                        <i class="bi bi-people me-1"></i>Clientes
                    </a>
                </li>
                
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarReportsDropdown" role="button" 
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-graph-up me-1"></i>Reportes
                    </a>
                    <ul class="dropdown-menu" aria-labelledby="navbarReportsDropdown">
                        <li><a class="dropdown-item" href="reportes_ventas.php">Ventas</a></li>
                        <li><a class="dropdown-item" href="reportes_inventario.php">Inventario</a></li>
                        <li><a class="dropdown-item" href="reportes_servicios.php">Servicios</a></li>
                    </ul>
                </li>
                <?php endif; ?>

             
            
            <ul class="navbar-nav ms-auto">
                <!-- Notificación para stock bajo (solo administradores) -->
                <?php if ($user_type == 'ADM' && count($productos_bajo_stock) > 0): ?>
                <li class="nav-item dropdown me-2">
                    <a class="nav-link position-relative" href="#" id="notificationsDropdown" role="button" 
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell-fill"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger">
                            <?= count($productos_bajo_stock) ?>
                            <span class="visually-hidden">alertas de stock</span>
                        </span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="notificationsDropdown">
                        <li class="dropdown-header text-danger">
                            <i class="bi bi-exclamation-triangle-fill me-1"></i> Productos con stock bajo
                        </li>
                        <?php foreach ($productos_bajo_stock as $producto): ?>
                        <li>
                            <a class="dropdown-item" href="inventario.php">
                                <div class="d-flex w-100 justify-content-between">
                                    <h6 class="mb-1"><?= htmlspecialchars($producto['nombre']) ?></h6>
                                    <small class="text-danger"><?= $producto['stock'] ?>/<?= $producto['stock_minimo'] ?></small>
                                </div>
                            </a>
                        </li>
                        <?php endforeach; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <a class="dropdown-item text-center" href="inventario.php">
                                Ver inventario completo
                            </a>
                        </li>
                    </ul>
                </li>
                <?php endif; ?>
                
            <!-- * probando navbar -->
         <li class="nav-item me-2 d-flex align-items-center"> <button id="theme-toggle-btn" class="theme-switch" type="button" role="switch" aria-label="Cambiar tema">
        
        <span class="slider">
            <i class="bi bi-sun-fill icon-sun"></i>
            <i class="bi bi-moon-fill icon-moon"></i>
        </span>
    </button>
    
</li>




                <li class="nav-item">
                    <span class="navbar-text text-light me-3">
                        <i class="bi bi-person-circle me-1"></i>
                        <?= htmlspecialchars($user_name) ?> 
                        <span class="badge bg-<?= ($user_type == 'ADM') ? 'danger' : 'info' ?>">
                            <?= ($user_type == 'ADM') ? 'Administrador' : 'Usuario' ?>
                        </span>
                    </span>
                </li>
                
                <li class="nav-item dropdown">
                    <a class="nav-link dropdown-toggle" href="#" id="navbarUserDropdown" role="button" 
                       data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-gear me-1"></i>Opciones
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="navbarUserDropdown">
                        <li>
                            <a class="dropdown-item" href="perfil.php">
                                <i class="bi bi-person me-2"></i>Mi Perfil
                            </a>
                        </li>
                        
                        <?php if ($user_type == 'ADM'): ?>
                        <li>
                            <a class="dropdown-item" href="usuarios.php">
                                <i class="bi bi-people me-2"></i>Gestionar Usuarios
                            </a>
                        </li>
                        <li><hr class="dropdown-divider"></li>
                        <?php endif; ?>
                        
                        <li>
                            <a class="dropdown-item" href="logout.php">
                                <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </div>
    </div>
</nav>

<!-- Espacio para compensar la barra de navegación fija -->
<div style="height: 76px;"></div>

</body>
</html>