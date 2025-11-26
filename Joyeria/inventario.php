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
            $stock = (int)($_POST['stock'] ?? 0);
            $stockMinimo = (int)($_POST['stock_minimo'] ?? 0);

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
                $mensaje = "Producto actualizado exitosamente.";
            } else {
                $error = "Error al actualizar el producto.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    /* Procesar Ingreso Masivo */
    if (isset($_POST['procesar_ingreso_masivo'])) {
        $transaccionActiva = false;
        try {
            // VALIDACIÓN ADICIONAL - Verificar que el proveedor existe
            $idProveedor = (int)($_POST['id_proveedor'] ?? 0);
            
            if ($idProveedor <= 0) {
                throw new Exception("Error: No se ha seleccionado un proveedor válido. Valor recibido: " . ($_POST['id_proveedor'] ?? 'NULL') . ". ID después de conversión: " . $idProveedor);
            }
            
            // Verificar que el proveedor existe en la base de datos
            $consultaVerificarProveedor = "SELECT id FROM proveedores WHERE id = :id_proveedor";
            $sentenciaVerificarProveedor = $conexion->prepare($consultaVerificarProveedor);
            $sentenciaVerificarProveedor->bindParam(':id_proveedor', $idProveedor, PDO::PARAM_INT);
            $sentenciaVerificarProveedor->execute();
            
            if ($sentenciaVerificarProveedor->rowCount() === 0) {
                throw new Exception("Error: El proveedor seleccionado no existe en la base de datos. ID: " . $idProveedor);
            }

            // INICIAR TRANSACCIÓN
            $conexion->beginTransaction();
            $transaccionActiva = true;

            $fechaCompra = $_POST['fecha_compra'];
            $formaPago = $_POST['forma_pago'];
            $observaciones = $_POST['observaciones'] ?? '';
            $totalCompra = 0;

            // Subir imagen de factura si existe
            $imagenFactura = null;
            if (isset($_FILES['imagen_factura']) && $_FILES['imagen_factura']['error'] === UPLOAD_ERR_OK) {
                // Crear directorio si no existe
                if (!is_dir('assets/facturas')) {
                    mkdir('assets/facturas', 0777, true);
                }
                
                $nombreImagen = uniqid() . '_' . $_FILES['imagen_factura']['name'];
                $rutaImagen = 'assets/facturas/' . $nombreImagen;
                if (move_uploaded_file($_FILES['imagen_factura']['tmp_name'], $rutaImagen)) {
                    $imagenFactura = $rutaImagen;
                }
            }

            // Insertar cabecera de compra
            $consultaCompra = "INSERT INTO compras (id_proveedor, fecha_compra, forma_pago, observaciones, total, imagen_factura, id_usuario) 
                              VALUES (:id_proveedor, :fecha_compra, :forma_pago, :observaciones, 0, :imagen_factura, :id_usuario)";
            $sentenciaCompra = $conexion->prepare($consultaCompra);
            $sentenciaCompra->bindParam(':id_proveedor', $idProveedor);
            $sentenciaCompra->bindParam(':fecha_compra', $fechaCompra);
            $sentenciaCompra->bindParam(':forma_pago', $formaPago);
            $sentenciaCompra->bindParam(':observaciones', $observaciones);
            $sentenciaCompra->bindParam(':imagen_factura', $imagenFactura);
            $sentenciaCompra->bindParam(':id_usuario', $idUsuario);
            $sentenciaCompra->execute();
            $idCompra = $conexion->lastInsertId();

            // Procesar productos
            $productos = $_POST['productos'] ?? [];
            foreach ($productos as $producto) {
                $idProducto = (int)$producto['id'];
                $cantidad = (int)$producto['cantidad'];
                $costoUnitario = (float)$producto['costo'];
                $subtotal = $cantidad * $costoUnitario;
                $totalCompra += $subtotal;

                // Insertar detalle de compra
                $consultaDetalle = "INSERT INTO compra_detalles (id_compra, id_producto, cantidad, costo_unitario, subtotal) 
                                   VALUES (:id_compra, :id_producto, :cantidad, :costo_unitario, :subtotal)";
                $sentenciaDetalle = $conexion->prepare($consultaDetalle);
                $sentenciaDetalle->bindParam(':id_compra', $idCompra);
                $sentenciaDetalle->bindParam(':id_producto', $idProducto);
                $sentenciaDetalle->bindParam(':cantidad', $cantidad);
                $sentenciaDetalle->bindParam(':costo_unitario', $costoUnitario);
                $sentenciaDetalle->bindParam(':subtotal', $subtotal);
                $sentenciaDetalle->execute();

                // Actualizar stock y costo del producto
                $consultaStock = "SELECT stock, costo FROM productos WHERE id = :id";
                $sentenciaStock = $conexion->prepare($consultaStock);
                $sentenciaStock->bindParam(':id', $idProducto);
                $sentenciaStock->execute();
                $productoActual = $sentenciaStock->fetch(PDO::FETCH_ASSOC);
                
                $stockAnterior = (int)$productoActual['stock'];
                $nuevoStock = $stockAnterior + $cantidad;
                
                // Actualizar producto
                $consultaUpdate = "UPDATE productos SET stock = :stock, costo = :costo WHERE id = :id";
                $sentenciaUpdate = $conexion->prepare($consultaUpdate);
                $sentenciaUpdate->bindParam(':stock', $nuevoStock);
                $sentenciaUpdate->bindParam(':costo', $costoUnitario);
                $sentenciaUpdate->bindParam(':id', $idProducto);
                $sentenciaUpdate->execute();

                // Registrar movimiento
                $consultaMovimiento = "INSERT INTO movimientos_stock 
                    (id_producto, tipo, cantidad, cantidad_anterior, cantidad_nueva, motivo, id_usuario) 
                    VALUES (:id_producto, 'ENTRADA', :cantidad, :cantidad_anterior, :cantidad_nueva, 'COMPRA A PROVEEDOR', :id_usuario)";
                $sentenciaMovimiento = $conexion->prepare($consultaMovimiento);
                $sentenciaMovimiento->bindParam(':id_producto', $idProducto);
                $sentenciaMovimiento->bindParam(':cantidad', $cantidad);
                $sentenciaMovimiento->bindParam(':cantidad_anterior', $stockAnterior);
                $sentenciaMovimiento->bindParam(':cantidad_nueva', $nuevoStock);
                $sentenciaMovimiento->bindParam(':id_usuario', $idUsuario);
                $sentenciaMovimiento->execute();
            }

            // Actualizar total de compra
            $consultaUpdateTotal = "UPDATE compras SET total = :total WHERE id = :id";
            $sentenciaUpdateTotal = $conexion->prepare($consultaUpdateTotal);
            $sentenciaUpdateTotal->bindParam(':total', $totalCompra);
            $sentenciaUpdateTotal->bindParam(':id', $idCompra);
            $sentenciaUpdateTotal->execute();

            $conexion->commit();
            $transaccionActiva = false;
            $mensaje = "Ingreso de mercadería registrado exitosamente. Total: $" . number_format($totalCompra, 2);

        } catch (Exception $e) {
            if ($transaccionActiva) {
                $conexion->rollBack();
            }
            $error = $e->getMessage();
        } catch (PDOException $e) {
            if ($transaccionActiva) {
                $conexion->rollBack();
            }
            $error = "Error en ingreso masivo: " . $e->getMessage();
        }
    }

    /* Procesar Egreso Masivo */
    if (isset($_POST['procesar_egreso_masivo'])) {
        $transaccionActiva = false;
        try {
            $conexion->beginTransaction();
            $transaccionActiva = true;

            $fechaEgreso = $_POST['fecha_egreso'];
            $motivo = $_POST['motivo'];
            $observaciones = $_POST['observaciones'] ?? '';

            // Insertar cabecera de egreso
            $consultaEgreso = "INSERT INTO egresos (fecha_egreso, motivo, observaciones, id_usuario) 
                              VALUES (:fecha_egreso, :motivo, :observaciones, :id_usuario)";
            $sentenciaEgreso = $conexion->prepare($consultaEgreso);
            $sentenciaEgreso->bindParam(':fecha_egreso', $fechaEgreso);
            $sentenciaEgreso->bindParam(':motivo', $motivo);
            $sentenciaEgreso->bindParam(':observaciones', $observaciones);
            $sentenciaEgreso->bindParam(':id_usuario', $idUsuario);
            $sentenciaEgreso->execute();
            $idEgreso = $conexion->lastInsertId();

            // Procesar productos
            $productos = $_POST['productos'] ?? [];
            foreach ($productos as $producto) {
                $idProducto = (int)$producto['id'];
                $cantidad = (int)$producto['cantidad'];

                // Verificar stock disponible
                $consultaStock = "SELECT stock FROM productos WHERE id = :id";
                $sentenciaStock = $conexion->prepare($consultaStock);
                $sentenciaStock->bindParam(':id', $idProducto);
                $sentenciaStock->execute();
                $productoActual = $sentenciaStock->fetch(PDO::FETCH_ASSOC);
                
                $stockAnterior = (int)$productoActual['stock'];
                
                if ($stockAnterior < $cantidad) {
                    throw new Exception("Stock insuficiente para el producto ID: $idProducto. Stock actual: $stockAnterior, solicitado: $cantidad");
                }

                $nuevoStock = $stockAnterior - $cantidad;

                // Insertar detalle de egreso
                $consultaDetalle = "INSERT INTO egreso_detalles (id_egreso, id_producto, cantidad) 
                                   VALUES (:id_egreso, :id_producto, :cantidad)";
                $sentenciaDetalle = $conexion->prepare($consultaDetalle);
                $sentenciaDetalle->bindParam(':id_egreso', $idEgreso);
                $sentenciaDetalle->bindParam(':id_producto', $idProducto);
                $sentenciaDetalle->bindParam(':cantidad', $cantidad);
                $sentenciaDetalle->execute();

                // Actualizar stock
                $consultaUpdate = "UPDATE productos SET stock = :stock WHERE id = :id";
                $sentenciaUpdate = $conexion->prepare($consultaUpdate);
                $sentenciaUpdate->bindParam(':stock', $nuevoStock);
                $sentenciaUpdate->bindParam(':id', $idProducto);
                $sentenciaUpdate->execute();

                // Registrar movimiento
                $consultaMovimiento = "INSERT INTO movimientos_stock 
                    (id_producto, tipo, cantidad, cantidad_anterior, cantidad_nueva, motivo, id_usuario) 
                    VALUES (:id_producto, 'SALIDA', :cantidad, :cantidad_anterior, :cantidad_nueva, :motivo, :id_usuario)";
                $sentenciaMovimiento = $conexion->prepare($consultaMovimiento);
                $sentenciaMovimiento->bindParam(':id_producto', $idProducto);
                $sentenciaMovimiento->bindParam(':cantidad', $cantidad);
                $sentenciaMovimiento->bindParam(':cantidad_anterior', $stockAnterior);
                $sentenciaMovimiento->bindParam(':cantidad_nueva', $nuevoStock);
                $sentenciaMovimiento->bindParam(':motivo', $motivo);
                $sentenciaMovimiento->bindParam(':id_usuario', $idUsuario);
                $sentenciaMovimiento->execute();
            }

            $conexion->commit();
            $transaccionActiva = false;
            $mensaje = "Egreso de mercadería registrado exitosamente.";

        } catch (PDOException $e) {
            if ($transaccionActiva) {
                $conexion->rollBack();
            }
            $error = "Error en egreso masivo: " . $e->getMessage();
        } catch (Exception $e) {
            if ($transaccionActiva) {
                $conexion->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

/* Ocultar/Mostrar producto - CON REDIRECCIÓN MEJORADA */
if ($esAdministrador && isset($_GET['toggle']) && isset($_GET['id'])) {
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
            
            // Redirigir para refrescar la página y ver los cambios inmediatamente
            header("Location: inventario.php?message=" . urlencode($mensaje));
            exit();
        } else {
            $error = "Error al cambiar el estado del producto.";
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

/* Obtener mensaje de URL si existe */
if (isset($_GET['message'])) {
    $mensaje = urldecode($_GET['message']);
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
    <link rel="stylesheet" href="assets/css/theme-oscuro.css">

    <script>
        (function() {
            const savedTheme = localStorage.getItem('theme');
            if (savedTheme === 'light') {
                document.documentElement.classList.add('theme-light');
            }
        })();
    </script>
</head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="inventario-container">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12 mb-3">
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
                    
                    <!-- Botones principales - Solo Ingreso y Egreso Masivo -->
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalIngresoMasivo">
                        <i class="bi bi-box-arrow-in-down me-2"></i>Ingreso de Mercadería
                    </button>
                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" data-bs-target="#modalEgresoMasivo">
                        <i class="bi bi-box-arrow-up me-2"></i>Egreso de Mercadería
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
                                                   required readonly>
                                            <div class="form-text">Use los botones de Ingreso/Egreso de Mercadería para ajustar el stock.</div>
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

    <!-- Modal Ingreso Masivo - VERSIÓN CON BUSCADORES -->
    <div class="modal fade" id="modalIngresoMasivo" tabindex="-1" aria-labelledby="modalIngresoMasivoLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="post" action="inventario.php" enctype="multipart/form-data" id="formIngresoMasivo">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalIngresoMasivoLabel">Ingreso Masivo de Mercadería</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="id_proveedor_ingreso" class="form-label">Proveedor *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="buscador_proveedor" 
                                               placeholder="Buscar proveedor por nombre o ID..." 
                                               autocomplete="off">
                                        <input type="hidden" id="id_proveedor_ingreso" name="id_proveedor">
                                        <button class="btn btn-outline-secondary" type="button" id="btn_limpiar_proveedor">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    </div>
                                    <div class="form-text" id="proveedor_info"></div>
                                    <div class="dropdown">
                                        <div class="dropdown-menu w-100" id="dropdown_proveedores" 
                                             style="max-height: 200px; overflow-y: auto;">
                                            <!-- Los resultados de búsqueda aparecerán aquí -->
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fecha_compra" class="form-label">Fecha de Compra *</label>
                                    <input type="date" class="form-control" id="fecha_compra" name="fecha_compra" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="forma_pago" class="form-label">Forma de Pago *</label>
                                    <select class="form-select" id="forma_pago" name="forma_pago" required>
                                        <option value="">Seleccionar forma de pago</option>
                                        <option value="EFECTIVO">Efectivo</option>
                                        <option value="TARJETA">Tarjeta</option>
                                        <option value="TRANSFERENCIA">Transferencia</option>
                                        <option value="CHEQUE">Cheque</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="imagen_factura" class="form-label">Factura/Ticket (QR)</label>
                                    <input type="file" class="form-control" id="imagen_factura" name="imagen_factura" accept="image/*">
                                    <div class="form-text">Puede escanear el código QR de la factura</div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="observaciones" class="form-label">Observaciones</label>
                            <textarea class="form-control" id="observaciones" name="observaciones" rows="2" placeholder="Ej: Compra mensual, pedido especial, etc."></textarea>
                        </div>
                        
                        <hr>
                        <h6>Productos</h6>
                        <div class="mb-3">
                            <div class="input-group">
                                <input type="text" class="form-control" id="buscador_producto" 
                                       placeholder="Buscar producto por nombre..." 
                                       autocomplete="off" disabled>
                                <button class="btn btn-outline-secondary" type="button" id="btn_limpiar_producto" disabled>
                                    <i class="bi bi-x-circle"></i>
                                </button>
                            </div>
                            <div class="dropdown">
                                <div class="dropdown-menu w-100" id="dropdown_productos" 
                                     style="max-height: 200px; overflow-y: auto;">
                                    <!-- Los resultados de búsqueda aparecerán aquí -->
                                </div>
                            </div>
                        </div>
                        
                        <div id="productos-ingreso-container">
                            <div class="producto-seleccionado mb-2 p-2 border rounded d-none" id="producto_base">
                                <div class="row align-items-center">
                                    <div class="col-md-5">
                                        <strong id="producto_nombre">Nombre del producto</strong>
                                        <input type="hidden" name="productos[0][id]" id="producto_id">
                                        <div class="form-text">
                                            Stock actual: <span id="producto_stock">0</span> | 
                                            Stock mínimo: <span id="producto_stock_minimo">0</span>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" class="form-control" name="productos[0][cantidad]" 
                                               min="1" required placeholder="Cantidad">
                                    </div>
                                    <div class="col-md-3">
                                        <input type="number" step="0.01" class="form-control" name="productos[0][costo]" 
                                               required placeholder="Costo unitario">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-sm btn-danger btn-eliminar-producto">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-sm btn-success mt-2" id="btn-agregar-otro-producto" disabled>
                            <i class="bi bi-plus-circle me-1"></i>Agregar Otro Producto
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="procesar_ingreso_masivo" class="btn btn-primary" id="btn-submit-ingreso" disabled>Registrar Ingreso</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Egreso Masivo -->
    <div class="modal fade" id="modalEgresoMasivo" tabindex="-1" aria-labelledby="modalEgresoMasivoLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="post" action="inventario.php">
                    <div class="modal-header">
                        <h5 class="modal-title" id="modalEgresoMasivoLabel">Egreso Masivo de Mercadería</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="fecha_egreso" class="form-label">Fecha de Egreso</label>
                                    <input type="date" class="form-control" id="fecha_egreso" name="fecha_egreso" required value="<?php echo date('Y-m-d'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="motivo" class="form-label">Motivo</label>
                                    <input type="text" class="form-control" id="motivo" name="motivo" required placeholder="Ej: Rotura, merma, devolución">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="observaciones_egreso" class="form-label">Observaciones</label>
                            <textarea class="form-control" id="observaciones_egreso" name="observaciones" rows="2" placeholder="Ej: Detalles adicionales sobre el egreso"></textarea>
                        </div>
                        
                        <hr>
                        <h6>Productos</h6>
                        <div id="productos-egreso-container">
                            <div class="row producto-egreso mb-2">
                                <div class="col-md-8">
                                    <select class="form-select producto-select" name="productos[0][id]" required>
                                        <option value="">Seleccionar producto</option>
                                        <?php foreach ($productos as $producto): ?>
                                            <option value="<?php echo (int)$producto['id']; ?>">
                                                <?php echo htmlspecialchars($producto['nombre']); ?> 
                                                (Stock: <?php echo (int)$producto['stock']; ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="number" class="form-control" name="productos[0][cantidad]" min="1" required placeholder="Cantidad">
                                </div>
                                <div class="col-md-2">
                                    <button type="button" class="btn btn-sm btn-danger btn-eliminar-producto" disabled>
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                        
                        <button type="button" class="btn btn-sm btn-success mt-2" id="btn-agregar-producto-egreso">
                            <i class="bi bi-plus-circle me-1"></i>Agregar Producto
                        </button>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="procesar_egreso_masivo" class="btn btn-primary">Registrar Egreso</button>
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

            // --- Cálculo de Precio ---
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

            // --- Buscador de Productos en la tabla principal ---
            const buscador = document.getElementById('buscadorProductos');
            const tabla = document.getElementById('tablaProductos');
            const filas = tabla ? tabla.getElementsByTagName('tbody')[0].getElementsByTagName('tr') : [];

            if (buscador) {
                buscador.addEventListener('keyup', function() {
                    const textoBusqueda = buscador.value.toLowerCase();
                    
                    for (let i = 0; i < filas.length; i++) {
                        const fila = filas[i];
                        const celdas = fila.getElementsByTagName('td');
                        const textoFila = (celdas[0].textContent + ' ' + celdas[1].textContent + ' ' + celdas[2].textContent).toLowerCase();
                        
                        if (textoFila.indexOf(textoBusqueda) > -1) {
                            fila.style.display = '';
                        } else {
                            fila.style.display = 'none';
                        }
                    }
                });
            }

            // --- GESTIÓN DEL MODAL INGRESO MASIVO CON BUSCADORES ---
            const proveedores = <?php echo json_encode($proveedores); ?>;
            const productos = <?php echo json_encode($productos); ?>;
            let productosFiltrados = [];
            let productosSeleccionados = [];
            let contadorIngreso = 0;

            // Buscador de Proveedores
            const buscadorProveedor = document.getElementById('buscador_proveedor');
            const dropdownProveedores = document.getElementById('dropdown_proveedores');
            const idProveedorInput = document.getElementById('id_proveedor_ingreso');
            const btnLimpiarProveedor = document.getElementById('btn_limpiar_proveedor');
            const proveedorInfo = document.getElementById('proveedor_info');

            // Buscador de Productos
            const buscadorProducto = document.getElementById('buscador_producto');
            const dropdownProductos = document.getElementById('dropdown_productos');
            const btnLimpiarProducto = document.getElementById('btn_limpiar_producto');
            const productosContainer = document.getElementById('productos-ingreso-container');
            const btnAgregarOtroProducto = document.getElementById('btn-agregar-otro-producto');
            const btnSubmitIngreso = document.getElementById('btn-submit-ingreso');

            // Buscar proveedores
            if (buscadorProveedor) {
                buscadorProveedor.addEventListener('input', function() {
                    const texto = this.value.toLowerCase().trim();
                    dropdownProveedores.innerHTML = '';
                    
                    if (texto.length < 2) {
                        dropdownProveedores.classList.remove('show');
                        return;
                    }
                    
                    const resultados = proveedores.filter(proveedor => 
                        proveedor.empresa.toLowerCase().includes(texto) || 
                        proveedor.id.toString().includes(texto)
                    );
                    
                    if (resultados.length > 0) {
                        resultados.forEach(proveedor => {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'dropdown-item';
                            item.innerHTML = `
                                <strong>${proveedor.empresa}</strong> 
                                <small class="text-muted">(ID: ${proveedor.id})</small><br>
                                <small class="text-muted">${proveedor.contacto || ''} - ${proveedor.telefono || ''}</small>
                            `;
                            item.addEventListener('click', function() {
                                seleccionarProveedor(proveedor);
                            });
                            dropdownProveedores.appendChild(item);
                        });
                        dropdownProveedores.classList.add('show');
                    } else {
                        const item = document.createElement('div');
                        item.className = 'dropdown-item text-muted';
                        item.textContent = 'No se encontraron proveedores';
                        dropdownProveedores.appendChild(item);
                        dropdownProveedores.classList.add('show');
                    }
                });
                
                // Cerrar dropdown al hacer clic fuera
                document.addEventListener('click', function(e) {
                    if (!buscadorProveedor.contains(e.target) && !dropdownProveedores.contains(e.target)) {
                        dropdownProveedores.classList.remove('show');
                    }
                });
            }

            // Seleccionar proveedor
            function seleccionarProveedor(proveedor) {
                idProveedorInput.value = proveedor.id;
                buscadorProveedor.value = `${proveedor.empresa} (ID: ${proveedor.id})`;
                dropdownProveedores.classList.remove('show');
                proveedorInfo.innerHTML = `
                    <span class="text-success">
                        <strong>${proveedor.empresa}</strong> seleccionado<br>
                        <small>Contacto: ${proveedor.contacto || 'N/A'} | Tel: ${proveedor.telefono || 'N/A'}</small>
                    </span>
                `;
                
                // Habilitar buscador de productos
                buscadorProducto.disabled = false;
                btnLimpiarProducto.disabled = false;
                
                // Filtrar productos del proveedor seleccionado
                productosFiltrados = productos.filter(producto => 
                    parseInt(producto.id_proveedor) === parseInt(proveedor.id)
                );
                
                validarFormularioIngreso();
            }

            // Limpiar proveedor
            btnLimpiarProveedor.addEventListener('click', function() {
                idProveedorInput.value = '';
                buscadorProveedor.value = '';
                proveedorInfo.innerHTML = '';
                buscadorProducto.disabled = true;
                btnLimpiarProducto.disabled = true;
                buscadorProducto.value = '';
                dropdownProductos.classList.remove('show');
                
                // Limpiar productos seleccionados
                productosSeleccionados = [];
                productosContainer.querySelectorAll('.producto-seleccionado:not(#producto_base)').forEach(el => el.remove());
                contadorIngreso = 0;
                
                validarFormularioIngreso();
            });

            // Buscar productos
            if (buscadorProducto) {
                buscadorProducto.addEventListener('input', function() {
                    const texto = this.value.toLowerCase().trim();
                    dropdownProductos.innerHTML = '';
                    
                    if (texto.length < 2 || productosFiltrados.length === 0) {
                        dropdownProductos.classList.remove('show');
                        return;
                    }
                    
                    const resultados = productosFiltrados.filter(producto => 
                        producto.nombre.toLowerCase().includes(texto) &&
                        !productosSeleccionados.some(p => p.id === producto.id)
                    );
                    
                    if (resultados.length > 0) {
                        resultados.forEach(producto => {
                            const item = document.createElement('button');
                            item.type = 'button';
                            item.className = 'dropdown-item';
                            item.innerHTML = `
                                <strong>${producto.nombre}</strong><br>
                                <small class="text-muted">
                                    Stock: ${producto.stock} | Mín: ${producto.stock_minimo} | 
                                    Categoría: ${producto.categoria}
                                </small>
                            `;
                            item.addEventListener('click', function() {
                                seleccionarProducto(producto);
                            });
                            dropdownProductos.appendChild(item);
                        });
                        dropdownProductos.classList.add('show');
                    } else {
                        const item = document.createElement('div');
                        item.className = 'dropdown-item text-muted';
                        item.textContent = 'No se encontraron productos o ya están seleccionados';
                        dropdownProductos.appendChild(item);
                        dropdownProductos.classList.add('show');
                    }
                });
                
                // Cerrar dropdown al hacer clic fuera
                document.addEventListener('click', function(e) {
                    if (!buscadorProducto.contains(e.target) && !dropdownProductos.contains(e.target)) {
                        dropdownProductos.classList.remove('show');
                    }
                });
            }

            // Limpiar producto
            btnLimpiarProducto.addEventListener('click', function() {
                buscadorProducto.value = '';
                dropdownProductos.classList.remove('show');
            });

            // Seleccionar producto
            function seleccionarProducto(producto) {
                buscadorProducto.value = '';
                dropdownProductos.classList.remove('show');
                
                // Agregar producto a la lista
                agregarProductoALista(producto);
                
                // Habilitar botón para agregar otro producto
                btnAgregarOtroProducto.disabled = false;
                
                validarFormularioIngreso();
            }

            // Agregar producto a la lista visual
            function agregarProductoALista(producto) {
                const productoBase = document.getElementById('producto_base');
                const nuevoProducto = productoBase.cloneNode(true);
                
                nuevoProducto.id = `producto_${producto.id}`;
                nuevoProducto.classList.remove('d-none');
                
                // Actualizar información del producto
                nuevoProducto.querySelector('#producto_nombre').textContent = producto.nombre;
                nuevoProducto.querySelector('#producto_id').value = producto.id;
                nuevoProducto.querySelector('#producto_id').name = `productos[${contadorIngreso}][id]`;
                nuevoProducto.querySelector('#producto_stock').textContent = producto.stock;
                nuevoProducto.querySelector('#producto_stock_minimo').textContent = producto.stock_minimo;
                
                // Actualizar nombres de inputs
                const cantidadInput = nuevoProducto.querySelector('input[name="productos[0][cantidad]"]');
                const costoInput = nuevoProducto.querySelector('input[name="productos[0][costo]"]');
                
                cantidadInput.name = `productos[${contadorIngreso}][cantidad]`;
                costoInput.name = `productos[${contadorIngreso}][costo]`;
                
                // Agregar event listeners para validación
                cantidadInput.addEventListener('input', validarFormularioIngreso);
                costoInput.addEventListener('input', validarFormularioIngreso);
                
                // Configurar botón eliminar
                const btnEliminar = nuevoProducto.querySelector('.btn-eliminar-producto');
                btnEliminar.addEventListener('click', function() {
                    eliminarProductoDeLista(producto.id, nuevoProducto);
                });
                
                productosContainer.appendChild(nuevoProducto);
                productosSeleccionados.push({
                    id: producto.id,
                    nombre: producto.nombre,
                    elemento: nuevoProducto
                });
                
                contadorIngreso++;
            }

            // Eliminar producto de la lista
            function eliminarProductoDeLista(productoId, elemento) {
                productosSeleccionados = productosSeleccionados.filter(p => p.id !== productoId);
                elemento.remove();
                
                // Reindexar productos
                contadorIngreso = 0;
                productosContainer.querySelectorAll('.producto-seleccionado:not(#producto_base)').forEach((el, index) => {
                    const inputs = el.querySelectorAll('input');
                    inputs[0].name = `productos[${index}][id]`;
                    inputs[1].name = `productos[${index}][cantidad]`;
                    inputs[2].name = `productos[${index}][costo]`;
                    contadorIngreso++;
                });
                
                // Deshabilitar botón si no hay productos
                if (productosSeleccionados.length === 0) {
                    btnAgregarOtroProducto.disabled = true;
                }
                
                validarFormularioIngreso();
            }

            // Agregar otro producto
            btnAgregarOtroProducto.addEventListener('click', function() {
                buscadorProducto.focus();
            });

            // Validar formulario
            function validarFormularioIngreso() {
                const idProveedor = idProveedorInput.value;
                const formaPago = document.getElementById('forma_pago').value;
                const productosCompletos = productosSeleccionados.length > 0;
                
                let productosValidos = true;
                
                // Verificar que todos los productos tengan cantidad y costo válidos
                productosContainer.querySelectorAll('.producto-seleccionado:not(#producto_base)').forEach(productoEl => {
                    const cantidad = productoEl.querySelector('input[name$="[cantidad]"]').value;
                    const costo = productoEl.querySelector('input[name$="[costo]"]').value;
                    
                    if (!cantidad || parseInt(cantidad) <= 0 || !costo || parseFloat(costo) <= 0) {
                        productosValidos = false;
                    }
                });
                
                const formularioValido = idProveedor && formaPago && productosCompletos && productosValidos;
                btnSubmitIngreso.disabled = !formularioValido;
                
                return formularioValido;
            }

            // Validación en tiempo real
            document.addEventListener('input', function(e) {
                if (e.target.matches('#forma_pago, input[name$="[cantidad]"], input[name$="[costo]"]')) {
                    validarFormularioIngreso();
                }
            });

            // Prevenir envío si no es válido
            document.getElementById('formIngresoMasivo').addEventListener('submit', function(e) {
                if (!validarFormularioIngreso()) {
                    e.preventDefault();
                    alert('Por favor complete todos los campos requeridos correctamente.');
                }
            });

            // Limpiar modal al cerrar
            document.getElementById('modalIngresoMasivo').addEventListener('hidden.bs.modal', function() {
                // Limpiar proveedor
                idProveedorInput.value = '';
                buscadorProveedor.value = '';
                proveedorInfo.innerHTML = '';
                
                // Limpiar productos
                buscadorProducto.disabled = true;
                btnLimpiarProducto.disabled = true;
                buscadorProducto.value = '';
                productosSeleccionados = [];
                productosContainer.querySelectorAll('.producto-seleccionado:not(#producto_base)').forEach(el => el.remove());
                contadorIngreso = 0;
                
                // Limpiar otros campos
                document.getElementById('forma_pago').value = '';
                document.getElementById('observaciones').value = '';
                
                // Deshabilitar botones
                btnAgregarOtroProducto.disabled = true;
                btnSubmitIngreso.disabled = true;
            });

            // --- Script para manejar el modal de egreso masivo ---
            let contadorEgreso = 1;

            // Agregar producto al egreso
            document.getElementById('btn-agregar-producto-egreso').addEventListener('click', function() {
                const container = document.getElementById('productos-egreso-container');
                const nuevoProducto = document.createElement('div');
                nuevoProducto.className = 'row producto-egreso mb-2';
                nuevoProducto.innerHTML = `
                    <div class="col-md-8">
                        <select class="form-select producto-select" name="productos[${contadorEgreso}][id]" required>
                            <option value="">Seleccionar producto</option>
                            <?php foreach ($productos as $producto): ?>
                                <option value="<?php echo (int)$producto['id']; ?>">
                                    <?php echo htmlspecialchars($producto['nombre']); ?> 
                                    (Stock: <?php echo (int)$producto['stock']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control" name="productos[${contadorEgreso}][cantidad]" min="1" required placeholder="Cantidad">
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-sm btn-danger btn-eliminar-producto">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                `;
                container.appendChild(nuevoProducto);
                contadorEgreso++;
                
                actualizarBotonesEliminarEgreso();
            });

            // Eliminar producto en egreso
            function actualizarBotonesEliminarEgreso() {
                document.querySelectorAll('#productos-egreso-container .btn-eliminar-producto').forEach((btn, index) => {
                    const isFirst = index === 0;
                    if (!isFirst) {
                        btn.disabled = false;
                        btn.onclick = function() {
                            this.closest('.producto-egreso').remove();
                            reindexarProductosEgreso();
                        };
                    }
                });
            }

            function reindexarProductosEgreso() {
                document.querySelectorAll('#productos-egreso-container .producto-egreso').forEach((fila, index) => {
                    const selectInput = fila.querySelector('select');
                    const cantidadInput = fila.querySelector('input[type="number"]');
                    
                    if (selectInput) selectInput.name = `productos[${index}][id]`;
                    if (cantidadInput) cantidadInput.name = `productos[${index}][cantidad]`;
                });
               
                contadorEgreso = document.querySelectorAll('#productos-egreso-container .producto-egreso').length;
            }

            // Validar stock en egresos
            document.addEventListener('change', function(e) {
                if (e.target.closest('#productos-egreso-container') && e.target.type === 'number') {
                    const cantidad = parseInt(e.target.value);
                    const productoSelect = e.target.closest('.row').querySelector('select');
                    const stockText = productoSelect.options[productoSelect.selectedIndex]?.text;
                    const stockMatch = stockText.match(/Stock: (\d+)/);
                    
                    if (stockMatch && cantidad > parseInt(stockMatch[1])) {
                        alert('La cantidad ingresada supera el stock disponible');
                        e.target.value = '';
                    }
                }
            });

            // Limpiar modal egreso al cerrar
            document.getElementById('modalEgresoMasivo').addEventListener('hidden.bs.modal', function() {
                // Mantener solo el primer producto en egreso
                document.querySelectorAll('#productos-egreso-container .producto-egreso:not(:first-child)').forEach(el => el.remove());
                contadorEgreso = 1;
                actualizarBotonesEliminarEgreso();
            });

        });
    </script>

    <script src="assets/js/boton-oscuro.js"></script>
</body>
</html>