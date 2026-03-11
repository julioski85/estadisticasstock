<?php
// index.php
require_once __DIR__ . '/db.php';

function h($v){return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');}
function money_mx($n){return '$'.number_format((float)$n, 2, '.', ',');}

// -------- CONFIG --------
// IMPORTANTE:
// - Las keys de $warehousesOrder deben ser EXACTAMENTE sma_warehouses.code (DB)
// - $warehouseLabels es SOLO visual (lo que quieres que se vea en la tabla)

$warehousesOrder = [
    'AC' => 'Almacen Central',
    'PM' => 'Santiago Tianguistenco - EDO. MÉX.',
    'SA' => 'Plaza San Angel Ixtlahuaca - EDO. MÉX.',
    'SL' => 'Plaza Sendero Lerma - EDO. MÉX.',
    'AM' => 'Plaza Las Américas Metepec - EDO. MÉX.',
    'CA' => 'Chedraui Alfredo Del Mazo Toluca - EDO. MÉX.',
    'AT' => 'Atlacomulco - EDO. MÉX.',
    'PZ' => 'Plaza Zina - EDO. MÉX.',
    'XONA' => 'Plaza Peas Xona - EDO. MÉX.',
];

// Labels visibles (SOLO CAMBIAMOS los 3 que pediste)
$warehouseLabels = [
    'AC' => 'AC',
    'PL' => 'PL',
    'PM' => 'PM',
    'SA' => 'IXT',
    'SL' => 'LER',
    'AM' => 'MET',
    'CA' => 'DMZ',
    'AT' => 'ATL',
    'PZ' => 'ZNA',
    'CM' => 'CM',
    'XONA' => 'XNA',
];

$warehousePrintNames = [
    'AC' => 'A. Central',
    'PM' => 'Tianguis.',
    'SA' => 'San Angel',
    'SL' => 'Sendero',
    'AM' => 'Metepec',
    'CA' => 'Del Mazo',
    'AT' => 'Atlaco.',
    'PZ' => 'Zina',
    'XONA' => 'Xona',
];

// Orden de productos (filas)
$productOrder = [
    11 => 1,  // 10.0 Ultra
    16 => 2,  // 2.0 normal
    19 => 3,  // 2.0 600 ml
    18 => 4,  // 2.1
    15 => 5,  // 3.0
    12 => 6,  // 4.0
    32 => 7,  // 5.0
    14 => 8,  // Animalia
    20 => 9,  // Tepezcohuite
    44 => 10, // 11.0 Akkermansia
];

$targetStock = []; // si no usas stock ideal, se queda vacío

$productIds = array_keys($productOrder);
$warehouseCodes = array_keys($warehousesOrder);
$productIdsSql = implode(',', array_map('intval', $productIds));
$warehouseCodesSql = implode("','", array_map(function($x){ return addslashes($x); }, $warehouseCodes));

// -------- TABLA 1: STOCK ACTUAL (DB) --------
$sqlStock = "
    SELECT
        p.id   AS product_id,
        p.name AS product_name,
        w.code AS warehouse_code,
        SUM(wp.quantity) AS quantity
    FROM sma_warehouses_products wp
    JOIN sma_products   p ON p.id = wp.product_id
    JOIN sma_warehouses w ON w.id = wp.warehouse_id
    WHERE p.id IN ($productIdsSql)
      AND w.code IN ('$warehouseCodesSql')
    GROUP BY p.id, p.name, w.code
    ORDER BY p.id, w.code
";

try {
    $stmt = $pdo->query($sqlStock);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    die('Error al ejecutar la consulta de stock: ' . h($e->getMessage()));
}

$products = [];
foreach ($rows as $row) {
    $pid    = (int)$row['product_id'];
    $pname  = $row['product_name'];
    $wcode  = $row['warehouse_code'];
    $qty    = (float)$row['quantity'];

    if (!isset($warehousesOrder[$wcode])) continue;

    if (!isset($products[$pid])) {
        $products[$pid] = [
            'name' => $pname,
            'quantities' => array_fill_keys(array_keys($warehousesOrder), 0),
        ];
    }
    $products[$pid]['quantities'][$wcode] = $qty;
}

uksort($products, function($a, $b) use ($productOrder) {
    $oa = $productOrder[$a] ?? 9999;
    $ob = $productOrder[$b] ?? 9999;
    return $oa <=> $ob;
});

// -------- TABLA 2: VENTAS ULTIMOS 7 DIAS (DB) --------
$warehouseIdByCode = [];
try {
    $stmtW = $pdo->query("SELECT id, code FROM sma_warehouses WHERE code IN ('$warehouseCodesSql')");
    $whRows = $stmtW->fetchAll(PDO::FETCH_ASSOC);
    foreach ($whRows as $w) {
        $warehouseIdByCode[$w['code']] = (int)$w['id'];
    }
} catch (PDOException $e) {
    // si falla, dejamos vacío y la tabla mostrará sin datos
}

$warehouseIds = array_values($warehouseIdByCode);
$codeByWarehouseId = !empty($warehouseIdByCode) ? array_flip($warehouseIdByCode) : [];

$salesPivot = [];      // [day][code] => ['total'=>x,'count'=>y]
$salesDays = [];       // lista ordenada de days
$salesDayTotals = [];  // [day] => ['total'=>x,'count'=>y]

if (!empty($warehouseIds)) {
    $inIds = implode(',', array_map('intval', $warehouseIds));
    $sqlSales = "
        SELECT
            DATE(`date`) AS day,
            warehouse_id,
            COUNT(*) AS num_sales,
            SUM(grand_total) AS total_sales
        FROM sma_sales
        WHERE DATE(`date`) >= (CURDATE() - INTERVAL 6 DAY)
          AND warehouse_id IN ($inIds)
        GROUP BY DATE(`date`), warehouse_id
        ORDER BY DATE(`date`) DESC
    ";

    try {
        $stmtS = $pdo->query($sqlSales);
        $sRows = $stmtS->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sRows as $r) {
            $day = $r['day'];
            $wid = (int)$r['warehouse_id'];
            $total = (float)$r['total_sales'];
            $count = (int)$r['num_sales'];

            if (!isset($salesPivot[$day])) {
                $salesPivot[$day] = [];
                $salesDayTotals[$day] = ['total' => 0.0, 'count' => 0];
            }

            $code = $codeByWarehouseId[$wid] ?? null;
            if ($code === null) continue;

            $salesPivot[$day][$code] = ['total' => $total, 'count' => $count];
            $salesDayTotals[$day]['total'] += $total;
            $salesDayTotals[$day]['count'] += $count;
        }

        // asegurar que salgan los 7 dias aunque no haya ventas
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime('-'.$i.' day'));
            if (!isset($salesPivot[$d])) {
                $salesPivot[$d] = [];
                $salesDayTotals[$d] = ['total' => 0.0, 'count' => 0];
            }
        }

        $salesDays = array_keys($salesPivot);
        rsort($salesDays);

    } catch (PDOException $e) {
        // ignore
    }
}

