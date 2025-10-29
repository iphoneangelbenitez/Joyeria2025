<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}


/* Compatibilidad de sesión en español/inglés */
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

/* ---------------- Procesar formularios (sólo ADM) ---------------- */
if ($esAdministrador && $_SERVER['REQUEST_METHOD'] === 'POST') {
    /* Crear proveedor */
    if (isset($_POST['crear_proveedor'])) {
        try {
            $nombre   = trim($_POST['nombre']   ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $empresa  = trim($_POST['empresa']  ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $email    = trim($_POST['email']    ?? '');

            $consultaCrear = "INSERT INTO proveedores (nombre, apellido, empresa, telefono, email)
                              VALUES (:nombre, :apellido, :empresa, :telefono, :email)";
            $st = $conexion->prepare($consultaCrear);
            $st->bindParam(':nombre', $nombre);
            $st->bindParam(':apellido', $apellido);
            $st->bindParam(':empresa', $empresa);
            $st->bindParam(':telefono', $telefono);
            $st->bindParam(':email', $email);

            if ($st->execute()) {
                $mensaje = "Proveedor creado exitosamente.";
            } else {
                $error = "Error al crear el proveedor.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    /* Actualizar proveedor */
    if (isset($_POST['actualizar_proveedor'])) {
        try {
            $id       = (int)($_POST['id'] ?? 0);
            $nombre   = trim($_POST['nombre']   ?? '');
            $apellido = trim($_POST['apellido'] ?? '');
            $empresa  = trim($_POST['empresa']  ?? '');
            $telefono = trim($_POST['telefono'] ?? '');
            $email    = trim($_POST['email']    ?? '');

            $consultaActualizar = "UPDATE proveedores
                                   SET nombre = :nombre, apellido = :apellido, empresa = :empresa,
                                       telefono = :telefono, email = :email
                                   WHERE id = :id";
            $st = $conexion->prepare($consultaActualizar);
            $st->bindParam(':id', $id, PDO::PARAM_INT);
            $st->bindParam(':nombre', $nombre);
            $st->bindParam(':apellido', $apellido);
            $st->bindParam(':empresa', $empresa);
            $st->bindParam(':telefono', $telefono);
            $st->bindParam(':email', $email);

            if ($st->execute()) {
                $mensaje = "Proveedor actualizado exitosamente.";
            } else {
                $error = "Error al actualizar el proveedor.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

/* Eliminar proveedor por GET (sólo ADM) */
if ($esAdministrador && isset($_GET['eliminar']) && is_numeric($_GET['eliminar'])) {
    try {
        $id = (int)$_GET['eliminar'];

        // ¿Tiene productos asociados?
        $consultaVerificar = "SELECT COUNT(*) AS total FROM productos WHERE id_proveedor = :id";
        $stVer = $conexion->prepare($consultaVerificar);
        $stVer->bindParam(':id', $id, PDO::PARAM_INT);
        $stVer->execute();
        $asociados = (int)$stVer->fetch(PDO::FETCH_ASSOC)['total'];

        if ($asociados > 0) {
            $error = "No se puede eliminar el proveedor porque tiene productos asociados.";
        } else {
            $consultaEliminar = "DELETE FROM proveedores WHERE id = :id";
            $stDel = $conexion->prepare($consultaEliminar);
            $stDel->bindParam(':id', $id, PDO::PARAM_INT);
            if ($stDel->execute()) {
                $mensaje = "Proveedor eliminado exitosamente.";
            } else {
                $error = "Error al eliminar el proveedor.";
            }
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

/* ---------------- Consultas para UI ---------------- */
/* Lista de proveedores */
$consultaProveedores = "SELECT * FROM proveedores ORDER BY empresa, apellido, nombre";
$stProv = $conexion->prepare($consultaProveedores);
$stProv->execute();
$proveedores = $stProv->fetchAll(PDO::FETCH_ASSOC);

/* Proveedor a editar */
$proveedorEditar = null;
if ($esAdministrador && isset($_GET['editar']) && is_numeric($_GET['editar'])) {
    $idEditar = (int)$_GET['editar'];
    $consultaEditar = "SELECT * FROM proveedores WHERE id = :id";
    $stEdit = $conexion->prepare($consultaEditar);
    $stEdit->bindParam(':id', $idEditar, PDO::PARAM_INT);
    $stEdit->execute();
    $proveedorEditar = $stEdit->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Proveedores - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="assets/css/proveedores.css" />
</head>
<body>
<?php include 'includes/navbar.php'; ?>

<div class="proveedores-container">
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="proveedores-header">
                    <h2><i class="bi bi-truck me-2"></i>Gestión de Proveedores</h2>
                    <p class="mb-0">Administre los proveedores de la joyería</p>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($mensaje): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        <!-- Formulario agregar/editar (sólo ADM) -->
        <?php if ($esAdministrador): ?>
        <div class="row mb-4">
            <div class="col-12">
                <div class="proveedores-card">
                    <div class="proveedores-card-header">
                        <h5 class="mb-0"><?php echo isset($proveedorEditar) ? 'Editar Proveedor' : 'Agregar Nuevo Proveedor'; ?></h5>
                    </div>
                    <div class="card-body">
                        <form method="post" action="">
                            <?php if (isset($proveedorEditar)): ?>
                                <input type="hidden" name="id" value="<?php echo (int)$proveedorEditar['id']; ?>">
                            <?php endif; ?>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="nombre" class="form-label">Nombre</label>
                                        <input type="text" class="form-control" id="nombre" name="nombre"
                                               value="<?php echo isset($proveedorEditar) ? htmlspecialchars($proveedorEditar['nombre']) : ''; ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="apellido" class="form-label">Apellido</label>
                                        <input type="text" class="form-control" id="apellido" name="apellido"
                                               value="<?php echo isset($proveedorEditar) ? htmlspecialchars($proveedorEditar['apellido']) : ''; ?>" required>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="empresa" class="form-label">Empresa</label>
                                        <input type="text" class="form-control" id="empresa" name="empresa"
                                               value="<?php echo isset($proveedorEditar) ? htmlspecialchars($proveedorEditar['empresa']) : ''; ?>" required>
                                    </div>

                                    <div class="mb-3">
                                        <label for="telefono" class="form-label">Teléfono</label>
                                        <input type="tel" class="form-control" id="telefono" name="telefono"
                                               value="<?php echo isset($proveedorEditar) ? htmlspecialchars($proveedorEditar['telefono']) : ''; ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" class="form-control" id="email" name="email"
                                               value="<?php echo isset($proveedorEditar) ? htmlspecialchars($proveedorEditar['email']) : ''; ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                <?php if (isset($proveedorEditar)): ?>
                                    <button type="submit" name="actualizar_proveedor" class="btn btn-primary">Actualizar Proveedor</button>
                                    <a href="proveedores.php" class="btn btn-secondary">Cancelar</a>
                                <?php else: ?>
                                    <button type="submit" name="crear_proveedor" class="btn btn-success">Agregar Proveedor</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Tarjetas de proveedores -->
        <div class="row">
            <div class="col-12">
                <div class="proveedores-card">
                    <div class="proveedores-card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Lista de Proveedores</h5>
                        <span class="badge bg-primary">Total: <?php echo count($proveedores); ?></span>
                    </div>
                    <div class="card-body">
                        <?php if (count($proveedores) > 0): ?>
                            <div class="row">
                                <?php foreach ($proveedores as $prov): ?>
                                    <div class="col-md-6 col-lg-4 mb-3">
                                        <div class="proveedor-item">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div>
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($prov['empresa']); ?></h6>
                                                    <p class="mb-1"><?php echo htmlspecialchars($prov['apellido'] . ', ' . $prov['nombre']); ?></p>

                                                    <?php if (!empty($prov['telefono'])): ?>
                                                        <p class="mb-1 contact-info">
                                                            <i class="bi bi-telephone"></i><?php echo htmlspecialchars($prov['telefono']); ?>
                                                        </p>
                                                    <?php endif; ?>

                                                    <?php if (!empty($prov['email'])): ?>
                                                        <p class="mb-1 contact-info">
                                                            <i class="bi bi-envelope"></i><?php echo htmlspecialchars($prov['email']); ?>
                                                        </p>
                                                    <?php endif; ?>

                                                    <?php if (!empty($prov['fecha_registro'])): ?>
                                                        <p class="mb-0 text-muted">
                                                            <small>Registrado: <?php echo date('d/m/Y', strtotime($prov['fecha_registro'])); ?></small>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>

                                                <?php if ($esAdministrador): ?>
                                                    <div class="btn-group">
                                                        <a href="proveedores.php?editar=<?php echo (int)$prov['id']; ?>" class="btn btn-sm btn-primary btn-action" title="Editar">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <a href="proveedores.php?eliminar=<?php echo (int)$prov['id']; ?>" class="btn btn-sm btn-danger btn-action" title="Eliminar"
                                                           onclick="return confirm('¿Está seguro de que desea eliminar este proveedor?')">
                                                            <i class="bi bi-trash"></i>
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info">No hay proveedores registrados.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- container-fluid -->
</div><!-- proveedores-container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
