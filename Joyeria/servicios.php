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

/* Permisos (ADM) */
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

/* ===== Procesar formularios ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* Crear nuevo servicio */
    if (isset($_POST['crear_servicio'])) {
        try {
            $idCliente = (int)($_POST['id_cliente'] ?? 0);
            $tipo = $_POST['tipo'] ?? '';
            $producto = $_POST['producto'] ?? '';
            $descripcion = $_POST['descripcion'] ?? '';
            $fechaEntregaEstimada = $_POST['fecha_entrega_estimada'] ?? null;
            $costoServicio = (float)($_POST['costo_servicio'] ?? 0);

            // Estado inicial siempre Pendiente
            $estadoInicial = 'PENDIENTE';

            $consultaCrear = "INSERT INTO servicios 
                (id_cliente, tipo, producto, descripcion, fecha_entrega_estimada, costo_servicio, estado, fecha_ingreso) 
                VALUES (:id_cliente, :tipo, :producto, :descripcion, :fecha_entrega_estimada, :costo_servicio, :estado, NOW())";
            
            $sentenciaCrear = $conexion->prepare($consultaCrear);
            $sentenciaCrear->bindParam(':id_cliente', $idCliente, PDO::PARAM_INT);
            $sentenciaCrear->bindParam(':tipo', $tipo);
            $sentenciaCrear->bindParam(':producto', $producto);
            $sentenciaCrear->bindParam(':descripcion', $descripcion);
            $sentenciaCrear->bindParam(':fecha_entrega_estimada', $fechaEntregaEstimada);
            $sentenciaCrear->bindParam(':costo_servicio', $costoServicio);
            $sentenciaCrear->bindParam(':estado', $estadoInicial);

            if ($sentenciaCrear->execute()) {
                $mensaje = "Servicio creado exitosamente.";
            } else {
                $error = "Error al crear el servicio.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }

    /* Actualizar estado del servicio (Lógica manual) */
    if (isset($_POST['actualizar_estado'])) {
        try {
            $idServicio = (int)($_POST['id'] ?? 0);
            $estado = $_POST['estado'] ?? 'PENDIENTE';

            $consultaEstado = "UPDATE servicios SET estado = :estado";
            if ($estado === 'COMPLETADO') {
                $consultaEstado .= ", fecha_completado = NOW()";
            }
            
            $consultaEstado .= " WHERE id = :id";

            $sentenciaEstado = $conexion->prepare($consultaEstado);
            $sentenciaEstado->bindParam(':estado', $estado);
            $sentenciaEstado->bindParam(':id', $idServicio, PDO::PARAM_INT);

            if ($sentenciaEstado->execute()) {
                $mensaje = "Estado del servicio actualizado exitosamente.";
            } else {
                $error = "Error al actualizar el estado del servicio.";
            }
        } catch (PDOException $e) {
            $error = "Error: " . $e->getMessage();
        }
    }
}

/* ===== Listado y filtros ===== */
$filtroEstado = $_GET['estado'] ?? '';
$filtroDni = $_GET['dni'] ?? '';

$consultaServicios = "SELECT s.*, c.nombre AS cliente_nombre, c.apellido AS cliente_apellido, c.dni AS cliente_dni 
                      FROM servicios s 
                      INNER JOIN clientes c ON s.id_cliente = c.id";
$where = [];
$params = [];

if ($filtroEstado !== '') {
    $where[] = "s.estado = :estado";
    $params[':estado'] = $filtroEstado;
}
if ($filtroDni !== '') {
    $where[] = "c.dni = :dni";
    $params[':dni'] = $filtroDni;
}
if ($where) {
    $consultaServicios .= " WHERE " . implode(" AND ", $where);
}
$consultaServicios .= " ORDER BY s.fecha_ingreso DESC";

$sentenciaServicios = $conexion->prepare($consultaServicios);
foreach ($params as $k => $v) {
    $sentenciaServicios->bindValue($k, $v);
}
$sentenciaServicios->execute();
$servicios = $sentenciaServicios->fetchAll(PDO::FETCH_ASSOC);

/* Clientes (para selector) */
$consultaClientes = "SELECT id, nombre, apellido, dni FROM clientes ORDER BY apellido, nombre";
$sentenciaClientes = $conexion->prepare($consultaClientes);
$sentenciaClientes->execute();
$clientes = $sentenciaClientes->fetchAll(PDO::FETCH_ASSOC);
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
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($mensaje): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($mensaje); ?></div>
        <?php endif; ?>

        
        <div class="row mb-3">
            <div class="col-12">
                <button class="btn btn-success" type="button" data-bs-toggle="collapse" data-bs-target="#formularioNuevoServicio" aria-expanded="false" aria-controls="formularioNuevoServicio">
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
                                            <label for="id_cliente" class="form-label">Cliente</label>
                                            <select class="form-select" id="id_cliente" name="id_cliente" required>
                                                <option value="">Seleccionar cliente</option>
                                                <?php foreach ($clientes as $cliente): ?>
                                                    <option value="<?php echo (int)$cliente['id']; ?>">
                                                        <?php echo htmlspecialchars($cliente['apellido'] . ', ' . $cliente['nombre'] . ' (' . $cliente['dni'] . ')'); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="tipo" class="form-label">Tipo de Servicio</label>
                                            <select class="form-select" id="tipo" name="tipo" required>
                                                <option value="">Seleccionar tipo</option>
                                                <option value="MANTENIMIENTO">Mantenimiento de Reloj</option>
                                                <option value="REPARACION">Reparación de Anillo</option>
                                            </select>
                                        </div>

                                        <div class="mb-3">
                                            <label for="producto" class="form-label">Producto</label>
                                            <input type="text" class="form-control" id="producto" name="producto" required
                                                   placeholder="Ej: Reloj Rolex, Anillo de Oro, etc.">
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="descripcion" class="form-label">Descripción del Problema/Servicio</label>
                                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3" required></textarea>
                                        </div>

                                        <div class="mb-3">
                                            <label for="fecha_entrega_estimada" class="form-label">Fecha de Entrega Estimada</label>
                                            <input type="date" class="form-control" id="fecha_entrega_estimada" name="fecha_entrega_estimada" required>
                                        </div>

                                        <div class="mb-3">
                                            <label for="costo_servicio" class="form-label">Costo del Servicio ($)</label>
                                            <input type="number" step="0.01" class="form-control" id="costo_servicio" name="costo_servicio" value="0">
                                        </div>
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" name="crear_servicio" class="btn btn-success">Agregar Servicio</button>
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
                                    <label for="estado" class="form-label">Estado</label>
                                    <select class="form-select" id="estado" name="estado">
                                        <option value="">Todos los estados</option>
                                        <option value="PENDIENTE"   <?php echo ($filtroEstado==='PENDIENTE')?'selected':''; ?>>Pendiente</option>
                                        <option value="EN_PROCESO"  <?php echo ($filtroEstado==='EN_PROCESO')?'selected':''; ?>>En Proceso</option>
                                        <option value="COMPLETADO"  <?php echo ($filtroEstado==='COMPLETADO')?'selected':''; ?>>Completado (Listo)</option>
                                        <option value="ENTREGADO"   <?php echo ($filtroEstado==='ENTREGADO')?'selected':''; ?>>Entregado</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="dni" class="form-label">Buscar por DNI</label>
                                    <input type="text" class="form-control" id="dni" name="dni"
                                           value="<?php echo htmlspecialchars($filtroDni); ?>" placeholder="Ingrese DNI del cliente">
                                </div>
                                <div class="col-md-4 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary me-2">Aplicar Filtros (BD)</button>
                                    <a href="servicios.php" class="btn btn-secondary">Limpiar</a>
                                </div>
                            </form>
                        </div>

                        <hr class="my-4">

                        <?php if (count($servicios) > 0): ?>
                            <?php foreach ($servicios as $servicio): ?>
                                <?php
                                    $tipoClase = ($servicio['tipo'] === 'MANTENIMIENTO') ? 'servicio-mantenimiento' : 'servicio-reparacion';
                                    $estado = $servicio['estado'];
                                    $claseEstado = 'pendiente'; $textoEstado = 'Pendiente';
                                    
                                    // Mapeo de estados para colores CSS
                                    if ($estado === 'EN_PROCESO') { $claseEstado = 'proceso'; $textoEstado = 'En Proceso'; }
                                    if ($estado === 'COMPLETADO') { $claseEstado = 'completado'; $textoEstado = 'Listo para retirar'; }
                                    if ($estado === 'Pagado y Entregado' || $estado === 'Entregado') { $claseEstado = 'entregado'; $textoEstado = 'Entregado'; }
                                    if ($estado === 'Pagado') { $claseEstado = 'pagado'; $textoEstado = 'Pagado (En taller)'; }
                                ?>
                                <div class="servicio-item <?php echo $tipoClase; ?>" 
                                     data-dni="<?php echo htmlspecialchars($servicio['cliente_dni']); ?>"
                                     data-estado="<?php echo htmlspecialchars($servicio['estado']); ?>">

                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <div>
                                            <h6 class="mb-1">#<?php echo $servicio['id']; ?> - <?php echo htmlspecialchars($servicio['producto']); ?></h6>
                                            <p class="mb-1"><strong>Cliente:</strong>
                                                <?php echo htmlspecialchars($servicio['cliente_apellido'] . ', ' . $servicio['cliente_nombre']); ?>
                                                (DNI: <?php echo htmlspecialchars($servicio['cliente_dni']); ?>)
                                            </p>
                                            <p class="mb-1"><strong>Tipo:</strong>
                                                <?php echo ($servicio['tipo'] === 'MANTENIMIENTO') ? 'Mantenimiento de Reloj' : 'Reparación de Anillo'; ?>
                                            </p>
                                            <p class="mb-1"><strong>Descripción:</strong> <?php echo htmlspecialchars($servicio['descripcion']); ?></p>
                                            <p class="mb-1"><strong>Fecha de Ingreso:</strong> <?php echo date('d/m/Y', strtotime($servicio['fecha_ingreso'])); ?></p>
                                            
                                            <?php if (!empty($servicio['fecha_entrega_estimada'])): ?>
                                                <p class="mb-1"><strong>Entrega Estimada:</strong> <?php echo date('d/m/Y', strtotime($servicio['fecha_entrega_estimada'])); ?></p>
                                            <?php endif; ?>
                                            
                                            <?php if ((float)$servicio['costo_servicio'] > 0): ?>
                                                <p class="mb-1"><strong>Costo:</strong> $<?php echo number_format((float)$servicio['costo_servicio'], 2); ?></p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <span class="estado-badge estado-<?php echo $claseEstado; ?>" 
                                                  style="padding: 5px 10px; border-radius: 15px; font-weight: bold; font-size: 0.9em; 
                                                  <?php 
                                                    if($claseEstado=='entregado') echo 'background:#28a745; color:white;';
                                                    elseif($claseEstado=='completado') echo 'background:#ffc107; color:black;';
                                                    elseif($claseEstado=='proceso') echo 'background:#17a2b8; color:white;';
                                                    else echo 'background:#6c757d; color:white;';
                                                  ?>">
                                                <?php echo $textoEstado; ?>
                                            </span>
                                            <?php if (!empty($servicio['fecha_completado'])): ?>
                                                <p class="mb-0 mt-1"><small>Terminado: <?php echo date('d/m/Y', strtotime($servicio['fecha_completado'])); ?></small></p>
                                            <?php endif; ?>
                                            <?php if (!empty($servicio['fecha_entrega'])): ?>
                                                <p class="mb-0 mt-1"><small>Entregado: <?php echo date('d/m/Y', strtotime($servicio['fecha_entrega'])); ?></small></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="mt-3 d-flex flex-wrap gap-2">
                                        <?php if ($servicio['estado'] !== 'COMPLETADO' && $servicio['estado'] !== 'Pagado y Entregado' && $servicio['estado'] !== 'Entregado'): ?>
                                            <form method="post" action="" class="d-inline">
                                                <input type="hidden" name="id" value="<?php echo (int)$servicio['id']; ?>">
                                                <input type="hidden" name="estado" value="<?php echo ($servicio['estado'] === 'PENDIENTE') ? 'EN_PROCESO' : 'COMPLETADO'; ?>">
                                                <button type="submit" name="actualizar_estado" class="btn btn-sm btn-outline-primary">
                                                    <?php echo ($servicio['estado'] === 'PENDIENTE') ? '<i class="bi bi-play-fill"></i> Iniciar Trabajo' : '<i class="bi bi-check-lg"></i> Marcar Terminado'; ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <?php 
                                        $estadosBloqueados = ['Pagado y Entregado', 'Entregado', 'Cancelado'];
                                        if (!in_array($servicio['estado'], $estadosBloqueados)): 
                                        ?>
                                            <a href="ventas_servicios.php?id=<?php echo $servicio['id']; ?>" 
                                               class="btn btn-sm btn-success text-white">
                                                <i class="bi bi-cart-check"></i> Cobrar / Entregar
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            
                            <div id="no-resultados-js" class="alert alert-info" style="display: none;">
                                No hay servicios que coincidan con los filtros en tiempo real.
                            </div>

                        <?php else: ?>
                            <div class="alert alert-info">No hay servicios que coincidan con los filtros aplicados.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    
    /* Script original de fechas */
    const fechaEntregaInput = document.getElementById('fecha_entrega_estimada');
    if (fechaEntregaInput) {
        const hoy = new Date();
        const min = hoy.toISOString().split('T')[0];
        fechaEntregaInput.min = min;

        if (!fechaEntregaInput.value) {
            const enUnaSemana = new Date(hoy);
            enUnaSemana.setDate(hoy.getDate() + 7);
            fechaEntregaInput.value = enUnaSemana.toISOString().split('T')[0];
        }
    }

    /* ===== Filtro en tiempo real (Client-side) ===== */
    const filtroDniInput = document.getElementById('dni');
    const filtroEstadoInput = document.getElementById('estado');
    const itemsServicio = document.querySelectorAll('.servicio-item');
    const noResultadosMsg = document.getElementById('no-resultados-js');

    function filtrarServicios() {
        if (!noResultadosMsg) return;

        const filtroDni = filtroDniInput.value.toLowerCase().trim();
        const filtroEstado = filtroEstadoInput.value; 
        let visiblesCount = 0;

        itemsServicio.forEach(function(item) {
            const itemDni = item.dataset.dni.toLowerCase();
            const itemEstado = item.dataset.estado;

            const dniMatch = (filtroDni === '') || itemDni.includes(filtroDni);
            // Ajuste para el filtro de estado: si eliges "COMPLETADO", también muestra los entregados si quieres,
            // o se mantiene estricto. Aquí es coincidencia exacta o vacío.
            const estadoMatch = (filtroEstado === '') || (itemEstado === filtroEstado);

            if (dniMatch && estadoMatch) {
                item.style.display = ''; 
                visiblesCount++;
            } else {
                item.style.display = 'none';
            }
        });

        noResultadosMsg.style.display = (visiblesCount === 0) ? 'block' : 'none';
    }

    if (filtroDniInput) {
        filtroDniInput.addEventListener('input', filtrarServicios);
    }
    if (filtroEstadoInput) {
        filtroEstadoInput.addEventListener('change', filtrarServicios);
    }
});
</script>
<script src="assets/js/boton-oscuro.js"></script>
</body>
</html>