// -------- TABLA 3: CLIENTES (DB) --------
$allowedPerPage = [25, 200, 500, 700, 1000];
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
if (!in_array($perPage, $allowedPerPage, true)) $perPage = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $perPage;

$cg = isset($_GET['cg']) ? trim((string)$_GET['cg']) : '';

// lista para dropdown
$customerGroups = [];
try {
    $stmtCg = $pdo->query("SELECT DISTINCT customer_group_name FROM sma_companies WHERE group_name='customer' AND customer_group_name IS NOT NULL AND customer_group_name <> '' ORDER BY customer_group_name ASC");
    $customerGroups = $stmtCg->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    $customerGroups = [];
}

$where = "WHERE group_name='customer'";
$params = [];
if ($cg !== '') {
    $where .= " AND customer_group_name = :cg";
    $params[':cg'] = $cg;
}

// export CSV de lo que se esta viendo (misma pagina, filtro y per_page)
if (isset($_GET['export']) && $_GET['export'] === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="clientes_export.csv"');

    echo "\xEF\xBB\xBF"; // BOM para Excel
    $out = fopen('php://output', 'w');

    fputcsv($out, ['customer_group_name','name','company','address','city','state','postal_code','phone','email']);

    $sqlExport = "
        SELECT customer_group_name, name, company, address, city, state, postal_code, phone, email
        FROM sma_companies
        $where
        ORDER BY customer_group_name ASC, name ASC
        LIMIT :limit OFFSET :offset
    ";

    try {
        $st = $pdo->prepare($sqlExport);
        foreach ($params as $k => $v) $st->bindValue($k, $v);
        $st->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $st->execute();
        while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($out, $row);
        }
    } catch (PDOException $e) {
        // si falla, export vacio
    }

    fclose($out);
    exit;
}

