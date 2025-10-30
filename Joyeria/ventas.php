<?php
// ventas.php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}


/* Compatibilidad de nombres de sesión en español e inglés */
if (!isset($_SESSION['id_usuario']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_usuario'] = $_SESSION['user_id'];
}

/* Verificación de sesión (usa nuevas claves; cae a las viejas si existen) */
if (!isset($_SESSION['id_usuario']) && !isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

require_once "config/database.php";
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

$mensaje = '';
$error = '';

/* Inicializar carrito si no existe (antes de procesar POST para evitar warnings) */
if (!isset($_SESSION['carrito_venta']) || !is_array($_SESSION['carrito_venta'])) {
    $_SESSION['carrito_venta'] = [];
}

/* ---- Procesar formulario de venta ---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Agregar producto al carrito */
    if (isset($_POST['agregar_producto'])) {
        $idProducto = isset($_POST['id_producto']) ? (int)$_POST['id_producto'] : 0;
        $cantidad = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 0;

        // Obtener información del producto
        $consultaProducto = "SELECT * FROM productos WHERE id = :id AND oculto = 0";
        $sentenciaProducto = $conexion->prepare($consultaProducto);
        $sentenciaProducto->bindParam(':id', $idProducto, PDO::PARAM_INT);
        $sentenciaProducto->execute();
        $producto = $sentenciaProducto->fetch(PDO::FETCH_ASSOC);

        if ($producto) {
            $stockTotal = (int)$producto['stock'];

            // ---- INICIO DE MODIFICACIÓN (Lógica de Stock) ----
            
            // 1. Verificar cuánto hay ya en el carrito
            $cantidadEnCarrito = 0;
            foreach ($_SESSION['carrito_venta'] as $item) {
                if ((int)$item['id'] === $idProducto) {
                    $cantidadEnCarrito = (int)$item['cantidad'];
                    break;
                }
            }

            // 2. Calcular el total deseado
            $cantidadTotalDeseada = $cantidadEnCarrito + $cantidad;

            // 3. Verificación de stock corregida
            if ($cantidad > 0 && $cantidadTotalDeseada <= $stockTotal) {
            // ---- FIN DE MODIFICACIÓN ----
            
                // Verificar si el producto ya está en el carrito
                $encontrado = false;
                foreach ($_SESSION['carrito_venta'] as &$item) {
                    if ((int)$item['id'] === $idProducto) {
                        $item['cantidad'] += $cantidad; // Suma la nueva cantidad
                        $encontrado = true;
                        break;
                    }
                }
                unset($item); // romper referencia

                if (!$encontrado) {
                    $_SESSION['carrito_venta'][] = [
                        'id' => (int)$producto['id'],
                        'nombre' => $producto['nombre'],
                        'precio' => (float)$producto['precio'],
                        'cantidad' => $cantidad,
                        'stock' => $stockTotal, // Guardamos el stock total
                    ];
                }

                $mensaje = "Producto agregado al carrito.";
            
            // ---- INICIO DE MODIFICACIÓN (Lógica de Stock) ----
            } elseif ($cantidad <= 0) {
                 $error = "La cantidad debe ser mayor a cero.";
            } else {
                // 4. Error específico
                $stockRestante = $stockTotal - $cantidadEnCarrito;
                if ($stockRestante < 0) $stockRestante = 0; // Por si acaso
                
                $error = "No hay suficiente stock. Stock total: $stockTotal. Ya tiene $cantidadEnCarrito en el carrito. Solo puede agregar $stockRestante más.";
            }
            // ---- FIN DE MODIFICACIÓN ----
        } else {
            $error = "Producto no encontrado.";
        }
    }

    /* Eliminar producto del carrito */
    if (isset($_POST['eliminar_producto'])) {
        $indice = isset($_POST['indice_producto']) ? (int)$_POST['indice_producto'] : -1;
        if ($indice >= 0 && isset($_SESSION['carrito_venta'][$indice])) {
            unset($_SESSION['carrito_venta'][$indice]);
            $_SESSION['carrito_venta'] = array_values($_SESSION['carrito_venta']);
            $mensaje = "Producto eliminado del carrito.";
        }
    }

    /* Actualizar cantidad de un producto en el carrito */
    if (isset($_POST['actualizar_cantidad'])) {
        $indice = isset($_POST['indice_producto']) ? (int)$_POST['indice_producto'] : -1;
        $nuevaCantidad = isset($_POST['nueva_cantidad']) ? (int)$_POST['nueva_cantidad'] : 0;

        if ($indice >= 0 && isset($_SESSION['carrito_venta'][$indice])) {
            $stockDisponible = (int)$_SESSION['carrito_venta'][$indice]['stock'];
            if ($nuevaCantidad > 0 && $nuevaCantidad <= $stockDisponible) {
                $_SESSION['carrito_venta'][$indice]['cantidad'] = $nuevaCantidad;
                $mensaje = "Cantidad actualizada.";
            } else {
                $error = "No hay suficiente stock para esta cantidad.";
            }
        }
    }

    /* Realizar la venta */
    if (isset($_POST['realizar_venta'])) {
        if (empty($_SESSION['carrito_venta'])) {
            $error = "No hay productos en el carrito.";
        } elseif (empty($_POST['id_cliente'])) {
            $error = "Debe seleccionar un cliente.";
        } else {
            try {
                $conexion->beginTransaction();

                $idCliente = (int)$_POST['id_cliente'];
                $metodoPago = isset($_POST['metodo_pago']) ? $_POST['metodo_pago'] : 'EFECTIVO';
                $descuento = isset($_POST['descuento']) ? (float)$_POST['descuento'] : 0.0;

                // Calcular totales
                $subtotal = 0.0;
                foreach ($_SESSION['carrito_venta'] as $productoCarrito) {
                    $subtotal += ((float)$productoCarrito['precio']) * ((int)$productoCarrito['cantidad']);
                }
                $total = $subtotal - ($subtotal * $descuento / 100);

                // Insertar venta
                $consultaVenta = "INSERT INTO ventas (id_cliente, id_usuario, descuento, subtotal, total, metodo_pago) 
                                  VALUES (:id_cliente, :id_usuario, :descuento, :subtotal, :total, :metodo_pago)";
                $sentenciaVenta = $conexion->prepare($consultaVenta);
                $idUsuario = isset($_SESSION['id_usuario']) ? (int)$_SESSION['id_usuario'] : (int)$_SESSION['user_id'];
                $sentenciaVenta->bindParam(':id_cliente', $idCliente, PDO::PARAM_INT);
                $sentenciaVenta->bindParam(':id_usuario', $idUsuario, PDO::PARAM_INT);
                $sentenciaVenta->bindParam(':descuento', $descuento);
                $sentenciaVenta->bindParam(':subtotal', $subtotal);
                $sentenciaVenta->bindParam(':total', $total);
                $sentenciaVenta->bindParam(':metodo_pago', $metodoPago);
                $sentenciaVenta->execute();

                $idVenta = $conexion->lastInsertId();

                // Insertar detalles y actualizar stock
                foreach ($_SESSION['carrito_venta'] as $productoCarrito) {
                    $idProducto = (int)$productoCarrito['id'];
                    $cantidad = (int)$productoCarrito['cantidad'];
                    $precioUnitario = (float)$productoCarrito['precio'];

                    // Detalle
                    $consultaDetalle = "INSERT INTO venta_detalles (id_venta, id_producto, cantidad, precio_unitario) 
                                        VALUES (:id_venta, :id_producto, :cantidad, :precio_unitario)";
                    $sentenciaDetalle = $conexion->prepare($consultaDetalle);
                    $sentenciaDetalle->bindParam(':id_venta', $idVenta, PDO::PARAM_INT);
                    $sentenciaDetalle->bindParam(':id_producto', $idProducto, PDO::PARAM_INT);
                    $sentenciaDetalle->bindParam(':cantidad', $cantidad, PDO::PARAM_INT);
                    $sentenciaDetalle->bindParam(':precio_unitario', $precioUnitario);
                    $sentenciaDetalle->execute();

                    // Actualizar stock (CON VERIFICACIÓN ATÓMICA)
                    // Se agrega "AND stock >= :cantidad" para asegurar que solo se reste si hay stock.
                    $consultaActualizarStock = "UPDATE productos SET stock = stock - :cantidad 
                                                WHERE id = :id_producto AND stock >= :cantidad";
                    $sentenciaActualizar = $conexion->prepare($consultaActualizarStock);
                    $sentenciaActualizar->bindParam(':cantidad', $cantidad, PDO::PARAM_INT);
                    $sentenciaActualizar->bindParam(':id_producto', $idProducto, PDO::PARAM_INT);
                    $sentenciaActualizar->execute();

                    // Verificar si la actualización fue exitosa
                    // Si rowCount() es 0, significa que la condición (stock >= :cantidad) no se cumplió.
                    if ($sentenciaActualizar->rowCount() === 0) {
                        // Lanzamos una excepción para forzar el rollback de toda la transacción
                        throw new Exception("Stock insuficiente para el producto '{$productoCarrito['nombre']}'. Venta cancelada.");
                    }

                    // Registrar movimiento de stock

                    $consultaMovimiento = "INSERT INTO movimientos_stock (id_producto, tipo, cantidad, cantidad_anterior, cantidad_nueva, motivo, id_usuario) 
                                           SELECT :id_producto, 'SALIDA', :cantidad, (stock + :cantidad), stock, 'VENTA', :id_usuario 
                                           FROM productos WHERE id = :id_producto";
                    $sentenciaMovimiento = $conexion->prepare($consultaMovimiento);
                    $sentenciaMovimiento->bindParam(':id_producto', $idProducto, PDO::PARAM_INT);
                    $sentenciaMovimiento->bindParam(':cantidad', $cantidad, PDO::PARAM_INT);
                    $sentenciaMovimiento->bindParam(':id_usuario', $idUsuario, PDO::PARAM_INT);
                    $sentenciaMovimiento->execute();
                }

                $conexion->commit();

                // Limpiar carrito
                $_SESSION['carrito_venta'] = [];

                // Redirigir a generación de PDF
                header("Location: generar_pdf.php?id_venta=" . $idVenta);
                exit();

            } catch (Throwable $e) { // captura Exception y PDOException
                if ($conexion->inTransaction()) {
                    $conexion->rollBack();
                }
                $error = "Error al realizar la venta: " . $e->getMessage();
            }
        }
    }
}

/* ---- Datos para selects ---- */

// Productos
$consultaProductos = "SELECT p.id, p.nombre, p.precio, p.stock, c.nombre AS categoria 
                      FROM productos p 
                      INNER JOIN categorias c ON p.id_categoria = c.id 
                      WHERE p.oculto = 0 AND p.stock > 0 
                      ORDER BY p.nombre";
$sentenciaProductos = $conexion->prepare($consultaProductos);
$sentenciaProductos->execute();
$productos = $sentenciaProductos->fetchAll(PDO::FETCH_ASSOC);

// Clientes
$consultaClientes = "SELECT id, nombre, apellido, dni FROM clientes ORDER BY apellido, nombre";
$sentenciaClientes = $conexion->prepare($consultaClientes);
$sentenciaClientes->execute();
$clientes = $sentenciaClientes->fetchAll(PDO::FETCH_ASSOC);

/* ---- Totales del carrito ---- */
$subtotalCarrito = 0.0;
$totalItemsCarrito = 0; // <-- CAMBIO: Inicializar contador de ítems
foreach ($_SESSION['carrito_venta'] as $productoCarrito) {
    $subtotalCarrito += ((float)$productoCarrito['precio']) * ((int)$productoCarrito['cantidad']);
    $totalItemsCarrito += (int)$productoCarrito['cantidad']; // <-- CAMBIO: Sumar cantidad de ítems
}
$descuentoAplicado = isset($_POST['descuento']) ? (float)$_POST['descuento'] : 0.0;
$totalCarrito = $subtotalCarrito - ($subtotalCarrito * $descuentoAplicado / 100);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ventas - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <link href="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/css/tom-select.bootstrap5.min.css" rel="stylesheet">
    
    <link rel="stylesheet" href="assets/css/theme-oscuro.css">

    <style>
        /* Estilo para los controles de Tom-Select (los buscadores) */
        .ts-control {
            padding-top: 0.5rem;    
            padding-bottom: 0.5rem;
            min-height: calc(2.5rem + 2px); 

            /* --- NUEVOS ESTILOS DARK --- */
            background-color: #343a40; /* Color de fondo oscuro (como el input de Cantidad) */
            border-color: #495057;     /* Color de borde oscuro */
            color: #f8f9fa;            /* Color de texto claro */
        }
        
        /* Placeholder y texto de ítem seleccionado */
        .ts-control .ts-input::placeholder,
        .ts-control > .item {
            color: #f8f9fa;
        }

        /* El campo de texto donde se escribe */
        .ts-input {
            font-size: 1rem;
            color: #f8f9fa !important; /* Forzar color de texto al escribir */
        }

        /* El menú desplegable */
        .ts-dropdown {
            background-color: #343a40;
            border-color: #495057;
        }

        /* Las opciones dentro del desplegable */
        .ts-option {
            color: #f8f9fa;
            padding-top: 0.5rem;
            padding-bottom: 0.5rem;
        }

        /* Opción activa o al pasar el mouse */
        .ts-option.active, 
        .ts-option:hover {
            background-color: #495057; /* Un gris un poco más claro */
            color: #fff;
        }
    </style>
    </head>
<body>
    <?php include 'includes/navbar.php'; ?>

    <div class="ventas-container">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-12">
                    <div class="ventas-header">
                        <h2><i class="bi bi-cash-coin me-2"></i>Módulo de Ventas</h2>
                        <p class="mb-0">Realice ventas de productos de joyería</p>
                    </div>
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($mensaje): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-8">
                    <div class="ventas-card" style="overflow: visible;">
                        <div class="ventas-card-header">
                            <h5 class="mb-0">Agregar Productos</h5>
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="id_producto" class="form-label">Seleccionar Producto (Buscar por ID o Nombre)</label>
                                            <select id="id_producto" name="id_producto" required>
                                                <option value="">Seleccionar producto</option>
                                                <?php foreach ($productos as $producto): ?>
                                                    <option value="<?php echo (int)$producto['id']; ?>"
                                                        data-precio="<?php echo (float)$producto['precio']; ?>"
                                                        data-stock="<?php echo (int)$producto['stock']; ?>">
                                                    (ID: <?php echo (int)$producto['id']; ?>) <?php echo htmlspecialchars($producto['nombre']); ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="cantidad" class="form-label">Cantidad</label>
                                            <input type="number" class="form-control" id="cantidad" name="cantidad" min="1" value="1" required>
                                        </div>
                                    </div>
                                    <div class="col-md-2 d-flex align-items-end">
                                        <button type="submit" name="agregar_producto" class="btn btn-primary">Agregar</button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>

                    <div class="ventas-card">
                        <div class="ventas-card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">Productos en el Carrito</h5>
                            <span class="badge bg-primary">
                                <?php echo count($_SESSION['carrito_venta']); ?> Tipos / <?php echo $totalItemsCarrito; ?> Ítems
                            </span>
                        </div>
                        <div class="card-body">
                            <?php if (count($_SESSION['carrito_venta']) > 0): ?>
                                <?php foreach ($_SESSION['carrito_venta'] as $indice => $productoCarrito): ?>
                                    <div class="producto-item">
                                        <div class="d-flex justify-content-between align-items-start">
                                            <div>
            <h6 class="mb-1"><?php echo htmlspecialchars($productoCarrito['nombre']); ?></h6>
            <p class="mb-1">Precio unitario: $<?php echo number_format((float)$productoCarrito['precio'], 2); ?></p>
            <p class="mb-1">Stock disponible: <?php echo (int)$productoCarrito['stock']; ?></p>
                                            </div>
                                            <div class="text-end">
                                                <form method="post" action="" class="d-inline">
                                                    <input type="hidden" name="indice_producto" value="<?php echo (int)$indice; ?>">
                                                    <div class="input-group mb-2">
                                                        <input type="number" class="form-control" name="nueva_cantidad" value="<?php echo (int)$productoCarrito['cantidad']; ?>" min="1" max="<?php echo (int)$productoCarrito['stock']; ?>" style="width: 80px;">
                                                        <button type="submit" name="actualizar_cantidad" class="btn btn-sm btn-outline-secondary" style="display: none;">Actualizar</button>
                                                    </div>
                                                </form>
                                                <form method="post" action="" class="d-inline">
                                                    <input type="hidden" name="indice_producto" value="<?php echo (int)$indice; ?>">
                                                    <button type="submit" name="eliminar_producto" class="btn btn-sm btn-danger">Eliminar</button>
                                                </form>
                                            </div>
                                        </div>
                                        <div class="mt-2">
                                            <strong>Subtotal: $<?php echo number_format(((float)$productoCarrito['precio']) * ((int)$productoCarrito['cantidad']), 2); ?></strong>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <div class="alert alert-info">No hay productos en el carrito.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="ventas-card">
                        <div class="ventas-card-header">
                            <h5 class="mb-0">Información de la Venta</h5>
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <div class="mb-3">
                                    <label for="id_cliente" class="form-label">Cliente (Buscar por DNI o Nombre)</label>
                                    <select id="id_cliente" name="id_cliente" required>
                                        <option value="">Seleccionar cliente</option>
                                        <?php foreach ($clientes as $cliente): ?>
                                            <option value="<?php echo (int)$cliente['id']; ?>">
                                                <?php echo htmlspecialchars($cliente['apellido'] . ', ' . $cliente['nombre'] . ' (DNI: ' . $cliente['dni'] . ')'); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">Método de Pago</label>
                                    <div class="metodo-pago">
                                        <div class="metodo-pago-option <?php echo (isset($_POST['metodo_pago']) && $_POST['metodo_pago'] === 'EFECTIVO') ? 'active' : ''; ?>" onclick="seleccionarMetodoPago('EFECTIVO')">
                                            <i class="bi bi-cash"></i>
                                            <div>Efectivo</div>
                                            <input type="radio" name="metodo_pago" value="EFECTIVO" <?php echo (isset($_POST['metodo_pago']) && $_POST['metodo_pago'] === 'EFECTIVO') ? 'checked' : ''; ?> required style="display:none;">
                                        </div>
                                        <div class="metodo-pago-option <?php echo (isset($_POST['metodo_pago']) && $_POST['metodo_pago'] === 'TARJETA') ? 'active' : ''; ?>" onclick="seleccionarMetodoPago('TARJETA')">
                                            <i class="bi bi-credit-card"></i>
                                            <div>Tarjeta</div>
                                            <input type="radio" name="metodo_pago" value="TARJETA" <?php echo (isset($_POST['metodo_pago']) && $_POST['metodo_pago'] === 'TARJETA') ? 'checked' : ''; ?> style="display:none;">
                                        </div>
                                        <div class="metodo-pago-option <?php echo (isset($_POST['metodo_pago']) && $_POST['metodo_pago'] === 'TRANSFERENCIA') ? 'active' : ''; ?>" onclick="seleccionarMetodoPago('TRANSFERENCIA')">
                                            <i class="bi bi-bank"></i>
                                            <div>Transferencia</div>
                                            <input type="radio" name="metodo_pago" value="TRANSFERENCIA" <?php echo (isset($_POST['metodo_pago']) && $_POST['metodo_pago'] === 'TRANSFERENCIA') ? 'checked' : ''; ?> style="display:none;">
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="descuento" class="form-label">Descuento (%)</label>
                                    <input type="number" class="form-control" id="descuento" name="descuento" min="0" max="100" value="<?php echo htmlspecialchars($descuentoAplicado); ?>">
                                </div>

                                <div class="resumen-venta">
                                    <div class="resumen-item">
                                        <span>Subtotal:</span>
                                        <span>$<?php echo number_format($subtotalCarrito, 2); ?></span>
                                    </div>
                                    <div class="resumen-item">
                                        <span>Descuento:</span>
                                        <span><?php echo $descuentoAplicado; ?>% (-$<?php echo number_format($subtotalCarrito * $descuentoAplicado / 100, 2); ?>)</span>
                                    </div>
                                    <div class="resumen-total">
                                        <span>Total:</span>
                                        <span>$<?php echo number_format($totalCarrito, 2); ?></span>
                                    </div>
                                </div>

                                <div class="d-grid mt-3">
                                    <button type="submit" name="realizar_venta" class="btn btn-success btn-lg" <?php echo count($_SESSION['carrito_venta']) === 0 ? 'disabled' : ''; ?>>
                                        <i class="bi bi-check-circle"></i> Realizar Venta
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div></div></div><script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script src="https://cdn.jsdelivr.net/npm/tom-select@2.3.1/dist/js/tom-select.complete.min.js"></script>
    
    <script>
        // Seleccionar método de pago (Función original)
        function seleccionarMetodoPago(metodo) {
            document.querySelectorAll('.metodo-pago-option').forEach(option => {
                option.classList.remove('active');
                option.querySelector('input[type="radio"]').checked = false;
            });
            const indice = (metodo === 'EFECTIVO') ? 1 : (metodo === 'TARJETA' ? 2 : 3);
            const opcionSeleccionada = document.querySelector(`.metodo-pago-option:nth-child(${indice})`);
            if (opcionSeleccionada) {
                opcionSeleccionada.classList.add('active');
                opcionSeleccionada.querySelector('input[type="radio"]').checked = true;
            }
        }

        // Ejecutar cuando el DOM esté listo
        document.addEventListener("DOMContentLoaded", function() {
            
            // Inicializar Tom Select para Productos
            var elProducto = document.getElementById('id_producto');
            if (elProducto) {
                new TomSelect(elProducto, {
                    create: false,
                    sortField: {
                        field: "text",
                        direction: "asc"
                    }
                });

                // Lógica de stock corregida para TomSelect
                elProducto.tomselect.on('change', function() {
                    const selectedValue = this.getValue();
                    const cantidad = document.getElementById('cantidad');
                    
                    if (selectedValue && cantidad) {
                        const optionData = this.options[selectedValue]; 
                        if (optionData && optionData.$option) {
                            const originalOptionElement = optionData.$option;
                            const stock = parseInt(originalOptionElement.dataset.stock || '1', 10); 
                            cantidad.max = stock;
                            cantidad.value = 1; // Reseteamos a 1
                        } else {
                            cantidad.max = 1;
                            cantidad.value = 1;
                        }
                    }
                });
            }
            
            // Inicializar Tom Select para Clientes
            var elCliente = document.getElementById('id_cliente');
            if (elCliente) {
                 new TomSelect(elCliente, {
                    create: false,
                    sortField: {
                        field: "text",
                        direction: "asc"
                    }
                });
            }

            // --- INICIO DE MODIFICACIÓN: Actualización automática del carrito ---
            document.querySelectorAll('input[name="nueva_cantidad"]').forEach(input => {
                input.addEventListener('change', function() {
                    // Busca el botón 'actualizar' dentro de su propio formulario y lo "pulsa"
                    this.form.querySelector('button[name="actualizar_cantidad"]').click();
                });
            });
            // --- FIN DE MODIFICACIÓN ---
        });


        // Recalcular al cambiar el descuento (Script original)
        const inputDescuento = document.getElementById('descuento');
        if (inputDescuento) {
            inputDescuento.addEventListener('input', function() {
                // Pequeña optimización: no enviar todo el formulario, 
                // pero si se necesita que el descuento se guarde en POST, 
                // this.form.submit() está bien.
                this.form.submit();
            });
        }
    </script>
     <script src="assets/js/boton-oscuro.js"></script>
</body>
</html> 