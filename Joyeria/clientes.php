<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* Compatibilidad de sesión */
if (!isset($_SESSION['id_usuario']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_usuario'] = $_SESSION['user_id'];
}
if (!isset($_SESSION['tipo_usuario']) && isset($_SESSION['user_type'])) {
    $_SESSION['tipo_usuario'] = $_SESSION['user_type'];
}

/* Autenticación */
if (!isset($_SESSION['id_usuario']) && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* Permisos */
$esAdministrador = false;
if ((isset($_SESSION['tipo_usuario']) && $_SESSION['tipo_usuario'] === 'ADM') ||
    (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'ADM')) {
    $esAdministrador = true;
}

require_once "config/database.php";
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

$mensaje = '';
$error = '';
$accion = $_GET['action'] ?? '';

/* ================== Procesar formularios ================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Crear cliente */
    if (isset($_POST['crear_cliente'])) {
        try {
            $nombre   = trim($_POST['nombre']   ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $dni      = trim($_POST['dni']      ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $email    = trim($_POST['email']    ?? '');

            // Verificar DNI duplicado
            $sqlVer = "SELECT COUNT(*) AS total FROM clientes WHERE dni = :dni";
            $stVer = $conexion->prepare($sqlVer);
            $stVer->bindParam(':dni', $dni);
            $stVer->execute();
            $existe = (int)$stVer->fetch(PDO::FETCH_ASSOC)['total'];

            if ($existe > 0) {
                $error = "Ya existe un cliente con el DNI ingresado.";
            } else {
                $sqlIns = "INSERT INTO clientes (nombre, apellido, dni, telefono, email)
                           VALUES (:nombre, :apellido, :dni, :telefono, :email)";
                $stIns = $conexion->prepare($sqlIns);
                $stIns->bindParam(':nombre', $nombre);
                $stIns->bindParam(':apellido', $apellido);
                $stIns->bindParam(':dni', $dni);
                $stIns->bindParam(':telefono', $telefono);
                $stIns->bindParam(':email', $email);

                if ($stIns->execute()) {
                    $mensaje = "Cliente creado exitosamente.";
                } else {
                    $error = "Error al crear el cliente.";
                }
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    /* Actualizar cliente (solo ADM) */
    if ($esAdministrador && isset($_POST['actualizar_cliente'])) {
        try {
            $id       = (int)($_POST['id'] ?? 0);
            $nombre   = trim($_POST['nombre']   ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $dni      = trim($_POST['dni']      ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $email    = trim($_POST['email']    ?? '');

            // DNI duplicado en otro registro
            $sqlVer = "SELECT COUNT(*) AS total FROM clientes WHERE dni = :dni AND id != :id";
            $stVer = $conexion->prepare($sqlVer);
            $stVer->bindParam(':dni', $dni);
            $stVer->bindParam(':id', $id, PDO::PARAM_INT);
            $stVer->execute();
            $existe = (int)$stVer->fetch(PDO::FETCH_ASSOC)['total'];

            if ($existe > 0) {
                $error = "Ya existe otro cliente con el DNI ingresado.";
            } else {
                $sqlUpd = "UPDATE clientes
                           SET nombre = :nombre, apellido = :apellido, dni = :dni,
                               telefono = :telefono, email = :email
                           WHERE id = :id";
                $stUpd = $conexion->prepare($sqlUpd);
                $stUpd->bindParam(':id', $id, PDO::PARAM_INT);
                $stUpd->bindParam(':nombre', $nombre);
                $stUpd->bindParam(':apellido', $apellido);
                $stUpd->bindParam(':dni', $dni);
                $stUpd->bindParam(':telefono', $telefono);
                $stUpd->bindParam(':email', $email);

                if ($stUpd->execute()) {
                    $mensaje = "Cliente actualizado exitosamente.";
                } else {
                    $error = "Error al actualizar el cliente.";
                }
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

/* ================== Eliminar cliente (solo ADM) ================== */
if ($esAdministrador && isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    try {
        $id = (int)$_GET['eliminar'];

        // Verificar dependencias
        $sqlVtas = "SELECT COUNT(*) AS total FROM ventas WHERE id_cliente = :id";
        $stVtas = $conexion->prepare($sqlVtas);
        $stVtas->bindParam(':id', $id, PDO::PARAM_INT);
        $stVtas->execute();
        $ventasAsociadas = (int)$stVtas->fetch(PDO::FETCH_ASSOC)['total'];

        $sqlServ = "SELECT COUNT(*) AS total FROM servicios WHERE id_cliente = :id";
        $stServ = $conexion->prepare($sqlServ);
        $stServ->bindParam(':id', $id, PDO::PARAM_INT);
        $stServ->execute();
        $servAsociados = (int)$stServ->fetch(PDO::FETCH_ASSOC)['total'];

        if ($ventasAsociadas > 0 || $servAsociados > 0) {
            $error = "No se puede eliminar el cliente porque tiene ventas o servicios asociados.";
        } else {
            $sqlDel = "DELETE FROM clientes WHERE id = :id";
            $stDel = $conexion->prepare($sqlDel);
            $stDel->bindParam(':id', $id, PDO::PARAM_INT);
            if ($stDel->execute()) {
                $mensaje = "Cliente eliminado exitosamente.";
            } else {
                $error = "Error al eliminar el cliente.";
            }
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

/* ================== Consultas para UI ================== */
$filtroDni = $_GET['dni'] ?? '';

$sqlClientes = "SELECT * FROM clientes";
if ($filtroDni !== '') {
    $sqlClientes .= " WHERE dni LIKE :dni";
}
$sqlClientes .= " ORDER BY apellido, nombre";

$stCli = $conexion->prepare($sqlClientes);
if ($filtroDni !== '') {
    $dniLike = "%".$filtroDni."%";
    $stCli->bindParam(':dni', $dniLike);
}
$stCli->execute();
$clientes = $stCli->fetchAll(PDO::FETCH_ASSOC);

/* Cliente a editar */
$clienteEditar = null;
if ($esAdministrador && isset($_GET['editar']) && is_numeric($_GET['editar'])) {
    $idEditar = (int)$_GET['editar'];
    $sqlEdit = "SELECT * FROM clientes WHERE id = :id";
    $stEdit = $conexion->prepare($sqlEdit);
    $stEdit->bindParam(':id', $idEditar, PDO::PARAM_INT);
    $stEdit->execute();
    $clienteEditar = $stEdit->fetch(PDO::FETCH_ASSOC);
}

/* Servicios por DNI */
$serviciosCliente = [];
if (isset($_GET['ver_servicios']) && !empty($_GET['dni_servicios'])) {
    $dniServicios = trim($_GET['dni_servicios']);
    $sqlServPorDni = "SELECT s.*, c.nombre AS cliente_nombre, c.apellido AS cliente_apellido
                      FROM servicios s
                      INNER JOIN clientes c ON s.id_cliente = c.id
                      WHERE c.dni = :dni
                      ORDER BY s.fecha_ingreso DESC";
    $stServPorDni = $conexion->prepare($sqlServPorDni);
    $stServPorDni->bindParam(':dni', $dniServicios);
    $stServPorDni->execute();
    $serviciosCliente = $stServPorDni->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Clientes - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" />
    <!-- CSS externo estilo joyería -->
    <link rel="stylesheet" href="assets/css/clientes.css" />
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="clientes-container">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="clientes-header">
                    <h2><i class="bi bi-people me-2"></i>Gestión de Clientes</h2>
                    <p class="mb-0">Administre los clientes de la joyería</p>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($mensaje): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <!-- Filtros -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="filtros-container">
                    <h5>Filtros y Búsqueda</h5>
                    <form method="get" action="clientes.php" class="row g-3">
                        <div class="col-md-6">
                            <label for="dni" class="form-label">Buscar por DNI</label>
                            <input type="text" class="form-control" id="dni" name="dni"
                                   value="<?php echo htmlspecialchars($filtroDni); ?>" placeholder="Ingrese DNI del cliente">
                        </div>
                        <div class="col-md-6 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">Buscar</button>
                            <a href="clientes.php" class="btn btn-secondary">Limpiar</a>
                        </div>
                    </form>

                    <hr>
                    <h5>Ver Servicios por DNI</h5>
                    <form method="get" action="clientes.php" class="row g-3">
                        <input type="hidden" name="ver_servicios" value="1">
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="dni_servicios" name="dni_servicios"
                                   value="<?php echo isset($_GET['dni_servicios']) ? htmlspecialchars($_GET['dni_servicios']) : ''; ?>"
                                   placeholder="Ingrese DNI del cliente">
                        </div>
                        <div class="col-md-6">
                            <button type="submit" class="btn btn-info">Ver Servicios</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Servicios del cliente (si aplica) -->
        <?php if (isset($_GET['ver_servicios']) && !empty($serviciosCliente)): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="clientes-card">
                    <div class="clientes-card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            Servicios del Cliente:
                            <?php echo htmlspecialchars($serviciosCliente[0]['cliente_apellido'] . ', ' . $serviciosCliente[0]['cliente_nombre']); ?>
                        </h5>
                        <span class="badge bg-primary">Total: <?php echo count($serviciosCliente); ?></span>
                    </div>
                    <div class="card-body">
                        <?php foreach ($serviciosCliente as $serv): ?>
                            <?php
                                $claseTipo = ($serv['tipo'] === 'MANTENIMIENTO') ? 'servicio-mantenimiento' : 'servicio-reparacion';
                                $estado = $serv['estado'];
                                $claseEstado = strtolower($estado); // pendiente, en_proceso, completado (ajustamos abajo)
                                if ($claseEstado === 'en_proceso') { $claseEstado = 'proceso'; }
                            ?>
                            <div class="servicio-item <?php echo $claseTipo; ?>">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h6 class="mb-1"><?php echo htmlspecialchars($serv['producto']); ?></h6>
                                        <p class="mb-1"><strong>Tipo:</strong> <?php echo ($serv['tipo'] === 'MANTENIMIENTO') ? 'Mantenimiento de Reloj' : 'Reparación de Anillo'; ?></p>
                                        <p class="mb-1"><strong>Descripción:</strong> <?php echo htmlspecialchars($serv['descripcion']); ?></p>
                                        <p class="mb-1"><strong>Fecha de Ingreso:</strong> <?php echo date('d/m/Y', strtotime($serv['fecha_ingreso'])); ?></p>
                                        <?php if (!empty($serv['fecha_entrega_estimada'])): ?>
                                            <p class="mb-1"><strong>Entrega Estimada:</strong> <?php echo date('d/m/Y', strtotime($serv['fecha_entrega_estimada'])); ?></p>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-end">
                                        <span class="estado-badge estado-<?php echo $claseEstado; ?>">
                                            <?php
                                                switch ($estado) {
                                                    case 'PENDIENTE':   echo 'Pendiente';   break;
                                                    case 'EN_PROCESO':  echo 'En Proceso';  break;
                                                    case 'COMPLETADO':  echo 'Completado';  break;
                                                }
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Formulario alta/edición -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="clientes-card">
                    <div class="clientes-card-header">
                        <h5 class="mb-0"><?php echo isset($clienteEditar) ? 'Editar Cliente' : 'Agregar Nuevo Cliente'; ?></h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <?php if (isset($clienteEditar)): ?>
                                <input type="hidden" name="id" value="<?php echo (int)$clienteEditar['id']; ?>">
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="nombre" class="form-label">Nombre</label>
                                        <input type="text" class="form-control" id="nombre" name="nombre"
                                               value="<?php echo isset($clienteEditar) ? htmlspecialchars($clienteEditar['nombre']) : ''; ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="apellido" class="form-label">Apellido</label>
                                        <input type="text" class="form-control" id="apellido" name="apellido"
                                               value="<?php echo isset($clienteEditar) ? htmlspecialchars($clienteEditar['apellido']) : ''; ?>" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="dni" class="form-label">DNI</label>
                                        <input type="text" class="form-control" id="dni" name="dni"
                                               value="<?php echo isset($clienteEditar) ? htmlspecialchars($clienteEditar['dni']) : ''; ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="telefono" class="form-label">Teléfono</label>
                                        <input type="tel" class="form-control" id="telefono" name="telefono"
                                               value="<?php echo isset($clienteEditar) ? htmlspecialchars($clienteEditar['telefono']) : ''; ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email (Opcional)</label>
                                        <input type="email" class="form-control" id="email" name="email"
                                               value="<?php echo isset($clienteEditar) ? htmlspecialchars($clienteEditar['email']) : ''; ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <?php if (isset($clienteEditar)): ?>
                                    <button type="submit" name="actualizar_cliente" class="btn btn-primary">Actualizar Cliente</button>
                                    <a href="clientes.php" class="btn btn-secondary">Cancelar</a>
                                <?php else: ?>
                                    <button type="submit" name="crear_cliente" class="btn btn-success">Agregar Cliente</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <!-- Listado -->
        <div class="row">
            <div class="col-12">
                <div class="clientes-card">
                    <div class="clientes-card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Lista de Clientes</h5>
                        <span class="badge bg-primary">Total: <?php echo count($clientes); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (count($clientes) > 0): ?>
                            <div class="row">
                                <?php foreach ($clientes as $cli): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="cliente-item">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($cli['apellido'] . ', ' . $cli['nombre']); ?></h6>
                                                    <p class="mb-1 contact-info">
                                                        <i class="bi bi-person-badge"></i>DNI: <?php echo htmlspecialchars($cli['dni']); ?>
                                                    </p>
                                                    <?php if (!empty($cli['telefono'])): ?>
                                                        <p class="mb-1 contact-info">
                                                            <i class="bi bi-telephone"></i><?php echo htmlspecialchars($cli['telefono']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($cli['email'])): ?>
                                                        <p class="mb-1 contact-info">
                                                            <i class="bi bi-envelope"></i><?php echo htmlspecialchars($cli['email']); ?>
                                                        </p>
                                                    <?php endif; ?>
                                                    <?php if (!empty($cli['fecha_registro'])): ?>
                                                        <p class="mb-0 text-muted">
                                                            <small>Registrado: <?php echo date('d/m/Y', strtotime($cli['fecha_registro'])); ?></small>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($esAdministrador): ?>
                                                    <div class="btn-group">
                                                        <a href="clientes.php?editar=<?php echo (int)$cli['id']; ?>" class="btn btn-sm btn-primary btn-action" title="Editar">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <a href="clientes.php?eliminar=<?php echo (int)$cli['id']; ?>" class="btn btn-sm btn-danger btn-action" title="Eliminar"
                                                           onclick="return confirm('¿Está seguro de que desea eliminar este cliente?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>

                        <div class="mt-2">
                            <a href="clientes.php?ver_servicios=1&dni_servicios=<?php echo urlencode($cli['dni']); ?>" class="btn btn-sm btn-info">
                                <i class="bi bi-tools"></i> Ver Servicios
                            </a>
                        </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">No hay clientes registrados.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- container-fluid -->
</div><!-- clientes-container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
