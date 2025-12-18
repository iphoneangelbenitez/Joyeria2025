<?php
session_start();
// --- 1. VALIDACIÓN DE SESIÓN ---
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
if (!isset($_SESSION['id_usuario']) && isset($_SESSION['user_id'])) {
    $_SESSION['id_usuario'] = $_SESSION['user_id'];
}

require_once "config/database.php";
$baseDeDatos = new Database();
$conexion = $baseDeDatos->getConnection();

$mensaje = '';
$error = '';

/* ===== 2. PROCESAR FORMULARIOS (POST) ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // A. CREAR NUEVO TIPO DE SERVICIO (NUEVO BLOQUE)
    if (isset($_POST['guardar_nuevo_tipo'])) {
        try {
            $nuevoTipo = strtoupper(trim($_POST['nuevo_tipo_nombre']));
            if (!empty($nuevoTipo)) {
                $stmt = $conexion->prepare("INSERT INTO tipos_servicio (nombre) VALUES (:nombre)");
                $stmt->bindParam(':nombre', $nuevoTipo);
                if ($stmt->execute()) {
                    $mensaje = "Nuevo tipo '$nuevoTipo' agregado correctamente.";
                }
            }
        } catch (PDOException $e) {
            // Error 23000 es duplicado en SQL
            if ($e->getCode() == 23000) {
                $error = "El tipo '$nuevoTipo' ya existe.";
            } else {
                $error = "Error al crear tipo: " . $e->getMessage();
            }
        }
    }

    // B. CREAR NUEVO SERVICIO
    if (isset($_POST['crear_servicio'])) {
        try {
            $idCliente = (int)($_POST['id_cliente'] ?? 0);
            $tipo = $_POST['tipo'] ?? '';
            $producto = $_POST['producto'] ?? '';
            $descripcion = $_POST['descripcion'] ?? '';
            $fechaEntregaEstimada = $_POST['fecha_entrega_estimada'] ?? null;
            $costoServicio = (float)($_POST['costo_servicio'] ?? 0);

            $consultaCrear = "INSERT INTO servicios 
                (id_cliente, tipo, producto, descripcion, fecha_entrega_estimada, costo_servicio, estado, fecha_ingreso) 
                VALUES (:id_cliente, :tipo, :producto, :descripcion, :fecha_entrega_estimada, :costo_servicio, 'PENDIENTE', NOW())";
            
            $sentenciaCrear = $conexion->prepare($consultaCrear);
            $sentenciaCrear->bindParam(':id_cliente', $idCliente, PDO::PARAM_INT);
            $sentenciaCrear->bindParam(':tipo', $tipo);
            $sentenciaCrear->bindParam(':producto', $producto);
            $sentenciaCrear->bindParam(':descripcion', $descripcion);
            $sentenciaCrear->bindParam(':fecha_entrega_estimada', $fechaEntregaEstimada);
            $sentenciaCrear->bindParam(':costo_servicio', $costoServicio);

            if ($sentenciaCrear->execute()) {
                $mensaje = "Servicio creado exitosamente.";
            } else {
                $error = "Error al crear el servicio.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    // C. ACTUALIZAR ESTADO
    if (isset($_POST['actualizar_estado'])) {
        try {
            $idServicio = (int)($_POST['id'] ?? 0);
            $estado = $_POST['estado'];

            $sql = "UPDATE servicios SET estado = :estado";
            if ($estado === 'ENTREGADO') $sql .= ", fecha_entrega = NOW()";
            if ($estado === 'TERMINADO') $sql .= ", fecha_completado = NOW()";
            $sql .= " WHERE id = :id";

            $stmt = $conexion->prepare($sql);
            $stmt->bindParam(':estado', $estado);
            $stmt->bindParam(':id', $idServicio, PDO::PARAM_INT);

            if ($stmt->execute()) {
                $mensaje = "Estado actualizado a: " . $estado;
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

/* ===== 3. OBTENER DATOS (SELECTS) ===== */

// a. Obtener Tipos de Servicio (Desde la BD ahora)
$stmtTipos = $conexion->query("SELECT * FROM tipos_servicio ORDER BY nombre ASC");
$listaTipos = $stmtTipos->fetchAll(PDO::FETCH_ASSOC);

// b. Filtros y Lista Principal
$filtroEstado = $_GET['estado'] ?? '';
$filtroDni = $_GET['dni'] ?? '';

$consultaServicios = "SELECT s.*, c.nombre AS cliente_nombre, c.apellido AS cliente_apellido, c.dni AS cliente_dni,
                      (SELECT id_venta_servicio FROM detalle_servicios_ventas WHERE id_servicio = s.id LIMIT 1) as id_ticket_pago 
                      FROM servicios s 
                      INNER JOIN clientes c ON s.id_cliente = c.id";