$totalCustomers = 0;
$customers = [];
try {
    $stCount = $pdo->prepare("SELECT COUNT(*) FROM sma_companies $where");
    foreach ($params as $k => $v) $stCount->bindValue($k, $v);
    $stCount->execute();
    $totalCustomers = (int)$stCount->fetchColumn();

    $sqlCustomers = "
        SELECT customer_group_name, name, company, address, city, state, postal_code, phone, email
        FROM sma_companies
        $where
        ORDER BY customer_group_name ASC, name ASC
        LIMIT :limit OFFSET :offset
    ";
    $st = $pdo->prepare($sqlCustomers);
    foreach ($params as $k => $v) $st->bindValue($k, $v);
    $st->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
    $st->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $st->execute();
    $customers = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $customers = [];
}

$totalPages = (int)max(1, ceil($totalCustomers / $perPage));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page - 1) * $perPage; }

function build_query($overrides = []) {
    $q = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return http_build_query($q);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Inventario BioMaussan</title>
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <style>
        :root{
            --bg-main:#f3f4f6;
            --bg-card:#ffffff;
            --border:rgba(17,24,39,.12);
            --text:#111827;
            --muted:#6b7280;
            --shadow:0 10px 25px rgba(17,24,39,.08);
            --green:#16a34a;
            --yellow:#ca8a04;
            --red:#dc2626;
        }
        *{box-sizing:border-box;}
        body{
            margin:0;
            min-height:100vh;
            font-family:system-ui,-apple-system,BlinkMacSystemFont,"SF Pro Text",sans-serif;
            background:var(--bg-main);
            color:var(--text);
            display:flex;
            align-items:flex-start;
            justify-content:center;
            padding:32px 16px;
        }
        .shell{width:100%;max-width:1280px;}
        .header{margin-bottom:18px;}
        h1{margin:0;font-size:24px;letter-spacing:.06em;text-transform:uppercase;}
        .subtitle{margin:6px 0 0;font-size:13px;color:var(--muted);}
        .card{
            border-radius:16px;
            background:var(--bg-card);
            border:1px solid var(--border);
            box-shadow:var(--shadow);
            overflow:hidden;
            margin-bottom:22px;
        }
        .card-header{
            padding:10px 16px;
            border-bottom:1px solid var(--border);
            display:flex;
            flex-wrap:wrap;
            justify-content:space-between;
            gap:10px;
            align-items:center;
        }
        .card-header-title{font-size:13px;text-transform:uppercase;letter-spacing:.14em;color:var(--muted);}
        .table-wrapper{max-height:60vh;overflow:auto;}
        table{border-collapse:separate;border-spacing:0;width:100%;min-width:720px;}
        th,td{border-right:1px solid var(--border);border-bottom:1px solid var(--border);padding:6px 8px;font-size:12px;}
        th{position:sticky;top:0;background:#ffffff;text-align:center;z-index:2;}
        th:first-child{text-align:left;}
        th:first-child,td:first-child{border-left:1px solid var(--border);}
        tr td{background:#ffffff;}
        td:first-child{font-weight:600;}
        .header-short{font-size:11px;font-weight:700;}
        .header-long{font-size:9px;color:var(--muted);}
        .qty{font-weight:700;}
        .muted{color:var(--muted);}
        .legend{display:flex;flex-wrap:wrap;gap:12px;font-size:11px;color:var(--muted);}
        .legend span{display:inline-flex;align-items:center;gap:6px;}
        .dot{width:9px;height:9px;border-radius:999px;}
        .dot-green{background:var(--green);} .dot-yellow{background:var(--yellow);} .dot-red{background:var(--red);}
        .lvl-good{color:var(--green);} .lvl-warn{color:var(--yellow);} .lvl-low{color:var(--red);} .lvl-na{color:var(--muted);}

        .controls{display:flex;flex-wrap:wrap;gap:10px;align-items:center;}
        .control{display:flex;gap:6px;align-items:center;font-size:12px;color:var(--muted);}
        select,input{border:1px solid var(--border);border-radius:10px;padding:6px 10px;background:#fff;color:var(--text);}
        .btn{border:1px solid var(--border);border-radius:10px;padding:7px 12px;background:#fff;color:var(--text);cursor:pointer;font-size:12px;font-weight:700;}
        .btn:hover{box-shadow:0 6px 18px rgba(17,24,39,.08);}
        .pager{display:flex;gap:10px;align-items:center;flex-wrap:wrap;font-size:12px;color:var(--muted);}
        .link{color:var(--text);text-decoration:none;border:1px solid var(--border);border-radius:10px;padding:6px 10px;background:#fff;font-weight:700;}
        .link:hover{box-shadow:0 6px 18px rgba(17,24,39,.08);}
        .print-only{display:none !important;}
        .print-note-col{display:none;}
        .print-actions{display:flex;justify-content:flex-end;padding:12px 16px 16px;}
        .print-stock-table{min-width:0;}

        @page{
            size:A4 landscape;
            margin:8mm;
        }

        @media print{
            body{
                padding:0;
                background:#fff;
                display:block;
            }
            .shell{
                max-width:none;
                width:100%;
            }
            body.print-stock-mode .no-print{display:none !important;}
            body.print-stock-mode .print-target{display:block !important;}
            body.print-stock-mode .print-target{
                box-shadow:none;
                border:0;
                border-radius:0;
                margin:0;
                overflow:visible;
            }
            body.print-stock-mode .print-target .card-header{
                padding:0 0 6mm;
                border-bottom:0;
            }
            body.print-stock-mode .print-target .table-wrapper{
                max-height:none;
                overflow:visible;
            }
            body.print-stock-mode .print-target table{
                width:100%;
                min-width:0;
                table-layout:fixed;
                border-collapse:collapse;
            }
            body.print-stock-mode .print-target th,
            body.print-stock-mode .print-target td{
                font-size:6px;
                line-height:1.1;
                padding:1.6mm .6mm;
                border:1px solid #9ca3af;
                color:#000;
                background:#fff !important;
                word-break:normal;
                overflow-wrap:anywhere;
            }
            body.print-stock-mode .print-target th{
                position:static;
            }
            body.print-stock-mode .print-target th:first-child,
            body.print-stock-mode .print-target td:first-child{
                width:38mm;
                min-width:38mm;
                max-width:38mm;
                word-break:break-word;
            }
            body.print-stock-mode .print-target th:not(:first-child),
            body.print-stock-mode .print-target td:not(:first-child){
                width:13mm;
                min-width:13mm;
                max-width:13mm;
            }
            body.print-stock-mode .print-target .header-short{font-size:6.8px;}
            body.print-stock-mode .print-target .header-long{font-size:4.8px;color:#000;}
            body.print-stock-mode .print-target .print-note-col{
                display:table-cell;
                width:13mm;
                min-width:13mm;
                max-width:13mm;
                padding:0;
            }
            body.print-stock-mode .print-target .qty{color:#000 !important;}
            body.print-stock-mode .print-only{display:table-cell !important;}
        }

        @media (max-width:768px){
            body{padding:16px 8px;}
            h1{font-size:20px;}
            .subtitle{font-size:12px;}
            table{min-width:640px;}
        }
    </style>
</head>
<body>
<div class="shell">
    <header class="header no-print">
        <h1>Inventario BioMaussan</h1>
        <p class="subtitle">Tablas: (1) stock actual, (2) ventas últimos 7 días, (3) clientes (filtro + export).</p>
    </header>

    <!-- TABLA 1: STOCK ACTUAL -->
    <section class="card print-target">
        <div class="card-header">
            <div class="card-header-title">STOCK ACTUAL</div>
            <div class="legend">
                <span><span class="dot dot-green"></span>Verde: ideal o arriba</span>
                <span><span class="dot dot-yellow"></span>Amarillo: 50% a &lt; ideal</span>
                <span><span class="dot dot-red"></span>Rojo: menos de 50%</span>
                <span class="muted">(Si no hay ideal, queda neutral)</span>
            </div>
        </div>

        <div class="print-actions no-print">
            <button type="button" class="btn" onclick="printStockTable()">Imprimir tabla A4 horizontal</button>
        </div>

        <div class="table-wrapper">
            <table class="print-stock-table">
                <thead>
                <tr>
                    <th>Producto</th>
                    <?php foreach ($warehousesOrder as $code => $fullName): ?>
                        <th class="print-note-col print-only"></th>
                        <th>
                            <div class="header-short"><?= h($warehouseLabels[$code] ?? $code) ?></div>
                            <div class="header-long"><?= h($warehousePrintNames[$code] ?? $fullName) ?></div>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="<?= 1 + (count($warehousesOrder) * 2) ?>">No hay datos.</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $pid => $pdata): ?>
                        <tr>
                            <td><?= h($pdata['name']) ?></td>
                            <?php foreach ($warehousesOrder as $code => $fullName): ?>
                                <?php
                                    $actual = $pdata['quantities'][$code] ?? 0;
$actualDisplay = (int) floor((float)$actual);


                                    $class = 'lvl-na';
                                    if (isset($targetStock[$pid][$code])) {
                                        $ideal = (float)$targetStock[$pid][$code];
                                        if ($ideal > 0) {
                                            $ratio = $actual / $ideal;
                                            if ($ratio >= 1) $class = 'lvl-good';
                                            elseif ($ratio >= 0.5) $class = 'lvl-warn';
                                            else $class = 'lvl-low';
                                        }
                                    }
                                ?>
                                <td class="print-note-col print-only"></td>
                                <td style="text-align:center;"><span class="qty <?= h($class) ?>"><?= h($actualDisplay) ?></span></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- TABLA 2: VENTAS ULTIMOS 7 DIAS -->
    <section class="card no-print">
        <div class="card-header">
            <div class="card-header-title">VENTAS · ULTIMOS 7 DIAS (POR DIA Y ALMACEN)</div>
            <div class="muted" style="font-size:12px;">Celda = total (numero de ventas)</div>
        </div>
        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Dia</th>
                    <?php foreach ($warehousesOrder as $code => $fullName): ?>
                        <th><?= h($warehouseLabels[$code] ?? $code) ?></th>
                    <?php endforeach; ?>
                    <th>Total dia</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($salesDays)): ?>
                    <tr><td colspan="<?= 2 + count($warehousesOrder) ?>">No hay datos (o no se pudo consultar sma_sales).</td></tr>
                <?php else: ?>
                    <?php foreach ($salesDays as $day): ?>
                        <tr>
                            <td><?= h($day) ?></td>
                            <?php foreach ($warehousesOrder as $code => $fullName): ?>
                                <?php
                                    $cell = $salesPivot[$day][$code] ?? ['total' => 0.0, 'count' => 0];
                                    $txt = money_mx($cell['total']) . ' (' . (int)$cell['count'] . ')';
                                ?>
                                <td style="text-align:center;"><?= h($txt) ?></td>
                            <?php endforeach; ?>
                            <?php
                                $t = $salesDayTotals[$day] ?? ['total' => 0.0, 'count' => 0];
                                $txtT = money_mx($t['total']) . ' (' . (int)$t['count'] . ')';
                            ?>
                            <td style="text-align:center;font-weight:700;"><?= h($txtT) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- TABLA 3: CLIENTES -->
    <section class="card no-print">
        <div class="card-header">
            <div class="card-header-title">CLIENTES (sma_companies)</div>
            <form method="get" class="controls">
                <div class="control">
                    <label for="cg">Tienda:</label>
                    <select name="cg" id="cg">
                        <option value="">Todas</option>
                        <?php foreach ($customerGroups as $g): ?>
                            <option value="<?= h($g) ?>" <?= ($cg === (string)$g) ? 'selected' : '' ?>><?= h($g) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="control">
                    <label for="per_page">Mostrar:</label>
                    <select name="per_page" id="per_page">
                        <?php foreach ($allowedPerPage as $pp): ?>
                            <option value="<?= (int)$pp ?>" <?= ($perPage === (int)$pp) ? 'selected' : '' ?>><?= (int)$pp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <input type="hidden" name="page" value="1" />
                <button class="btn" type="submit">Aplicar</button>
                <a class="link" href="?<?= h(build_query(['export' => '1'])) ?>">Descargar Excel</a>
            </form>
        </div>

        <div style="padding:10px 16px;" class="pager">
            <span>Total: <strong><?= (int)$totalCustomers ?></strong></span>
            <span>Pagina <strong><?= (int)$page ?></strong> / <strong><?= (int)$totalPages ?></strong></span>
            <?php if ($page > 1): ?>
                <a class="link" href="?<?= h(build_query(['page' => $page - 1])) ?>">← Anterior</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
                <a class="link" href="?<?= h(build_query(['page' => $page + 1])) ?>">Siguiente →</a>
            <?php endif; ?>
        </div>

        <div class="table-wrapper">
            <table>
                <thead>
                <tr>
                    <th>Tienda</th>
                    <th>Nombre</th>
                    <th>Company</th>
                    <th>Address</th>
                    <th>City</th>
                    <th>State</th>
                    <th>Postal</th>
                    <th>Phone</th>
                    <th>Email</th>
                </tr>
                </thead>
                <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="9">No hay clientes para mostrar.</td></tr>
                <?php else: ?>
                    <?php foreach ($customers as $c): ?>
                        <tr>
                            <td><?= h($c['customer_group_name']) ?></td>
                            <td><?= h($c['name']) ?></td>
                            <td><?= h($c['company']) ?></td>
                            <td><?= h($c['address']) ?></td>
                            <td><?= h($c['city']) ?></td>
                            <td><?= h($c['state']) ?></td>
                            <td><?= h($c['postal_code']) ?></td>
                            <td><?= h($c['phone']) ?></td>
                            <td><?= h($c['email']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
<script>
function printStockTable(){
    document.body.classList.add('print-stock-mode');
    const cleanup = () => {
        document.body.classList.remove('print-stock-mode');
        window.removeEventListener('afterprint', cleanup);
    };
    window.addEventListener('afterprint', cleanup);
    window.print();
    setTimeout(cleanup, 1000);
}
</script>
</body>
</html>
