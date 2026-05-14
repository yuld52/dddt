<?php
/**
 * API para alternar entre painéis (Infoprodutor e Área de Membros)
 */

require_once __DIR__ . '/../config/config.php';

// Carregar funções SaaS se necessário
if (file_exists(__DIR__ . '/../saas/includes/saas_functions.php')) {
    require_once __DIR__ . '/../saas/includes/saas_functions.php';
}

header('Content-Type: application/json');

// Verifica se está logado
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Verifica se é admin (não pode alternar)
if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] === 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Administradores não podem alternar painéis']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$view_mode = $input['view_mode'] ?? $_POST['view_mode'] ?? null;

if (!in_array($view_mode, ['infoprodutor', 'member'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Modo de visualização inválido']);
    exit;
}

$usuario_id = $_SESSION['id'] ?? 0;
$usuario_tipo = $_SESSION['tipo'] ?? '';
$usuario_email = $_SESSION['usuario'] ?? '';

// Validação de acesso
$has_access = false;

if ($view_mode === 'infoprodutor') {
    // SEGURANÇA: Apenas infoprodutores podem acessar o painel de infoprodutor
    // Não permite elevação de privilégios de 'usuario' para 'infoprodutor'
    $has_access = ($usuario_tipo === 'infoprodutor');
    
    if (!$has_access) {
        log_security_event('unauthorized_panel_switch_attempt', [
            'user_id' => $usuario_id,
            'user_type' => $usuario_tipo,
            'attempted_view_mode' => $view_mode,
            'ip' => get_client_ip()
        ]);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas infoprodutores podem acessar este painel.']);
        exit;
    }
} elseif ($view_mode === 'member') {
    // Pode acessar área de membros se:
    // 1. Tem registros em alunos_acessos (comprou cursos), OU
    // 2. É infoprodutor e criou produtos do tipo area_membros
    try {
        // Verifica se tem cursos comprados
        $stmt_acessos = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM alunos_acessos aa
            JOIN produtos p ON aa.produto_id = p.id
            WHERE aa.aluno_email = ? AND p.tipo_entrega = 'area_membros'
        ");
        $stmt_acessos->execute([$usuario_email]);
        $acessos = $stmt_acessos->fetch(PDO::FETCH_ASSOC);
        
        if ($acessos && $acessos['total'] > 0) {
            $has_access = true;
        } else {
            // Verifica se é infoprodutor e criou produtos area_membros
            if ($usuario_tipo === 'infoprodutor') {
                $stmt_produtos = $pdo->prepare("
                    SELECT COUNT(*) as total 
                    FROM produtos 
                    WHERE usuario_id = ? AND tipo_entrega = 'area_membros'
                ");
                $stmt_produtos->execute([$usuario_id]);
                $produtos = $stmt_produtos->fetch(PDO::FETCH_ASSOC);
                
                if ($produtos && $produtos['total'] > 0) {
                    $has_access = true;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Erro ao verificar acesso à área de membros: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao verificar acesso']);
        exit;
    }
}

if (!$has_access) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Você não tem acesso a este painel']);
    exit;
}

// Atualiza o modo de visualização na sessão
$_SESSION['current_view_mode'] = $view_mode;

// Define URL de redirecionamento
$redirect_url = ($view_mode === 'infoprodutor') ? '/' : '/member_area_dashboard';

echo json_encode([
    'success' => true,
    'view_mode' => $view_mode,
    'redirect_url' => $redirect_url
]);

