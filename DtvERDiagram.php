<?php
$host = '127.0.0.1';
$user = 'root';
$password = '';

$conn = new mysqli($host, $user, $password, '');
if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

$dbs = $conn->query("SHOW DATABASES");

function showStructure($conn, $db, $table)
{
    echo "<div class='table-container'>";
    echo "<div class='card mb-4'><div class='card-header bg-dark text-white'>
            <strong>Table: $table (Database: $db)</strong>
          </div><div class='card-body'>";

    $desc = $conn->query("DESCRIBE `$table`");
    if ($desc) {
        echo "<div class='table-responsive'><table class='table table-bordered'>
                <thead class='table-light'>
                    <tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>
                </thead><tbody>";
        while ($row = $desc->fetch_assoc()) {
            echo "<tr>";
            foreach ($row as $value) {
                echo "<td style='font-family: \"Courier New\", Courier, monospace;'>" . htmlspecialchars($value) . "</td>";
            }
            echo "</tr>";
        }
        echo "</tbody></table></div>";
    }

    $fkQuery = "
        SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = '$table' AND REFERENCED_TABLE_NAME IS NOT NULL";

    $fks = $conn->query($fkQuery);
    if ($fks && $fks->num_rows > 0) {
        echo "<h5>🔗 Foreign Keys</h5>";
        echo "<table class='table table-sm table-striped'>";
        echo "<thead><tr><th>Column</th><th>References Table</th><th>References Column</th></tr></thead><tbody>";
        while ($fk = $fks->fetch_assoc()) {
            echo "<tr>
                    <td style='font-family: \"Courier New\", Courier, monospace;'>{$fk['COLUMN_NAME']}</td>
                    <td>{$fk['REFERENCED_TABLE_NAME']}</td>
                    <td>{$fk['REFERENCED_COLUMN_NAME']}</td>
                  </tr>";
        }
        echo "</tbody></table>";
    } else {
        echo "<p class='text-muted fst-italic'>No foreign key relations.</p>";
    }

    echo "</div></div></div>";
}

// --- Montar arrays para o diagrama ---
$tablesData = [];
$relationsData = [];

