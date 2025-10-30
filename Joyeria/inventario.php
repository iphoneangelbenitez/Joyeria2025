<?php
// inventario.php
session_start();

/* Compatibilidad de nombres de sesión en español e inglés */
if (!isset($_SESSION['id_usuario']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_usuario'] = $_SESSION['user_id'];
}
if (!isset($_SESSION['tipo_usuario']) && isset($_SESSION['user_type'])) {
    $_SESSION['tipo_usuario'] = $_SESSION['user_type'];
}

/* Verificación de sesión */
if (!isset($_SESSION['id_usuario']) && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

/* Verificar permisos de usuario (ADM) */
$esAdministrador = false;
if ((isset($_SESSION['tipo_usuario']) && $_SESSION['tipo_usuario'] === 'ADM') ||
    (isset($_SESSION['user_type'])   && $_SESSION['user_type']   === 'ADM')) {
    $esAdministrador = true;
}

require_once "config/database.php";
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

$mensaje = '';
$error = '';
$accion = $_GET['action'] ?? '';
$idUsuario = isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : (int)$_SESSION['user_id'];


/* Procesar formularios solo si es administrador */
if ($esAdministrador && $_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Crear nuevo producto */
    if (isset($_POST['crear_producto'])) {
        try {
            $nombre = $_POST['nombre'] ?? '';
            $descripcion = $_POST['descripcion'] ?? '';
            $idCategoria = (int)($_POST['id_categoria'] ?? 0);
            $idProveedor = (int)($_POST['id_proveedor'] ?? 0);
            $costo = (float)($_POST['costo'] ?? 0);
            $porcentajeGanancia = (float)($_POST['porcentaje_ganancia'] ?? 0);
            $stock = (int)($_POST['stock'] ?? 0); // Stock inicial
            $stockMinimo = (int)($_POST['stock_minimo'] ?? 0);

            // Calcular precio basado en costo y % de ganancia
            $precio = $costo + ($costo * $porcentajeGanancia / 100);

            $consultaCrear = "INSERT INTO productos 
                (nombre, descripcion, id_categoria, id_proveedor, costo, porcentaje_ganancia, precio, stock, stock_minimo) 
                VALUES 
                (:nombre, :descripcion, :id_categoria, :id_proveedor, :costo, :porcentaje_ganancia, :precio, :stock, :stock_minimo)";
            $sentenciaCrear = $conexion->prepare($consultaCrear);
            $sentenciaCrear->bindParam(':nombre', $nombre);
            $sentenciaCrear->bindParam(':descripcion', $descripcion);
            $sentenciaCrear->bindParam(':id_categoria', $idCategoria, PDO::PARAM_INT);
            $sentenciaCrear->bindParam(':id_proveedor', $idProveedor, PDO::PARAM_INT);
            $sentenciaCrear->bindParam(':costo', $costo);
            $sentenciaCrear->bindParam(':porcentaje_ganancia', $porcentajeGanancia);
            $sentenciaCrear->bindParam(':precio', $precio);
            $sentenciaCrear->bindParam(':stock', $stock, PDO::PARAM_INT);
            $sentenciaCrear->bindParam(':stock_minimo', $stockMinimo, PDO::PARAM_INT);

            if ($sentenciaCrear->execute()) {
                $idProducto = $conexion->lastInsertId();

                // Registrar movimiento de stock (ALTA) solo si el stock inicial es > 0
                if ($stock > 0) {
                    $consultaMovimiento = "INSERT INTO movimientos_stock 
                        (id_producto, tipo, cantidad, cantidad_anterior, cantidad_nueva, motivo, id_usuario) 
                        VALUES 
                        (:id_producto, 'ENTRADA', :cantidad, 0, :cantidad_nueva, 'ALTA DE PRODUCTO', :id_usuario)";
                    $sentenciaMovimiento = $conexion->prepare($consultaMovimiento);
                    $sentenciaMovimiento->bindParam(':id_producto', $idProducto, PDO::PARAM_INT);
                    $sentenciaMovimiento->bindParam(':cantidad', $stock, PDO::PARAM_INT);
                    $sentenciaMovimiento->bindParam(':cantidad_nueva', $stock, PDO::PARAM_INT);
                    $sentenciaMovimiento->bindParam(':id_usuario', $idUsuario, PDO::PARAM_INT);
                    $sentenciaMovimiento->execute();
                }

                $mensaje = "Producto creado exitosamente.";
            } else {
                $error = "Error al crear el producto.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    /* Actualizar producto */
    if (isset($_POST['actualizar_producto'])) {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $nombre = $_POST['nombre'] ?? '';
            $descripcion = $_POST['descripcion'] ?? '';
            $idCategoria = (int)($_POST['id_categoria'] ?? 0);
            $idProveedor = (int)($_POST['id_proveedor'] ?? 0);
            $costo = (float)($_POST['costo'] ?? 0);
            $porcentajeGanancia = (float)($_POST['porcentaje_ganancia'] ?? 0);
            $stockMinimo = (int)($_POST['stock_minimo'] ?? 0);

            // Calcular nuevo precio
            $precio = $costo + ($costo * $porcentajeGanancia / 100);

            $consultaActualizar = "UPDATE productos 
                SET nombre = :nombre, descripcion = :descripcion, id_categoria = :id_categoria, 
                    id_proveedor = :id_proveedor, costo = :costo, porcentaje_ganancia = :porcentaje_ganancia, 
                    precio = :precio, stock_minimo = :stock_minimo 
                WHERE id = :id";
            $sentenciaActualizar = $conexion->prepare($consultaActualizar);
            $sentenciaActualizar->bindParam(':id', $id, PDO::PARAM_INT);
            $sentenciaActualizar->bindParam(':nombre', $nombre);
            $sentenciaActualizar->bindParam(':descripcion', $descripcion);
            $sentenciaActualizar->bindParam(':id_categoria', $idCategoria, PDO::PARAM_INT);
            $sentenciaActualizar->bindParam(':id_proveedor', $idProveedor, PDO::PARAM_INT);
            $sentenciaActualizar->bindParam(':costo', $costo);
            $sentenciaActualizar->bindParam(':porcentaje_ganancia', $porcentajeGanancia);
            $sentenciaActualizar->bindParam(':precio', $precio);
            $sentenciaActualizar->bindParam(':stock_minimo', $stockMinimo, PDO::PARAM_INT);

            if ($sentenciaActualizar->execute()) {
                
                // --- MODIFICACIÓN ---
                // Se eliminó el bloque que actualizaba el stock desde aquí.
                // El stock ahora solo se maneja por "ajustar_stock" (ingreso/egreso).
                // --- FIN MODIFICACIÓN ---

                $mensaje = "Producto actualizado exitosamente.";
            } else {
                $error = "Error al actualizar el producto.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    // --- NUEVO BLOQUE ---
    /* Ajustar Stock (Ingreso/Egreso desde Modal) */
    if (isset($_POST['ajustar_stock'])) {
        try {
            $idProducto = (int)($_POST['id_producto'] ?? 0);
            $tipoMovimiento = $_POST['tipo_ajuste'] ?? ''; // 'ENTRADA' o 'SALIDA'
            $cantidad = (int)($_POST['cantidad'] ?? 0);
            $motivo = $_POST['motivo'] ?? 'Ajuste de stock';

            if ($idProducto > 0 && ($tipoMovimiento === 'ENTRADA' || $tipoMovimiento === 'SALIDA') && $cantidad > 0) {
                
                // 1. Obtener stock actual
                $consultaStockActual = "SELECT stock FROM productos WHERE id = :id FOR UPDATE"; // Bloquear fila
                $sentenciaStockActual = $conexion->prepare($consultaStockActual);
                $sentenciaStockActual->bindParam(':id', $idProducto, PDO::PARAM_INT);
                $sentenciaStockActual->execute();
                $filaStock = $sentenciaStockActual->fetch(PDO::FETCH_ASSOC);
                $stockActual = $filaStock ? (int)$filaStock['stock'] : 0;

                $nuevoStock = $stockActual;

                // 2. Calcular nuevo stock y validar
                if ($tipoMovimiento === 'ENTRADA') {
                    $nuevoStock = $stockActual + $cantidad;
                } else { // SALIDA
                    if ($cantidad > $stockActual) {
                        $error = "Error: No se puede registrar una salida mayor al stock actual ($stockActual).";
                    } else {
                        $nuevoStock = $stockActual - $cantidad;
                    }
                }

                // 3. Si no hubo error, actualizar e insertar movimiento
                if (empty($error)) {
                    // Actualizar stock en productos
                    $consultaUpdateStock = "UPDATE productos SET stock = :stock WHERE id = :id";
                    $sentenciaUpdateStock = $conexion->prepare($consultaUpdateStock);
                    $sentenciaUpdateStock->bindParam(':stock', $nuevoStock, PDO::PARAM_INT);
                    $sentenciaUpdateStock->bindParam(':id', $idProducto, PDO::PARAM_INT);
                    
                    if ($sentenciaUpdateStock->execute()) {
                        // Registrar movimiento de stock
                        $consultaMovimiento = "INSERT INTO movimientos_stock 
                            (id_producto, tipo, cantidad, cantidad_anterior, cantidad_nueva, motivo, id_usuario) 
                            VALUES (:id_producto, :tipo, :cantidad, :cantidad_anterior, :cantidad_nueva, :motivo, :id_usuario)";
                        $sentenciaMovimiento = $conexion->prepare($consultaMovimiento);
                        $sentenciaMovimiento->bindParam(':id_producto', $idProducto, PDO::PARAM_INT);
                        $sentenciaMovimiento->bindParam(':tipo', $tipoMovimiento);
                        $sentenciaMovimiento->bindParam(':cantidad', $cantidad, PDO::PARAM_INT);
                        $sentenciaMovimiento->bindParam(':cantidad_anterior', $stockActual, PDO::PARAM_INT);
                        $sentenciaMovimiento->bindParam(':cantidad_nueva', $nuevoStock, PDO::PARAM_INT);
                        $sentenciaMovimiento->bindParam(':motivo', $motivo);
                        $sentenciaMovimiento->bindParam(':id_usuario', $idUsuario, PDO::PARAM_INT);
                        $sentenciaMovimiento->execute();

                        $mensaje = "Stock actualizado y movimiento registrado exitosamente.";
                    } else {
                        $error = "Error al actualizar el stock del producto.";
                    }
                }

            } else {
                $error = "Datos inválidos para el ajuste de stock.";
            }

        } catch (PDOException $e) {
            $error = "Error en ajuste de stock: " . $e->getMessage();
        }
    }
    // --- FIN NUEVO BLOQUE ---

}

/* Ocultar/Mostrar producto (también sólo para administradores) */
if ($esAdministrador && isset($_GET['toggle']) && isset($_GET['id'])) {
    // ... (sin cambios en este bloque)
    try {
        $id = (int)$_GET['id'];
        $consultaEstado = "SELECT oculto FROM productos WHERE id = :id";
        $sentenciaEstado = $conexion->prepare($consultaEstado);
        $sentenciaEstado->bindParam(':id', $id, PDO::PARAM_INT);
        $sentenciaEstado->execute();
        $filaEstado = $sentenciaEstado->fetch(PDO::FETCH_ASSOC);
        $ocultoActual = $filaEstado ? (int)$filaEstado['oculto'] : 0;
        $nuevoEstado = $ocultoActual ? 0 : 1;
        $consultaToggle = "UPDATE productos SET oculto = :oculto WHERE id = :id";
        $sentenciaToggle = $conexion->prepare($consultaToggle);
        $sentenciaToggle->bindParam(':oculto', $nuevoEstado, PDO::PARAM_INT);
        $sentenciaToggle->bindParam(':id', $id, PDO::PARAM_INT);
        if ($sentenciaToggle->execute()) {
            $accion = $nuevoEstado ? "ocultado" : "mostrado";
            $mensaje = "Producto $accion exitosamente.";
        } else {
            $error = "Error al cambiar el estado del producto.";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

/* Obtener lista de productos */
$consultaProductos = "SELECT p.*, c.nombre AS categoria, pr.empresa AS proveedor 
                      FROM productos p 
                      INNER JOIN categorias c ON p.id_categoria = c.id 
                      INNER JOIN proveedores pr ON p.id_proveedor = pr.id 
                      ORDER BY p.nombre";
$sentenciaProductos = $conexion->prepare($consultaProductos);
$sentenciaProductos->execute();
$productos = $sentenciaProductos->fetchAll(PDO::FETCH_ASSOC);

/* Categorías */
$consultaCategorias = "SELECT * FROM categorias ORDER BY nombre";
$sentenciaCategorias = $conexion->prepare($consultaCategorias);
$sentenciaCategorias->execute();
$categorias = $sentenciaCategorias->fetchAll(PDO::FETCH_ASSOC);

/* Proveedores */
$consultaProveedores = "SELECT * FROM proveedores ORDER BY empresa";
$sentenciaProveedores = $conexion->prepare($consultaProveedores);
$sentenciaProveedores->execute();
$proveedores = $sentenciaProveedores->fetchAll(PDO::FETCH_ASSOC);

/* Producto específico para edición */
$productoEditar = null;
if ($esAdministrador && isset($_GET['editar']) && is_numeric($_GET['editar'])) {
    $idEditar = (int)$_GET['editar'];
    $consultaEditar = "SELECT * FROM productos WHERE id = :id";
    $sentenciaEditar = $conexion->prepare($consultaEditar);
    $sentenciaEditar->bindParam(':id', $idEditar, PDO::PARAM_INT);
    $sentenciaEditar->execute();
    $productoEditar = $sentenciaEditar->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventario - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/inventario.css">
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="inventario-container">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="inventario-header">
                        <h2><i class="bi bi-boxes me-2"></i>Gestión de Inventario</h2>
                        <p class="mb-0">Administre los productos de la joyería</p>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($mensaje): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div>
            <?php endif; ?>

            <?php if ($esAdministrador): ?>
            
            <div class="row mb-3">
                <div class="col-12">
                    <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#formularioProducto" aria-expanded="<?php echo isset($productoEditar) ? 'true' : 'false'; ?>" aria-controls="formularioProducto">
                        <i class="bi bi-plus-circle me-2"></i><?php echo isset($productoEditar) ? 'Editando Producto' : 'Agregar Nuevo Producto'; ?>
                    </button>
                </div>
            </div>
            <div class="row mb-4 collapse <?php echo isset($productoEditar) ? 'show' : ''; ?>" id="formularioProducto">
            <div class="col-md-12">
                    <div class="inventario-card">
                        <div class="inventario-card-header">
                            <h5 class="mb-0"><?php echo isset($productoEditar) ? 'Editar Producto' : 'Agregar Nuevo Producto'; ?></h5>
                        </div>
                        <div class="card-body">
                            <form method="post" action="inventario.php<?php echo isset($productoEditar) ? '?editar='.(int)$productoEditar['id'] : ''; ?>">
                                <?php if (isset($productoEditar)): ?>
                                    <input type="hidden" name="id" value="<?php echo (int)$productoEditar['id']; ?>">
                                <?php endif; ?>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="nombre" class="form-label">Nombre del Producto</label>
                                            <input type="text" class="form-control" id="nombre" name="nombre"
                                                   value="<?php echo isset($productoEditar) ? htmlspecialchars($productoEditar['nombre']) : ''; ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label for="descripcion" class="form-label">Descripción</label>
                                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3"><?php 
                                                echo isset($productoEditar) ? htmlspecialchars($productoEditar['descripcion']) : ''; 
                                            ?></textarea>
                                        </div>

                                        <div class="mb-3">
                                            <label for="id_categoria" class="form-label">Categoría</label>
                                            <select class="form-select" id="id_categoria" name="id_categoria" required>
                                                <option value="">Seleccionar categoría</option>
                                                <?php foreach ($categorias as $categoria): ?>
                                                    <option value="<?php echo (int)$categoria['id']; ?>"
                                                        <?php if (isset($productoEditar) && (int)$productoEditar['id_categoria'] === (int)$categoria['id']) echo 'selected'; ?>>
                                                        <?php echo htmlspecialchars($categoria['nombre']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="id_proveedor" class="form-label">Proveedor</label>
                                            <select class="form-select" id="id_proveedor" name="id_proveedor" required>
                                                <option value="">Seleccionar proveedor</option>
                                                <?php foreach ($proveedores as $proveedor): ?>
                                                    <option value="<?php echo (int)$proveedor['id']; ?>"
                                                        <?php if (isset($productoEditar) && (int)$productoEditar['id_proveedor'] === (int)$proveedor['id']) echo 'selected'; ?>>
                                                        <?php echo htmlspecialchars($proveedor['empresa']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="costo" class="form-label">Costo ($)</label>
                                            <input type="number" step="0.01" class="form-control" id="costo" name="costo"
                                                   value="<?php echo isset($productoEditar) ? (float)$productoEditar['costo'] : ''; ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label for="porcentaje_ganancia" class="form-label">Porcentaje de Ganancia (%)</label>
                                            <input type="number" step="0.01" class="form-control" id="porcentaje_ganancia" name="porcentaje_ganancia"
                                                   value="<?php echo isset($productoEditar) ? (float)$productoEditar['porcentaje_ganancia'] : ''; ?>" required>
                                        </div>

                                        <div class="mb-3">
                                            <label for="precio" class="form-label">Precio de Venta ($)</label>
                                            <input type="number" step="0.01" class="form-control precio-calculado" id="precio" name="precio" readonly
                                                   value="<?php echo isset($productoEditar) ? (float)$productoEditar['precio'] : ''; ?>">
                                        </div>

                                        <div class="mb-3">
                                            <label for="stock" class="form-label">Stock <?php echo isset($productoEditar) ? 'Actual' : 'Inicial'; ?></label>
                                            <input type="number" class="form-control" id="stock" name="stock"
                                                   value="<?php echo isset($productoEditar) ? (int)$productoEditar['stock'] : '0'; ?>" 
                                                   required <?php echo isset($productoEditar) ? 'readonly' : ''; ?>>
                                            <?php if (isset($productoEditar)): ?>
                                                <div class="form-text">Use los botones (+) o (-) de la lista para ajustar el stock.</div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="mb-3">
                                            <label for="stock_minimo" class="form-label">Stock Mínimo</label>
                                            <input type="number" class="form-control" id="stock_minimo" name="stock_minimo"
                                                   value="<?php echo isset($productoEditar) ? (int)$productoEditar['stock_minimo'] : '5'; ?>" required>
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <?php if (isset($productoEditar)): ?>
                                        <button type="submit" name="actualizar_producto" class="btn btn-primary">Actualizar Producto</button>
                                        <a href="inventario.php" class="btn btn-secondary">Cancelar Edición</a>
                                    <?php else: ?>
                                        <button type="submit" name="crear_producto" class="btn btn-success">Guardar Producto</button>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-12">
                    <div class="inventario-card">
                        <div class="inventario-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Lista de Productos</h5>
                            <div class="w-50">
                                <input type="text" id="buscadorProductos" class="form-control" placeholder="Buscar por nombre, categoría o proveedor...">
                            </div>
                            </div>
                        <div class="card-body">
                            <?php if (count($productos) > 0): ?>
                                <div class="table-responsive">
                                    <table class="table table-hover" id="tablaProductos">
                                        <thead>
                                            <tr>
                                                <th>Nombre</th>
                                                <th>Categoría</th>
                                                <th>Proveedor</th>
                                                <th>Costo</th>
                                                <th>Precio</th>
                                                <th>Stock</th>
                                                <th>Stock Mínimo</th>
                                                <th>Estado</th>
                                                <?php if ($esAdministrador): ?>
                                                    <th>Acciones</th>
                                                <?php endif; ?>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($productos as $producto): ?>
                                                <tr class="<?php 
                                                    echo ((int)$producto['stock'] <= (int)$producto['stock_minimo']) ? 'stock-bajo ' : ''; 
                                                    echo ((int)$producto['oculto'] === 1) ? 'producto-oculto' : ''; 
                                                ?>">
                                                    <td><?php echo htmlspecialchars($producto['nombre']); ?></td>
                                                    <td><?php echo htmlspecialchars($producto['categoria']); ?></td>
                                                    <td><?php echo htmlspecialchars($producto['proveedor']); ?></td>
                                                    <td>$<?php echo number_format((float)$producto['costo'], 2); ?></td>
                                                    <td>$<?php echo number_format((float)$producto['precio'], 2); ?></td>
                                                    <td>
                                                        <span class="<?php echo ((int)$producto['stock'] <= (int)$producto['stock_minimo']) ? 'text-danger fw-bold' : ''; ?>">
                                                            <?php echo (int)$producto['stock']; ?>
                                                        </span>
                                                        <?php if ((int)$producto['stock'] <= (int)$producto['stock_minimo']): ?>
                                                            <i class="bi bi-exclamation-triangle-fill text-danger" title="Stock bajo"></i>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo (int)$producto['stock_minimo']; ?></td>
                                                    <td>
                                                        <?php if ((int)$producto['oculto'] === 1): ?>
                                                            <span class="badge bg-secondary">Oculto</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-success">Visible</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <?php if ($esAdministrador): ?>
                                                        <td>
                                                            <a href="inventario.php?editar=<?php echo (int)$producto['id']; ?>" class="btn btn-sm btn-primary btn-action" title="Editar">
                                                                <i class="bi bi-pencil"></i>
                                                            </a>
                                                            
                                                            <button type="button" class="btn btn-sm btn-success btn-action btn-ajuste-stock" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#modalAjusteStock"
                                                                data-bs-tipo="ENTRADA"
                                                                data-bs-producto-id="<?php echo (int)$producto['id']; ?>"
                                                                data-bs-producto-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                                title="Registrar Ingreso">
                                                                <i class="bi bi-plus-circle"></i>
                                                            </button>

                                                            <button type="button" class="btn btn-sm btn-danger btn-action btn-ajuste-stock" 
                                                                data-bs-toggle="modal" 
                                                                data-bs-target="#modalAjusteStock"
                                                                data-bs-tipo="SALIDA"
                                                                data-bs-producto-id="<?php echo (int)$producto['id']; ?>"
                                                                data-bs-producto-nombre="<?php echo htmlspecialchars($producto['nombre']); ?>"
                                                                title="Registrar Egreso">
                                                                <i class="bi bi-dash-circle"></i>
                                                            </button>

                                                            <a href="inventario.php?toggle=1&id=<?php echo (int)$producto['id']; ?>" class="btn btn-sm btn-warning btn-action" title="<?php echo ((int)$producto['oculto'] === 1) ? 'Mostrar' : 'Ocultar'; ?>">
                                                                <i class="bi bi-eye<?php echo ((int)$producto['oculto'] === 1) ? '' : '-slash'; ?>"></i>
                                                            </a>
                                                            </td>
                                                    <?php endif; ?>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-info">No hay productos en el inventario.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="modalAjusteStock" tabindex="-1" aria-labelledby="modalAjusteStockLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="post" action="inventario.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalAjusteStockLabel">Ajuste de Stock</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="id_producto" id="modal_id_producto">
                        <input type="hidden" name="tipo_ajuste" id="modal_tipo_ajuste">
                        
                        <div class="mb-3">
                            <label class="form-label">Producto</label>
                            <input type="text" class="form-control" id="modal_nombre_producto" disabled>
                        </div>

                        <div class="mb-3">
                            <label for="modal_cantidad" class="form-label">Cantidad</label>
                            <input type="number" class="form-control" id="modal_cantidad" name="cantidad" min="1" required>
                        </div>

                        <div class="mb-3">
                            <label for="modal_motivo" class="form-label">Motivo</label>
                            <input type="text" class="form-control" id="modal_motivo" name="motivo" placeholder="Ej: Compra a proveedor, Devolución, etc." required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="ajustar_stock" class="btn btn-primary" id="modal_btn_submit">Registrar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const costoInput = document.getElementById('costo');
            const porcentajeInput = document.getElementById('porcentaje_ganancia');
            const precioInput = document.getElementById('precio');

            // --- Cálculo de Precio (Sin cambios) ---
            function calcularPrecio() {
                if (costoInput && porcentajeInput && precioInput) {
                    const costo = parseFloat(costoInput.value) || 0;
                    const porcentaje = parseFloat(porcentajeInput.value) || 0;
                    const precio = costo + (costo * porcentaje / 100);
                    precioInput.value = precio.toFixed(2);
                }
            }
            if (costoInput) costoInput.addEventListener('input', calcularPrecio);
            if (porcentajeInput) porcentajeInput.addEventListener('input', calcularPrecio);
            calcularPrecio();


            // --- NUEVO SCRIPT: Buscador de Productos ---
            const buscador = document.getElementById('buscadorProductos');
            const tabla = document.getElementById('tablaProductos');
            const filas = tabla ? tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

            if (buscador) {
                buscador.addEventListener('keyup', function() {
                    const textoBusqueda = buscador.value.toLowerCase();
                    
                    for (let i = 0; i < filas.length; i++) {
                        const fila = filas[i];
                        const celdas = fila.getElementsByTagName('td');
                        // Busca en Nombre (0), Categoría (1) y Proveedor (2)
                        const textoFila = (celdas[0].textContent + ' ' + celdas[1].textContent + ' ' + celdas[2].textContent).toLowerCase();
                        
                        if (textoFila.indexOf(textoBusqueda) > -1) {
                            fila.style.display = '';
                        } else {
                            fila.style.display = 'none';
                        }
                    }
                });
            }

            // --- NUEVO SCRIPT: Lógica del Modal de Ajuste de Stock ---
            const modalAjusteStock = document.getElementById('modalAjusteStock');
            if (modalAjusteStock) {
                modalAjusteStock.addEventListener('show.bs.modal', function (event) {
                    // Botón que disparó el modal
                    const button = event.relatedTarget;
                    
                    // Extraer datos de los atributos data-bs-*
                    const tipo = button.getAttribute('data-bs-tipo');
                    const productoId = button.getAttribute('data-bs-producto-id');
                    const productoNombre = button.getAttribute('data-bs-producto-nombre');

                    // Obtener elementos del modal
                    const modalTitle = modalAjusteStock.querySelector('.modal-title');
                    const modalSubmitBtn = modalAjusteStock.querySelector('#modal_btn_submit');
                    const modalIdInput = modalAjusteStock.querySelector('#modal_id_producto');
                    const modalTipoInput = modalAjusteStock.querySelector('#modal_tipo_ajuste');
                    const modalNombreInput = modalAjusteStock.querySelector('#modal_nombre_producto');
                    const modalMotivoInput = modalAjusteStock.querySelector('#modal_motivo');

                    // Configurar el modal según sea ENTRADA o SALIDA
                    if (tipo === 'ENTRADA') {
                        modalTitle.textContent = 'Registrar Ingreso de Stock';
                        modalSubmitBtn.textContent = 'Registrar Ingreso';
                        modalSubmitBtn.classList.remove('btn-danger');
                        modalSubmitBtn.classList.add('btn-success');
                        modalMotivoInput.placeholder = 'Ej: Compra a proveedor, devolución cliente...';
                    } else {
                        modalTitle.textContent = 'Registrar Egreso de Stock';
                        modalSubmitBtn.textContent = 'Registrar Egreso';
                        modalSubmitBtn.classList.remove('btn-success');
                        modalSubmitBtn.classList.add('btn-danger');
                        modalMotivoInput.placeholder = 'Ej: Rotura, merma, devolución a proveedor...';
                    }

                    // Rellenar los campos del formulario
                    modalIdInput.value = productoId;
                    modalTipoInput.value = tipo;
                    modalNombreInput.value = productoNombre;
                });
            }

        });
    </script>
</body>
</html>