$where = [];
$params = [];

if ($filtroEstado !== '') {
    $where[] = "s.estado LIKE :estado"; 
    $params[':estado'] = $filtroEstado;
}
if ($filtroDni !== '') {
    $where[] = "c.dni LIKE :dni";
    $params[':dni'] = "%$filtroDni%";
}
if ($where) {
    $consultaServicios .= " WHERE " . implode(" AND ", $where);
}
$consultaServicios .= " ORDER BY s.fecha_ingreso DESC";

$sentenciaServicios = $conexion->prepare($consultaServicios);
foreach ($params as $k => $v) { $sentenciaServicios->bindValue($k, $v); }
$sentenciaServicios->execute();
$servicios = $sentenciaServicios->fetchAll(PDO::FETCH_ASSOC);

// c. Clientes
$clientes = $conexion->query("SELECT id, nombre, apellido, dni FROM clientes ORDER BY apellido, nombre")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Servicios - Sistema de Joyería</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" />
    <link rel="stylesheet" href="assets/css/theme-oscuro.css" />
    <link rel="stylesheet" href="assets/css/servicio.css" /> 

    <style>
        .precio-texto { font-weight: bold; color: #198754; font-size: 1.2em; }
        html[data-bs-theme="dark"] .precio-texto { color: #2ecc71; }
        
        .servicio-item { border-left: 5px solid #ccc; background-color: var(--bg-card); padding: 15px; margin-bottom: 15px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); }
        .border-PENDIENTE { border-left-color: #6c757d; }
        .border-EN_PROCESO { border-left-color: #0d6efd; }
        .border-TERMINADO { border-left-color: #ffc107; }
        .border-PAGADO { border-left-color: #0dcaf0; }
        .border-ENTREGADO { border-left-color: #198754; }
        .border-CANCELADO { border-left-color: #dc3545; }
        
        .date-info { font-size: 0.85em; color: var(--text-muted); display: block; margin-top: 2px;}
    </style>

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

<div class="servicios-container">
    <div class="container-fluid">
        
        <div class="row">
            <div class="col-12">
                <div class="servicios-header">
                    <h2><i class="bi bi-tools me-2"></i>Módulo de Servicios</h2>
                    <p class="mb-0">Gestión de mantenimiento y reparaciones</p>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger mt-3"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($mensaje): ?>
            <div class="alert alert-success mt-3"><?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>
        
        <div class="row mb-3 mt-3">
            <div class="col-12">
                <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#formularioNuevoServicio">
                    <i class="bi bi-plus-circle me-1"></i> Agregar Nuevo Servicio
                </button>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-12">
                <div class="collapse" id="formularioNuevoServicio">
                    <div class="servicios-card">
                        <div class="servicios-card-header">
                            <h5 class="mb-0">Agregar Nuevo Servicio</h5>
                        </div>
                        <div class="card-body">
                            <form method="post" action="">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Cliente</label>
                                            <select class="form-select" name="id_cliente" required>
                                                <option value="">Seleccionar cliente</option>
                                                <?php foreach ($clientes as $cliente): ?>
                                                    <option value="<?php echo (int)$cliente['id']; ?>">
                                                        <?php echo htmlspecialchars($cliente['apellido'] . ', ' . $cliente['nombre'] . ' (' . $cliente['dni'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        
                                        <div class="mb-3">
                                            <label class="form-label d-flex justify-content-between">
                                                Tipo de Servicio
                                                <button type="button" class="btn btn-sm btn-outline-primary py-0" data-bs-toggle="modal" data-bs-target="#modalNuevoTipo">
                                                    <i class="bi bi-plus"></i> Nuevo Tipo
                                                </button>
                                            </label>
                                            <select class="form-select" name="tipo" required>
                                                <?php foreach($listaTipos as $t): ?>
                                                    <option value="<?= htmlspecialchars($t['nombre']) ?>">
                                                        <?= htmlspecialchars($t['nombre']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label class="form-label">Producto</label>
                                            <input type="text" class="form-control" name="producto" required placeholder="Ej: Reloj Rolex">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label class="form-label">Descripción</label>
                                            <textarea class="form-control" name="descripcion" rows="3" required></textarea>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Fecha Estimada</label>
                                            <input type="date" class="form-control" name="fecha_entrega_estimada" required>
                                        </div>
                                        <div class="mb-3">
                                            <label class="form-label">Costo ($)</label>
                                            <input type="number" step="0.01" class="form-control" name="costo_servicio" value="0">
                                        </div>
                                    </div>
                                </div>
                                <div class="d-grid">
                                    <button type="submit" name="crear_servicio" class="btn btn-success">Guardar Servicio</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div> 
            </div>
        </div>
        
        <div class="row">
            <div class="col-12">
                <div class="servicios-card">
                    <div class="servicios-card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Lista de Servicios</h5>
                        <span class="badge bg-primary">Total: <?php echo count($servicios); ?></span>
                    </div>
                    <div class="card-body">

                        <div class="filtros-container mb-4">
                            <h5><i class="bi bi-funnel me-2"></i>Filtros</h5>
                            <form method="get" action="servicios.php" class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">Estado</label>
                                    <select class="form-select" name="estado">
                                        <option value="">Todos</option>
                                        <option value="PENDIENTE" <?php echo ($filtroEstado==='PENDIENTE')?'selected':''; ?>>Pendiente</option>
                                        <option value="EN_PROCESO" <?php echo ($filtroEstado==='EN_PROCESO')?'selected':''; ?>>En Proceso</option>
                                        <option value="TERMINADO" <?php echo ($filtroEstado==='TERMINADO')?'selected':''; ?>>Terminado</option>
                                        <option value="PAGADO" <?php echo ($filtroEstado==='PAGADO')?'selected':''; ?>>Pagado</option>
                                        <option value="ENTREGADO" <?php echo ($filtroEstado==='ENTREGADO')?'selected':''; ?>>Entregado</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">DNI Cliente</label>
                                    <input type="text" class="form-control" name="dni" value="<?php echo htmlspecialchars($filtroDni); ?>">
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary me-2">Filtrar</button>
                                    <a href="servicios.php" class="btn btn-secondary">Limpiar</a>
                                </div>
                            </form>
                        </div>

                        <hr class="my-4">

                        <?php if (count($servicios) > 0): ?>
                            <?php foreach ($servicios as $servicio): ?>
                                <?php
                                    // Determinar estilo según tipo (ahora dinámico, usamos un hash simple para color o default)
                                    $tipoClase = 'servicio-reparacion'; // Default
                                    if(strpos($servicio['tipo'], 'MANTENIMIENTO') !== false) $tipoClase = 'servicio-mantenimiento';
                                    
                                    $estadoDB = $servicio['estado']; 
                                    $estadoUpper = strtoupper($estadoDB);

                                    $badgeColor = 'secondary';
                                    if ($estadoUpper === 'PENDIENTE') $badgeColor = 'secondary';
                                    if ($estadoUpper === 'EN_PROCESO') $badgeColor = 'primary text-dark';
                                    if ($estadoUpper === 'TERMINADO') $badgeColor = 'warning text-dark';
                                    if ($estadoUpper === 'PAGADO') $badgeColor = 'info text-dark';
                                    if ($estadoUpper === 'ENTREGADO') $badgeColor = 'success';
                                    if ($estadoUpper === 'CANCELADO') $badgeColor = 'danger';
                                    
                                    $idTicketPago = $servicio['id_ticket_pago'] ?? null;
                                ?>
                                
                                <div class="servicio-item <?php echo $tipoClase; ?> border-<?php echo $estadoUpper; ?>">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        
                                        <div>
                                            <h6 class="mb-1 text-primary">#<?php echo $servicio['id']; ?> - <?php echo htmlspecialchars($servicio['producto']); ?></h6>
                                            <p class="mb-1"><strong>Tipo:</strong> <?php echo htmlspecialchars($servicio['tipo']); ?></p>
                                            <p class="mb-1"><strong>Cliente:</strong>
                                                <?php echo htmlspecialchars($servicio['cliente_apellido'] . ', ' . $servicio['cliente_nombre']); ?>
                                                (DNI: <?php echo htmlspecialchars($servicio['cliente_dni']); ?>)
                                            </p>
                                            <p class="mb-1"><strong>Desc:</strong> <?php echo htmlspecialchars($servicio['descripcion']); ?></p>

                                            <?php if ((float)$servicio['costo_servicio'] > 0): ?>
                                                <p class="mb-1 precio-texto">Costo: $<?php echo number_format((float)$servicio['costo_servicio'], 2); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <div class="text-end" style="min-width: 140px;">
                                            <span class="badge bg-<?php echo $badgeColor; ?>" style="font-size:0.9em; padding:8px 12px;">
                                                <?php echo htmlspecialchars($estadoDB); ?>
                                            </span>
                                            
                                            <div class="mt-2">
                                                <span class="date-info">
                                                    <i class="bi bi-calendar-event"></i> Ingreso: <?php echo date('d/m/y', strtotime($servicio['fecha_ingreso'])); ?>
                                                </span>
                                                <?php if (!empty($servicio['fecha_entrega_estimada'])): ?>
                                                    <span class="date-info text-info">
                                                        <i class="bi bi-clock-history"></i> Est: <?php echo date('d/m/y', strtotime($servicio['fecha_entrega_estimada'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                                <?php if (!empty($servicio['fecha_entrega'])): ?>
                                                    <p class="mb-0 mt-1 text-success small fw-bold">
                                                        <i class="bi bi-check-circle-fill"></i> Entregado: <br>
                                                        <?php echo date('d/m/Y H:i', strtotime($servicio['fecha_entrega'])); ?>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="mt-3 d-flex flex-wrap gap-2 justify-content-end">
                                        
                                        <a href="generar_ticket_ingreso.php?id=<?php echo $servicio['id']; ?>" 
                                           class="btn btn-sm btn-outline-secondary" target="_blank" title="Imprimir Comprobante Recepción">
                                            <i class="bi bi-printer"></i> Ingreso
                                        </a>

                                        <?php if ($idTicketPago): ?>
                                            <a href="generar_ticket_final.php?id=<?php echo $idTicketPago; ?>" 
                                               class="btn btn-sm btn-outline-success" target="_blank" title="Imprimir Factura/Pago">
                                                <i class="bi bi-receipt"></i> Ticket Pago
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($estadoUpper === 'PENDIENTE'): ?>
                                            <form method="post" action="" class="d-inline">
                                                <input type="hidden" name="id" value="<?php echo $servicio['id']; ?>">
                                                <input type="hidden" name="estado" value="EN_PROCESO">
                                                <button type="submit" name="actualizar_estado" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-play-fill"></i> Iniciar
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($estadoUpper === 'EN_PROCESO'): ?>
                                            <form method="post" action="" class="d-inline">
                                                <input type="hidden" name="id" value="<?php echo $servicio['id']; ?>">
                                                <input type="hidden" name="estado" value="TERMINADO">
                                                <button type="submit" name="actualizar_estado" class="btn btn-sm btn-warning">
                                                    <i class="bi bi-check-lg"></i> Terminar
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (($estadoUpper === 'TERMINADO' || $estadoUpper === 'COMPLETADO') && !$idTicketPago): ?>
                                            <a href="ventas_servicios.php?id=<?php echo $servicio['id']; ?>" class="btn btn-sm btn-success text-white">
                                                <i class="bi bi-cash-stack"></i> Cobrar
                                            </a>
                                        <?php endif; ?>

                                        <?php if ($estadoUpper === 'PAGADO'): ?>
                                            <form method="post" action="" class="d-inline">
                                                <input type="hidden" name="id" value="<?php echo $servicio['id']; ?>">
                                                <input type="hidden" name="estado" value="ENTREGADO">
                                                <button type="submit" name="actualizar_estado" class="btn btn-sm btn-info text-white">
                                                    <i class="bi bi-box-seam"></i> Entregar
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if (!in_array($estadoUpper, ['ENTREGADO', 'CANCELADO', 'PAGADO', 'PAGADO Y ENTREGADO'])): ?>
                                            <div class="dropdown">
                                                <button class="btn btn-sm btn-outline-danger dropdown-toggle" type="button" data-bs-toggle="dropdown">
                                                    Cancelar
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <li><h6 class="dropdown-header">¿Confirmar?</h6></li>
                                                    <li>
                                                        <form method="post" action="">
                                                            <input type="hidden" name="id" value="<?php echo $servicio['id']; ?>">
                                                            <input type="hidden" name="estado" value="CANCELADO">
                                                            <button type="submit" name="actualizar_estado" class="dropdown-item text-danger">Sí, Cancelar</button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">No se encontraron servicios.</div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalNuevoTipo" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content bg-dark text-white"> <div class="modal-header">
        <h5 class="modal-title">Agregar Nuevo Tipo de Servicio</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST">
          <div class="modal-body">
            <div class="mb-3">
                <label for="nuevoTipoInput" class="form-label">Nombre del Tipo:</label>
                <input type="text" class="form-control" id="nuevoTipoInput" name="nuevo_tipo_nombre" required placeholder="Ej: Limpieza, Ajuste, Grabado">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
            <button type="submit" name="guardar_nuevo_tipo" class="btn btn-primary">Guardar</button>
          </div>
      </form>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/boton-oscuro.js"></script>
</body>
</html>