if (!empty($_POST['database'])) {
    $selectedDb = $_POST['database'];
    $conn->select_db($selectedDb);

    $allTables = $conn->query("SHOW TABLES");
    while ($row = $allTables->fetch_row()) {
        $tableName = $row[0];
        $desc = $conn->query("DESCRIBE `$tableName`");

        $columns = [];
        $pk = [];
        while ($col = $desc->fetch_assoc()) {
            $columns[] = [
                'name' => $col['Field'],
                'key'  => $col['Key']
            ];
            if ($col['Key'] === 'PRI') $pk[] = $col['Field'];
        }

        $tablesData[$tableName] = ['columns'=>$columns,'pk'=>$pk];

        $fkQuery = "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
                    WHERE TABLE_SCHEMA='$selectedDb' AND TABLE_NAME='$tableName' AND REFERENCED_TABLE_NAME IS NOT NULL";
        $fks = $conn->query($fkQuery);
        while ($fk = $fks->fetch_assoc()) {
            $relationsData[] = [
                'from_table'=>$tableName,
                'from_column'=>$fk['COLUMN_NAME'],
                'to_table'=>$fk['REFERENCED_TABLE_NAME'],
                'to_column'=>$fk['REFERENCED_COLUMN_NAME']
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <title>Database Table Viewer + ER Diagram</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
    <style>
        body { padding:30px; background-color:#f8f9fa; font-family:Arial,sans-serif; }
        code, pre, td { font-family:"Courier New", Courier, monospace; }
        .table-container { margin-bottom:40px; }
        #mynetwork { width:100%; height:800px; border:1px solid #ccc; margin-top:20px; }
        .btn-export { margin:5px; }
    </style>
</head>
<body>
<div class="container-fluid">
    <h2 class="mb-4">📋 Database Table Viewer + ER Diagram</h2>

    <div class="card mb-4">
        <div class="card-body">
            <form method="post" class="row g-3">
                <div class="col-md-4">
                    <label for="database" class="form-label">Select Database</label>
                    <select name="database" id="database" class="form-select" onchange="this.form.submit()">
                        <option value="">-- Choose a database --</option>
                        <?php
                        $dbs->data_seek(0);
                        while ($row = $dbs->fetch_assoc()) {
                            $dbName = $row['Database'];
                            $selected = (isset($_POST['database']) && $_POST['database'] === $dbName) ? 'selected' : '';
                            echo "<option value=\"$dbName\" $selected>$dbName</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="col-md-2 align-self-end">
                    <input type="submit" class="btn btn-primary w-100" value="Show Structure" />
                </div>
            </form>
        </div>
    </div>

    <?php
    if (!empty($_POST['database'])):
        $selectedDb = $_POST['database'];
        $conn->select_db($selectedDb);

        echo "<div class='mb-4'><h4>🗄️ Viewing Structure of Database: <code>$selectedDb</code></h4></div>";

        $allTables = $conn->query("SHOW TABLES");
        while ($row = $allTables->fetch_row()) {
            showStructure($conn, $selectedDb, $row[0]);
        }
    endif;

    $conn->close();
    ?>

    <!-- Botões de exportação -->
    <div>
        <button id="exportPNG" class="btn btn-success btn-export">Exportar PNG</button>
        <button id="exportSVG" class="btn btn-info btn-export">Exportar SVG</button>
        <button id="exportPDF" class="btn btn-danger btn-export">Exportar PDF</button>
    </div>
    <div id="mynetwork"></div>
</div>

<script src="https://unpkg.com/vis-network/standalone/umd/vis-network.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
var tables = <?php echo json_encode($tablesData); ?>;
var relations = <?php echo json_encode($relationsData); ?>;

var nodes = [], edges = [];

// Monta nós (tabelas + campos em texto puro)
for (let t in tables) {
  let info = tables[t];
  let label = t + "\n";
  info.columns.forEach(c=>{
    if(c.key === "PRI") {
      label += c.name+" (PK)\n";
    } else if(c.key === "MUL") {
      label += c.name+" (FK)\n";
    } else if(c.key === "UNI") {
      label += c.name+" (UQ)\n";
    } else {
      label += c.name+"\n";
    }
  });
  nodes.push({
    id: t,
    label: label.trim(),
    shape: 'box',
    font: { multi: false, face: 'monospace', size: 13 },
    margin: { top: 12, right: 12, bottom: 12, left: 12 },
    color: { background: '#ffffff', border: '#333333' }
  });
}

// Monta arestas (relações FK → PK)
relations.forEach(r=>{
  edges.push({
    from: r.from_table,
    to: r.to_table,
    label: r.from_column + " → " + r.to_column,
    arrows: { to: { enabled: true, scaleFactor: 1.1 } },
    font: { align: 'middle', color: '#0055aa', size: 11, background: '#ffffff' },
    color: { color: '#0077cc', highlight: '#ff0000' },
    smooth: { type: 'cubicBezier', forceDirection: 'none', roundness: 0.4 }
  });
});

// Renderiza o diagrama ER com algoritmo livre de sobreposição
var container = document.getElementById('mynetwork');
var data = { nodes: new vis.DataSet(nodes), edges: new vis.DataSet(edges) };

var options = {
  layout: {
    improvedLayout: true,
    hierarchical: false // Desativado para evitar sobreposição em conexões cruzadas/cíclicas
  },
  physics: {
    enabled: true,
    solver: 'forceAtlas2Based',
    forceAtlas2Based: {
      gravitationalConstant: -250, // Força repulsiva alta entre nós
      centralGravity: 0.005,      // Evita dispersão infinita
      springLength: 220,          // Tamanho das conexões
      springConstant: 0.08,
      damping: 0.4,
      avoidOverlap: 1             // Impede estritamente a sobreposição de caixas (evita colisão vertical/horizontal)
    },
    stabilization: {
      enabled: true,
      iterations: 1000,           // Pré-calcula as posições antes de exibir na tela
      updateInterval: 25
    }
  },
  edges: {
    smooth: true
  }
};

var network = new vis.Network(container, data, options);

// Estabiliza o gráfico e trava as posições para facilitar exportação
network.once('stabilizationIterationsDone', function () {
  network.setOptions({ physics: { enabled: false } });
});

// Exportar PNG
document.getElementById('exportPNG').onclick = function(){
  var canvas = container.getElementsByTagName('canvas')[0];
  var link = document.createElement('a');
  link.href = canvas.toDataURL("image/png");
  link.download = "diagram.png";
  link.click();
};

// Exportar SVG
document.getElementById('exportSVG').onclick = function(){
  var svgData = network.body.container.innerHTML;
  var blob = new Blob([svgData], {type:"image/svg+xml;charset=utf-8"});
  var link = document.createElement('a');
  link.href = URL.createObjectURL(blob);
  link.download = "diagram.svg";
  link.click();
};

// Exportar PDF
document.getElementById('exportPDF').onclick = function(){
  var canvas = container.getElementsByTagName('canvas')[0];
  var imgData = canvas.toDataURL("image/png");
  const { jsPDF } = window.jspdf;
  var pdf = new jsPDF('l','mm','a4');
  var width = pdf.internal.pageSize.getWidth();
  var height = pdf.internal.pageSize.getHeight();
  pdf.addImage(imgData,'PNG',10,10,width-20,height-20);
  pdf.save("diagram.pdf");
};
</script>
</body>
</html>
