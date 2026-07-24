<?php
/**
 * Leitor e Gerador de Estrutura de Diretório (leitor.php)
 * 
 * Este script escaneia recursivamente o diretório atual,
 * associa comentários/descrições a cada arquivo e pasta,
 * e gera um arquivo Markdown formatado em árvore (diretorio.md).
 * Possui uma interface web interativa de alta fidelidade para edição de comentários.
 */

// Desativar limites de execução para diretórios muito grandes
set_time_limit(0);
ini_set('memory_limit', '512M');

// Nome do arquivo de comentários e do markdown gerado
define('COMMENTS_FILE', __DIR__ . '/leitor_comments.json');
define('MARKDOWN_FILE', __DIR__ . '/diretorio.md');
define('PROJECT_INFO_FILE', __DIR__ . '/leitor_project_info.json');

// Campos aceitos no formulário de informações do projeto e seus
// respectivos comentários de fallback quando o campo está vazio.
define('PROJECT_INFO_FIELDS', [
    'linguagem'   => 'Linguagem de programação não informada no formulário.',
    'framework'   => 'Framework não informado no formulário.',
    'nome'        => 'Nome do projeto não informado no formulário.',
    'descricao'   => 'Descrição do projeto não foi preenchida no formulário.',
    'tecnologias' => 'Tecnologias do projeto não foram informadas no formulário.',
    'aspectos'    => 'Aspectos do projeto não foram informados no formulário.',
]);

// 1. Processamento de requisições AJAX (Salvar comentários)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'save') {
    header('Content-Type: application/json; charset=utf-8');
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'message' => 'JSON inválido enviado.']);
        exit;
    }
    
    // Salva os comentários no JSON
    $comments = isset($data['comments']) ? $data['comments'] : [];
    
    // Salva de forma formatada para leitura humana facilitada
    $jsonResult = file_put_contents(COMMENTS_FILE, json_encode($comments, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    if ($jsonResult === false) {
        echo json_encode(['success' => false, 'message' => 'Não foi possível escrever em leitor_comments.json. Verifique as permissões de gravação.']);
        exit;
    }

    // Salva as informações do formulário (Informações do Projeto)
    $projectInfo = isset($data['projectInfo']) && is_array($data['projectInfo']) ? $data['projectInfo'] : [];
    $infoResult = saveProjectInfoFile($projectInfo);

    if ($infoResult === false) {
        echo json_encode(['success' => false, 'message' => 'Comentários salvos, mas não foi possível escrever em leitor_project_info.json. Verifique as permissões de gravação.']);
        exit;
    }

    // Recarrega os dados normalizados para montar o markdown
    $projectInfo = loadProjectInfo();
    
    // Regenera o diretorio.md
    $rootName = basename(__DIR__);
    $tree = getDirectoryTree(__DIR__);
    $treeLines = [];
    buildTreeLines($tree, '', $treeLines);
    
    $markdownContent = buildProjectInfoMarkdown($projectInfo) . formatTreeMarkdown($rootName, $treeLines, $comments);
    $mdResult = file_put_contents(MARKDOWN_FILE, $markdownContent);
    
    if ($mdResult === false) {
        echo json_encode(['success' => false, 'message' => 'Comentários salvos no JSON, mas falhou ao gerar diretorio.md.']);
        exit;
    }
    
    echo json_encode(['success' => true, 'message' => 'Estrutura e diretorio.md atualizados com sucesso!']);
    exit;
}

// 1.b Funções auxiliares para o formulário de Informações do Projeto
function loadProjectInfo() {
    $defaults = array_fill_keys(array_keys(PROJECT_INFO_FIELDS), '');
    if (!file_exists(PROJECT_INFO_FILE)) {
        return $defaults;
    }
    $data = json_decode(file_get_contents(PROJECT_INFO_FILE), true);
    if (!is_array($data)) {
        return $defaults;
    }
    return array_merge($defaults, array_intersect_key($data, PROJECT_INFO_FIELDS));
}

function saveProjectInfoFile($data) {
    $clean = [];
    foreach (array_keys(PROJECT_INFO_FIELDS) as $key) {
        $clean[$key] = isset($data[$key]) ? trim((string) $data[$key]) : '';
    }
    return file_put_contents(PROJECT_INFO_FILE, json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Gera o bloco Markdown com as informações do formulário, usando um
// comentário de fallback nos campos que não foram preenchidos.
function buildProjectInfoMarkdown($info) {
    $get = function ($key) use ($info) {
        $valor = isset($info[$key]) ? trim((string) $info[$key]) : '';
        if ($valor !== '') {
            return $valor;
        }
        return '_' . PROJECT_INFO_FIELDS[$key] . '_';
    };

    $md  = "# Informações do Projeto\n\n";
    $md .= "- **Linguagem de Programação:** " . $get('linguagem') . "\n";
    $md .= "- **Framework:** " . $get('framework') . "\n";
    $md .= "- **Nome do Projeto:** " . $get('nome') . "\n\n";

    $md .= "## Descrição do Projeto\n\n" . $get('descricao') . "\n\n";
    $md .= "## Tecnologias do Projeto\n\n" . $get('tecnologias') . "\n\n";
    $md .= "## Aspectos do Projeto\n\n" . $get('aspectos') . "\n\n";
    $md .= "---\n\n";
    $md .= "# Estrutura de Diretórios\n\n";

    return $md;
}

// 2. Funções auxiliares para leitura de diretório
function getDirectoryTree($dir, $baseDir = '') {
    if (empty($baseDir)) {
        $baseDir = $dir;
    }
    $result = [];
    $items = scandir($dir);
    if ($items === false) return [];
    
    $dirs = [];
    $files = [];
    
    // Arquivos e pastas a serem ignorados
    $ignores = [
        '.git',
        'leitor.php',
        'leitor_comments.json',
        'diretorio.md',
        '.idea',
        '.vscode',
        'node_modules',
        'vendor',
        '__pycache__',
        '.DS_Store'
    ];
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        if (in_array($item, $ignores)) continue;
        
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $relPath = ltrim(str_replace($baseDir, '', $path), DIRECTORY_SEPARATOR);
        $relPath = str_replace(DIRECTORY_SEPARATOR, '/', $relPath); // Padroniza barra
        
        if (is_dir($path)) {
            $dirs[] = [
                'name' => $item . '/',
                'path' => $relPath,
                'is_dir' => true,
                'children' => getDirectoryTree($path, $baseDir)
            ];
        } else {
            $files[] = [
                'name' => $item,
                'path' => $relPath,
                'is_dir' => false
            ];
        }
    }
    
    // Ordenação alfabética
    usort($dirs, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
    usort($files, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
    
    return array_merge($dirs, $files);
}

function buildTreeLines($tree, $prefix = '', &$lines = []) {
    $count = count($tree);
    foreach ($tree as $index => $node) {
        $isLast = ($index === $count - 1);
        $pointer = $isLast ? '└── ' : '├── ';
        
        $lineText = $prefix . $pointer . $node['name'];
        $lines[] = [
            'text' => $lineText,
            'path' => $node['path'],
            'name' => $node['name'],
            'is_dir' => $node['is_dir']
        ];
        
        if ($node['is_dir'] && !empty($node['children'])) {
            $nextPrefix = $prefix . ($isLast ? '    ' : '│   ');
            buildTreeLines($node['children'], $nextPrefix, $lines);
        }
    }
    return $lines;
}

// 3. Extração automática de comentários de cabeçalho
function extractCommentFromFile($relPath) {
    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    if (!file_exists($fullPath) || is_dir($fullPath)) {
        return '';
    }
    
    $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
    if (!in_array($ext, ['php', 'js', 'sql', 'css', 'json', 'py', 'sh'])) {
        return '';
    }
    
    $fp = fopen($fullPath, 'r');
    if (!$fp) return '';
    
    $content = '';
    $lineCount = 0;
    while (($line = fgets($fp)) !== false && $lineCount < 15) {
        $content .= $line;
        $lineCount++;
    }
    fclose($fp);
    
    // Procura por PHP/JS docblock /** ... */
    if (preg_match('~/\*\*(.*?)\*/~s', $content, $m)) {
        $doc = $m[1];
        $docLines = explode("\n", $doc);
        foreach ($docLines as $dl) {
            $dl = trim($dl, " \t\r\n*");
            if (!empty($dl) && strpos($dl, '@') === false) {
                return $dl;
            }
        }
    }
    
    // Procura por comentários de linha única
    $lines = explode("\n", $content);
    foreach ($lines as $line) {
        $line = trim($line);
        if (preg_match('~^(?://|#)\s*(.+)~', $line, $m)) {
            $comment = trim($m[1]);
            if (!empty($comment) && strpos($comment, '<?php') === false && strpos($comment, '!') === false) {
                return $comment;
            }
        }
    }
    
    return '';
}

// 4. Formatação do texto do Markdown
function formatTreeMarkdown($rootName, $treeLines, $comments) {
    $output = $rootName . "/\n\n";
    
    // Encontrar o comprimento máximo da linha do texto da árvore
    $maxLen = 0;
    foreach ($treeLines as $line) {
        $len = mb_strlen($line['text']);
        if ($len > $maxLen) {
            $maxLen = $len;
        }
    }
    
    // Define a coluna de alinhamento dos comentários
    $padCol = max($maxLen + 4, 30);
    
    foreach ($treeLines as $line) {
        $text = $line['text'];
        $path = $line['path'];
        
        $comment = '';
        if (isset($comments[$path]) && trim($comments[$path]) !== '') {
            $comment = trim($comments[$path]);
        } else {
            $comment = extractCommentFromFile($path);
        }
        
        if ($comment !== '') {
            $padding = str_repeat(' ', max($padCol - mb_strlen($text), 1));
            $output .= $text . $padding . '# ' . $comment . "\n";
        } else {
            $output .= $text . "\n";
        }
    }
    
    return $output;
}

// Carregar dicionário atual
$comments = [];
if (file_exists(COMMENTS_FILE)) {
    $comments = json_decode(file_get_contents(COMMENTS_FILE), true);
    if (!is_array($comments)) {
        $comments = [];
    }
}

// Escanear e gerar árvore inicial
$rootName = basename(__DIR__);
$tree = getDirectoryTree(__DIR__);
$treeLines = [];
buildTreeLines($tree, '', $treeLines);

// Mapear comentários extraídos
$extractedComments = [];
foreach ($treeLines as $line) {
    $extractedComments[$line['path']] = extractCommentFromFile($line['path']);
}

// Carregar informações do formulário de projeto já salvas (se houver)
$projectInfo = loadProjectInfo();

// Se o diretorio.md não existe, cria ele de imediato
if (!file_exists(MARKDOWN_FILE)) {
    $initialMarkdown = buildProjectInfoMarkdown($projectInfo) . formatTreeMarkdown($rootName, $treeLines, $comments);
    file_put_contents(MARKDOWN_FILE, $initialMarkdown);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Leitor de Estrutura & Gerador de Markdown</title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fira+Code:wght@400;500&family=Outfit:wght@300;400;600;700&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        :root {
            --bg-color: #080710;
            --card-bg: rgba(17, 12, 28, 0.65);
            --border-color: rgba(255, 255, 255, 0.06);
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-glow: rgba(59, 130, 246, 0.35);
            --secondary: #8b5cf6;
            --accent: #10b981;
            --font-main: 'Inter', system-ui, -apple-system, sans-serif;
            --font-title: 'Outfit', sans-serif;
            --font-code: 'Fira Code', monospace;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            background: radial-gradient(circle at 50% 0%, #171233, #090514 80%);
            background-color: var(--bg-color);
            color: var(--text-main);
            font-family: var(--font-main);
            min-height: 100vh;
            padding: 2.5rem 1.5rem;
            line-height: 1.5;
            overflow-x: hidden;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        /* Header Style */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 1.5rem;
            position: relative;
        }

        .header-left h1 {
            font-family: var(--font-title);
            font-size: 2.2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            margin-bottom: 0.25rem;
            letter-spacing: -0.5px;
        }

        .header-left p {
            color: var(--text-muted);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .header-left p i {
            color: var(--primary);
        }

        .header-actions {
            display: flex;
            gap: 1rem;
        }

        /* Buttons styling */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.65rem 1.25rem;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            text-decoration: none;
            border: 1px solid transparent;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            color: white;
            box-shadow: 0 4px 15px var(--primary-glow);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 22px rgba(139, 92, 246, 0.5);
            filter: brightness(1.1);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border-color);
            color: var(--text-main);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.1);
            transform: translateY(-2px);
        }

        /* Main Workspace Grid */
        .workspace {
            display: grid;
            grid-template-columns: 1.2fr 0.8fr;
            gap: 2rem;
            align-items: start;
        }

        @media (max-width: 1024px) {
            .workspace {
                grid-template-columns: 1fr;
            }
        }

        .card {
            background: var(--card-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.3);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-header h2 {
            font-family: var(--font-title);
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .card-header h2 i {
            color: var(--primary);
        }

        .card-body {
            padding: 1.5rem;
            max-height: 70vh;
            overflow-y: auto;
        }

        /* Custom Scrollbars */
        .card-body::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .card-body::-webkit-scrollbar-track {
            background: rgba(0,0,0,0.1);
        }
        .card-body::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.15);
            border-radius: 4px;
        }
        .card-body::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        /* Directory Tree Table Style */
        .tree-table {
            width: 100%;
            border-collapse: collapse;
        }

        .tree-row {
            border-bottom: 1px solid rgba(255, 255, 255, 0.02);
            transition: background-color 0.2s ease;
        }

        .tree-row:hover {
            background-color: rgba(255, 255, 255, 0.015);
        }

        .tree-cell-graphic {
            padding: 0.5rem 0.25rem;
            vertical-align: middle;
            font-family: var(--font-code);
            font-size: 0.85rem;
            color: #4b5563;
            white-space: pre;
            width: 1%;
        }

        .tree-cell-name {
            padding: 0.5rem 0.75rem;
            vertical-align: middle;
            font-family: var(--font-main);
            font-size: 0.9rem;
            color: var(--text-main);
            white-space: nowrap;
            width: 30%;
        }

        .tree-cell-name i {
            margin-right: 0.5rem;
            width: 16px;
            text-align: center;
        }

        .folder-icon {
            color: #fbbf24;
        }

        .file-icon {
            color: var(--text-muted);
        }

        .tree-cell-comment {
            padding: 0.5rem 0.75rem;
            vertical-align: middle;
            width: 60%;
        }

        .comment-input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
        }

        .comment-input {
            width: 100%;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
            color: #cbd5e1;
            font-family: var(--font-main);
            transition: all 0.25s ease;
        }

        .comment-input:focus {
            background: rgba(59, 130, 246, 0.05);
            border-color: var(--primary);
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.15);
            outline: none;
            color: #ffffff;
        }

        .comment-input::placeholder {
            color: rgba(255, 255, 255, 0.25);
            font-style: italic;
        }

        .comment-input.has-fallback {
            border-color: rgba(16, 185, 129, 0.2);
        }
        
        .comment-input.has-fallback:focus {
            border-color: var(--accent);
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.15);
        }

        /* Live Preview Styles */
        .preview-container {
            position: relative;
            background: #0d0a1b;
            border-radius: 12px;
            border: 1px solid rgba(255, 255, 255, 0.04);
            padding: 1.25rem;
            margin: 0;
            overflow: auto;
            font-family: var(--font-code);
            font-size: 0.85rem;
            color: #34d399;
            white-space: pre;
            line-height: 1.45;
            max-height: 70vh;
        }

        /* Toast Alert System */
        #toast-container {
            position: fixed;
            top: 2rem;
            right: 2rem;
            z-index: 9999;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .toast {
            background: rgba(15, 23, 42, 0.9);
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            padding: 1rem 1.5rem;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transform: translateX(120%);
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
            font-size: 0.9rem;
            min-width: 300px;
            backdrop-filter: blur(8px);
        }

        .toast.show {
            transform: translateX(0);
        }

        .toast.success {
            border-left: 4px solid var(--accent);
        }

        .toast.success i {
            color: var(--accent);
        }

        .toast.error {
            border-left: 4px solid #ef4444;
        }

        .toast.error i {
            color: #ef4444;
        }

        /* Info Summary Grid */
        .summary-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.5rem;
            margin-bottom: 0.5rem;
        }

        @media (max-width: 768px) {
            .summary-stats {
                grid-template-columns: 1fr;
            }
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            padding: 1rem;
            border-radius: 12px;
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            background: rgba(59, 130, 246, 0.1);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }

        .stat-card:nth-child(2) .stat-icon {
            background: rgba(139, 92, 246, 0.1);
            color: var(--secondary);
        }

        .stat-card:nth-child(3) .stat-icon {
            background: rgba(16, 185, 129, 0.1);
            color: var(--accent);
        }

        .stat-info {
            display: flex;
            flex-direction: column;
        }

        .stat-val {
            font-family: var(--font-title);
            font-size: 1.3rem;
            font-weight: 700;
        }

        .stat-lbl {
            color: var(--text-muted);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Project Info Form */
        .project-form {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 1.25rem;
        }

        @media (max-width: 900px) {
            .project-form {
                grid-template-columns: 1fr;
            }
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 0.4rem;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            font-size: 0.85rem;
            color: var(--text-muted);
            font-weight: 600;
        }

        .form-control {
            width: 100%;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 8px;
            padding: 0.55rem 0.8rem;
            font-size: 0.9rem;
            color: var(--text-main);
            font-family: var(--font-main);
            transition: all 0.25s ease;
        }

        .form-control:focus {
            background: rgba(59, 130, 246, 0.05);
            border-color: var(--primary);
            box-shadow: 0 0 10px rgba(59, 130, 246, 0.15);
            outline: none;
        }

        select.form-control {
            appearance: none;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%2394a3b8' viewBox='0 0 16 16'%3E%3Cpath d='M8 11L3 6h10z'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 0.7rem center;
        }

        select.form-control option {
            background: #171233;
            color: var(--text-main);
        }

        textarea.form-control {
            resize: vertical;
            min-height: 90px;
            font-family: var(--font-main);
        }
    </style>
</head>
<body>
    <div class="gtranslate_wrapper"></div>
    <div id="toast-container"></div>

    <div class="container">
        <header>
            <div class="header-left">
                <h1>Leitor de Diretório</h1>
                <p><i class="fa-solid fa-folder-open"></i> <?php echo htmlspecialchars(__DIR__); ?></p>
            </div>
            <div class="header-actions">
                <button class="btn btn-secondary" onclick="exportMarkdown()"><i class="fa-solid fa-download"></i> Baixar .md</button>
                <button class="btn btn-primary" onclick="saveComments()"><i class="fa-solid fa-floppy-disk"></i> Salvar e Gerar .md</button>
            </div>
        </header>

        <!-- Informações do Projeto: complementam o diretorio.md gerado -->
        <div class="card">
            <div class="card-header">
                <h2><i class="fa-solid fa-clipboard-list"></i> Informações do Projeto</h2>
                <span style="font-size: 0.8rem; color: var(--text-muted);"><i class="fa-solid fa-circle-info"></i> Complementam o diretorio.md gerado</span>
            </div>
            <div class="card-body">
                <div class="project-form">

                    <div class="form-group">
                        <label for="pi-linguagem">Linguagem de Programação</label>
                        <select class="form-control" id="pi-linguagem" onchange="updateFrameworkOptions(); onProjectFormInput();">
                            <option value="">Selecione</option>
                            <option>PHP</option>
                            <option>JavaScript</option>
                            <option>TypeScript</option>
                            <option>Python</option>
                            <option>Java</option>
                            <option>C#</option>
                            <option>Dart</option>
                            <option>Go</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="pi-framework">Framework</label>
                        <select class="form-control" id="pi-framework" onchange="onProjectFormInput();">
                            <option value="">Selecione a linguagem primeiro</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="pi-nome">Nome do Projeto</label>
                        <input type="text" class="form-control" id="pi-nome" oninput="onProjectFormInput();">
                    </div>

                    <div class="form-group full-width">
                        <label for="pi-descricao">Descrição do Projeto</label>
                        <textarea class="form-control" id="pi-descricao" oninput="onProjectFormInput();"></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="pi-tecnologias">Tecnologias do Projeto</label>
                        <textarea class="form-control" id="pi-tecnologias" oninput="onProjectFormInput();"></textarea>
                    </div>

                    <div class="form-group full-width">
                        <label for="pi-aspectos">Aspectos do Projeto</label>
                        <textarea class="form-control" id="pi-aspectos" oninput="onProjectFormInput();"></textarea>
                    </div>

                </div>
            </div>
        </div>

        <!-- Stats Panel -->
        <div class="summary-stats">
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-folder"></i></div>
                <div class="stat-info">
                    <span class="stat-val"><?php 
                        $foldersCount = count(array_filter($treeLines, function($l) { return $l['is_dir']; }));
                        echo $foldersCount; 
                    ?></span>
                    <span class="stat-lbl">Diretórios</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-file"></i></div>
                <div class="stat-info">
                    <span class="stat-val"><?php 
                        $filesCount = count(array_filter($treeLines, function($l) { return !$l['is_dir']; }));
                        echo $filesCount; 
                    ?></span>
                    <span class="stat-lbl">Arquivos</span>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon"><i class="fa-solid fa-comment-dots"></i></div>
                <div class="stat-info">
                    <span class="stat-val" id="comment-count">0</span>
                    <span class="stat-lbl">Mapeados</span>
                </div>
            </div>
        </div>

        <div class="workspace">
            <!-- Left Side: Interactive Tree List -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-solid fa-network-wired"></i> Estrutura Interativa</h2>
                    <span style="font-size: 0.8rem; color: var(--text-muted);"><i class="fa-solid fa-circle-info"></i> Edite os comentários em tempo real</span>
                </div>
                <div class="card-body">
                    <table class="tree-table">
                        <tbody>
                            <?php foreach ($treeLines as $index => $line): ?>
                                <?php 
                                    // Determinar se o item tem comentário do usuário ou fallback
                                    $userComment = isset($comments[$line['path']]) ? $comments[$line['path']] : '';
                                    $fallback = isset($extractedComments[$line['path']]) ? $extractedComments[$line['path']] : '';
                                    
                                    // Class styling para input que possui fallback
                                    $inputClass = 'comment-input';
                                    $placeholder = 'Sem descrição';
                                    if ($userComment === '' && $fallback !== '') {
                                        $inputClass .= ' has-fallback';
                                        $placeholder = $fallback;
                                    }
                                    
                                    // Separar o texto gráfico clássico do nome do item
                                    $parts = explode($line['name'], $line['text']);
                                    $graphic = $parts[0];
                                ?>
                                <tr class="tree-row">
                                    <td class="tree-cell-graphic"><?php echo htmlspecialchars($graphic); ?></td>
                                    <td class="tree-cell-name">
                                        <?php if ($line['is_dir']): ?>
                                            <i class="fa-solid fa-folder-open folder-icon"></i><strong><?php echo htmlspecialchars($line['name']); ?></strong>
                                        <?php else: ?>
                                            <i class="fa-solid fa-file file-icon"></i><?php echo htmlspecialchars($line['name']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="tree-cell-comment">
                                        <div class="comment-input-wrapper">
                                            <input 
                                                type="text" 
                                                class="<?php echo $inputClass; ?>" 
                                                data-path="<?php echo htmlspecialchars($line['path']); ?>" 
                                                data-graphic="<?php echo htmlspecialchars($graphic); ?>" 
                                                data-name="<?php echo htmlspecialchars($line['name']); ?>" 
                                                data-fallback="<?php echo htmlspecialchars($fallback); ?>" 
                                                placeholder="<?php echo htmlspecialchars($placeholder); ?>" 
                                                value="<?php echo htmlspecialchars($userComment); ?>"
                                                oninput="handleInput(this)"
                                            >
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Side: Real-time Markdown Preview -->
            <div class="card">
                <div class="card-header">
                    <h2><i class="fa-brands fa-markdown"></i> Pré-visualização do diretorio.md</h2>
                    <span style="font-size: 0.8rem; color: var(--text-muted);"><i class="fa-solid fa-circle-check"></i> Alinhado dinamicamente</span>
                </div>
                <div class="card-body" style="padding: 1rem; background: #0b0816;">
                    <pre class="preview-container" id="markdown-preview"></pre>
                </div>
            </div>
        </div>
    </div>
            
    <script>window.gtranslateSettings = {"default_language":"pt","languages":["pt","es","en"],"wrapper_selector":".gtranslate_wrapper","switcher_horizontal_position":"right","alt_flags":{"en":"usa","pt":"brazil","es":"mexico"}}</script>
    <script src="https://cdn.gtranslate.net/widgets/latest/float.js" defer></script>

    <script>
        // Dados da árvore passados do PHP para o JavaScript
        const treeLines = <?php echo json_encode($treeLines); ?>;
        const rootName = <?php echo json_encode($rootName); ?>;

        // Informações do projeto já salvas anteriormente (para pré-preencher o formulário)
        const savedProjectInfo = <?php echo json_encode($projectInfo); ?>;

        // Comentários de fallback exibidos no markdown quando um campo do formulário está vazio
        const projectInfoFallbacks = <?php echo json_encode(PROJECT_INFO_FIELDS); ?>;

        // Catálogo de frameworks por linguagem (mesma lista usada no cadastro)
        const frameworksCatalog = {
            PHP: ["Laravel", "Symfony"],
            JavaScript: ["React", "Angular", "Vue.js", "Express.js", "React Native"],
            TypeScript: ["Angular", "NestJS", "React", "Vue.js", "Express.js", "React Native"],
            Python: ["Django", "Flask"],
            Java: ["Spring Boot"],
            "C#": ["ASP.NET Core"],
            Dart: ["Flutter"],
            Go: ["Gin"]
        };

        // Popula o select de Framework de acordo com a Linguagem escolhida
        function updateFrameworkOptions(preSelectedFramework) {
            const linguagemSelect = document.getElementById('pi-linguagem');
            const frameworkSelect = document.getElementById('pi-framework');
            const linguagem = linguagemSelect.value;

            frameworkSelect.innerHTML = '';
            const opcaoPadrao = document.createElement('option');
            opcaoPadrao.value = '';
            opcaoPadrao.text = linguagem ? 'Selecione' : 'Selecione a linguagem primeiro';
            frameworkSelect.appendChild(opcaoPadrao);

            (frameworksCatalog[linguagem] || []).forEach(item => {
                const opcao = document.createElement('option');
                opcao.value = item;
                opcao.text = item;
                frameworkSelect.appendChild(opcao);
            });

            if (preSelectedFramework && frameworksCatalog[linguagem] && frameworksCatalog[linguagem].includes(preSelectedFramework)) {
                frameworkSelect.value = preSelectedFramework;
            }
        }

        // Lê os valores atuais do formulário de Informações do Projeto
        function getProjectFormValues() {
            return {
                linguagem: document.getElementById('pi-linguagem').value.trim(),
                framework: document.getElementById('pi-framework').value.trim(),
                nome: document.getElementById('pi-nome').value.trim(),
                descricao: document.getElementById('pi-descricao').value.trim(),
                tecnologias: document.getElementById('pi-tecnologias').value.trim(),
                aspectos: document.getElementById('pi-aspectos').value.trim()
            };
        }

        // Monta o bloco Markdown de "Informações do Projeto" a partir do formulário
        function buildProjectInfoMarkdownJS() {
            const info = getProjectFormValues();
            const campo = (chave) => info[chave] !== '' ? info[chave] : `_${projectInfoFallbacks[chave]}_`;

            let md = "# Informações do Projeto\n\n";
            md += `- **Linguagem de Programação:** ${campo('linguagem')}\n`;
            md += `- **Framework:** ${campo('framework')}\n`;
            md += `- **Nome do Projeto:** ${campo('nome')}\n\n`;
            md += `## Descrição do Projeto\n\n${campo('descricao')}\n\n`;
            md += `## Tecnologias do Projeto\n\n${campo('tecnologias')}\n\n`;
            md += `## Aspectos do Projeto\n\n${campo('aspectos')}\n\n`;
            md += "---\n\n";
            md += "# Estrutura de Diretórios\n\n";

            return md;
        }

        // Disparado a cada alteração no formulário de Informações do Projeto
        function onProjectFormInput() {
            updateMarkdownPreview();
        }

        // Pré-preenche o formulário com dados salvos anteriormente
        function fillProjectFormFromSaved() {
            document.getElementById('pi-linguagem').value = savedProjectInfo.linguagem || '';
            updateFrameworkOptions(savedProjectInfo.framework || '');
            document.getElementById('pi-nome').value = savedProjectInfo.nome || '';
            document.getElementById('pi-descricao').value = savedProjectInfo.descricao || '';
            document.getElementById('pi-tecnologias').value = savedProjectInfo.tecnologias || '';
            document.getElementById('pi-aspectos').value = savedProjectInfo.aspectos || '';
        }

        // Atualizar o preview do markdown quando a página carrega
        window.addEventListener('DOMContentLoaded', () => {
            fillProjectFormFromSaved();
            updateMarkdownPreview();
            countMappedComments();
        });

        // Manipular entrada de texto nos inputs
        function handleInput(input) {
            const fallback = input.getAttribute('data-fallback');
            // Alterar o visual se tiver ou não fallback
            if (input.value.trim() === '' && fallback !== '') {
                input.classList.add('has-fallback');
                input.placeholder = fallback;
            } else {
                input.classList.remove('has-fallback');
                input.placeholder = 'Sem descrição';
            }
            updateMarkdownPreview();
            countMappedComments();
        }

        // Calcula a quantidade de comentários personalizados ou fallbacks ativos
        function countMappedComments() {
            let mapped = 0;
            document.querySelectorAll('.comment-input').forEach(input => {
                if (input.value.trim() !== '' || input.getAttribute('data-fallback') !== '') {
                    mapped++;
                }
            });
            document.getElementById('comment-count').textContent = mapped;
        }

        // Função que renderiza em tempo real a árvore markdown alinhada
        function updateMarkdownPreview() {
            let output = buildProjectInfoMarkdownJS();
            output += rootName + "/\n\n";
            
            // 1. Encontrar o comprimento máximo das linhas gráficas + nome
            let maxLen = 0;
            const linesData = [];

            document.querySelectorAll('.comment-input').forEach(input => {
                const graphic = input.getAttribute('data-graphic');
                const name = input.getAttribute('data-name');
                const path = input.getAttribute('data-path');
                const fullText = graphic + name;
                
                if (fullText.length > maxLen) {
                    maxLen = fullText.length;
                }

                // Determinar o comentário ativo
                let comment = input.value.trim();
                if (comment === '') {
                    comment = input.getAttribute('data-fallback') || '';
                }

                linesData.push({
                    text: fullText,
                    comment: comment
                });
            });

            // 2. Definir coluna de alinhamento
            const padCol = Math.max(maxLen + 4, 30);

            // 3. Montar a string do markdown
            linesData.forEach(line => {
                if (line.comment !== '') {
                    const padding = " ".repeat(Math.max(padCol - line.text.length, 1));
                    output += line.text + padding + '# ' + line.comment + "\n";
                } else {
                    output += line.text + "\n";
                }
            });

            document.getElementById('markdown-preview').textContent = output;
        }

        // Salvar comentários via AJAX no leitor_comments.json e diretorio.md
        async function saveComments() {
            const commentsObj = {};
            document.querySelectorAll('.comment-input').forEach(input => {
                const path = input.getAttribute('data-path');
                const val = input.value.trim();
                if (val !== '') {
                    commentsObj[path] = val;
                }
            });

            try {
                const response = await fetch('leitor.php?action=save', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ comments: commentsObj, projectInfo: getProjectFormValues() })
                });

                const result = await response.json();
                if (result.success) {
                    showToast('sucesso', result.message || 'Estrutura gerada com sucesso.');
                } else {
                    showToast('erro', result.message || 'Ocorreu um erro.');
                }
            } catch (error) {
                console.error('Error saving comments:', error);
                showToast('erro', 'Falha na comunicação com o servidor.');
            }
        }

        // Permitir baixar o arquivo markdown gerado diretamente do navegador
        function exportMarkdown() {
            const markdownText = document.getElementById('markdown-preview').textContent;
            const blob = new Blob([markdownText], { type: 'text/markdown;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.setAttribute('download', 'diretorio.md');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            showToast('sucesso', 'Download do arquivo diretorio.md iniciado.');
        }

        // Sistema simples de Notificações Toast
        function showToast(type, message) {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type === 'sucesso' ? 'success' : 'error'}`;
            
            const icon = type === 'sucesso' ? 'fa-circle-check' : 'fa-circle-exclamation';
            toast.innerHTML = `
                <i class="fa-solid ${icon}"></i>
                <span>${message}</span>
            `;
            
            container.appendChild(toast);
            
            // Animação de entrada
            setTimeout(() => {
                toast.classList.add('show');
            }, 50);

            // Remoção automática após 4 segundos
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toast.remove();
                }, 350);
            }, 4000);
        }
    </script>
</body>
</html>
