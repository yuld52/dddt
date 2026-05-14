<?php
require_once __DIR__ . '/../../config/config.php';

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../../helpers/security_helper.php';

// Proteção da página: usuários logados podem acessar (exceto admin).
// Administradores são redirecionados para o painel de admin.
// Usuários não logados são redirecionados para a tela de login da área de membros.
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: /member_login");
    exit;
}

// Se for um administrador logado, redireciona para o painel de admin, pois não deve acessar a área de membros.
if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] === 'admin') {
    header("location: /admin");
    exit;
}

$cliente_email = $_SESSION['usuario']; 
$cliente_nome = $_SESSION['nome'] ?? $cliente_email;
$usuario_id = $_SESSION['id'] ?? 0;
$usuario_tipo = $_SESSION['tipo'] ?? ''; 

$mensagem_erro = '';
$curso = null;
$modulos_com_aulas = [];
$total_aulas_desbloqueadas = 0; // Total de aulas DESBLOQUEADAS para cálculo de progresso
$aulas_concluidas_desbloqueadas = 0; // Aulas concluídas que estão DESBLOQUEADAS
$progresso_percentual = 0;
$upload_dir = 'uploads/';
$aula_files_dir_public = 'uploads/aula_files/'; // Caminho público para download

// Valida o ID do produto
if (!isset($_GET['produto_id']) || !is_numeric($_GET['produto_id'])) {
    $mensagem_erro = "ID do curso inválido. Por favor, volte ao painel.";
} else {
    $produto_id = (int)$_GET['produto_id'];

    try {
        // 1. Verifica se o usuário tem acesso a este produto/curso
        // Acesso pode ser via alunos_acessos (comprou) OU se é infoprodutor e criou o produto
        $acesso_info = null;
        $data_concessao = null;
        
        // Primeiro verifica se comprou (está em alunos_acessos)
        $stmt_acesso = $pdo->prepare("
            SELECT data_concessao FROM alunos_acessos 
            WHERE aluno_email = ? AND produto_id = ?
        ");
        $stmt_acesso->execute([$cliente_email, $produto_id]);
        $acesso_info = $stmt_acesso->fetch(PDO::FETCH_ASSOC);
        
        // Se não encontrou em alunos_acessos, verifica se é infoprodutor e criou o produto
        if (!$acesso_info && $usuario_tipo === 'infoprodutor') {
            $stmt_produto = $pdo->prepare("
                SELECT data_criacao, usuario_id FROM produtos 
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt_produto->execute([$produto_id, $usuario_id]);
            $produto_info = $stmt_produto->fetch(PDO::FETCH_ASSOC);
            
            if ($produto_info) {
                // Infoprodutor criou o produto, usar data_criacao como data_concessao
                $acesso_info = ['data_concessao' => $produto_info['data_criacao']];
            }
        }

        if (!$acesso_info) {
            $mensagem_erro = "Você não tem acesso a este curso. Se acredita que é um erro, entre em contato com o suporte.";
        } else {
            $data_concessao = new DateTime($acesso_info['data_concessao']);
            $current_date = new DateTime();

            // 2. Busca os detalhes do curso e o produto associado
            $stmt_curso = $pdo->prepare("
                SELECT c.*, p.nome as produto_nome, p.descricao as produto_descricao, p.foto as produto_foto 
                FROM cursos c
                JOIN produtos p ON c.produto_id = p.id
                WHERE p.id = ? AND p.tipo_entrega = 'area_membros'
            ");
            $stmt_curso->execute([$produto_id]);
            $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);

            if (!$curso) {
                $mensagem_erro = "Curso não encontrado ou não está configurado como 'Área de Membros'.";
            } else {
                // 3. Busca os módulos do curso (incluindo campos de módulo pago)
                $stmt_modulos = $pdo->prepare("SELECT id, curso_id, titulo, imagem_capa_url, ordem, release_days, is_paid_module, linked_product_id FROM modulos WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
                $stmt_modulos->execute([$curso['id']]);
                $modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

                // 4. Para cada módulo, busca as aulas e o progresso do aluno
                foreach ($modulos as $modulo) {
                    // NEW: Verificar acesso a módulos pagos
                    $modulo['is_paid_module'] = (bool)($modulo['is_paid_module'] ?? 0);
                    $modulo['linked_product_id'] = $modulo['linked_product_id'] ?? null;
                    $modulo['produto_atrelado'] = null;
                    $has_access_to_linked_product = false;
                    
                    if ($modulo['is_paid_module'] && $modulo['linked_product_id']) {
                        // Buscar informações do produto atrelado (incluindo checkout_hash)
                        $stmt_prod = $pdo->prepare("SELECT id, nome, preco, checkout_hash FROM produtos WHERE id = ?");
                        $stmt_prod->execute([$modulo['linked_product_id']]);
                        $produto_data = $stmt_prod->fetch(PDO::FETCH_ASSOC);
                        
                        // Se não encontrou checkout_hash, tentar buscar de produto_ofertas
                        if ($produto_data && empty($produto_data['checkout_hash'])) {
                            $stmt_oferta = $pdo->prepare("SELECT checkout_hash FROM produto_ofertas WHERE produto_id = ? AND is_active = 1 LIMIT 1");
                            $stmt_oferta->execute([$modulo['linked_product_id']]);
                            $oferta = $stmt_oferta->fetch(PDO::FETCH_ASSOC);
                            if ($oferta) {
                                $produto_data['checkout_hash'] = $oferta['checkout_hash'];
                            }
                        }
                        
                        $modulo['produto_atrelado'] = $produto_data;
                        
                        // Verificar se o aluno tem acesso ao produto atrelado
                        if ($modulo['produto_atrelado']) {
                            $stmt_check_access = $pdo->prepare("SELECT COUNT(*) FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
                            $stmt_check_access->execute([$cliente_email, $modulo['linked_product_id']]);
                            $has_access_to_linked_product = $stmt_check_access->fetchColumn() > 0;
                        }
                    }
                    
                    // Calcula a data de liberação do módulo (só se não for módulo pago sem acesso)
                    if ($modulo['is_paid_module'] && !$has_access_to_linked_product) {
                        // Módulo pago sem acesso - sempre bloqueado
                        $modulo['is_locked'] = true;
                        $modulo['available_at'] = null;
                        $modulo['lock_reason'] = 'paid_module_no_access';
                    } else {
                        // Módulo normal ou módulo pago com acesso - verificar release_days
                        $module_release_date = clone $data_concessao;
                        $module_release_date->modify("+{$modulo['release_days']} days");
                        $modulo['is_locked'] = ($current_date < $module_release_date);
                        $modulo['available_at'] = $module_release_date->format('d/m/Y H:i');
                        $modulo['lock_reason'] = $modulo['is_locked'] ? 'release_days' : null;
                    }

                    // MODIFICADO: Incluir 'tipo_conteudo' na consulta das aulas
                    $stmt_aulas = $pdo->prepare("SELECT id, modulo_id, titulo, url_video, descricao, ordem, release_days, tipo_conteudo FROM aulas WHERE modulo_id = ? ORDER BY ordem ASC, id ASC");
                    $stmt_aulas->execute([$modulo['id']]);
                    $aulas = $stmt_aulas->fetchAll(PDO::FETCH_ASSOC);
                    
                    $aulas_com_progresso = [];
                    foreach ($aulas as $aula) {
                        // Calcula a data de liberação da aula
                        $lesson_release_date = clone $data_concessao;
                        $lesson_release_date->modify("+{$aula['release_days']} days");
                        $aula['is_locked'] = ($current_date < $lesson_release_date);
                        $aula['available_at'] = $lesson_release_date->format('d/m/Y H:i');

                        // Soma apenas as aulas que estão DESBLOQUEADAS para o cálculo do progresso geral
                        if (!$aula['is_locked']) {
                            $total_aulas_desbloqueadas++;
                        }

                        $stmt_progresso = $pdo->prepare("SELECT COUNT(*) FROM aluno_progresso WHERE aluno_email = ? AND aula_id = ?");
                        $stmt_progresso->execute([$cliente_email, $aula['id']]);
                        $aula['concluida'] = $stmt_progresso->fetchColumn() > 0;
                        if ($aula['concluida'] && !$aula['is_locked']) { // Só conta como concluída se estiver desbloqueada
                            $aulas_concluidas_desbloqueadas++;
                        }

                        // NOVO: Busca arquivos da aula
                        $stmt_files = $pdo->prepare("SELECT id, nome_original, nome_salvo FROM aula_arquivos WHERE aula_id = ? ORDER BY ordem ASC, id ASC");
                        $stmt_files->execute([$aula['id']]);
                        $aula['files'] = $stmt_files->fetchAll(PDO::FETCH_ASSOC);

                        $aulas_com_progresso[] = $aula;
                    }

                    $modulos_com_aulas[] = [
                        'modulo' => $modulo,
                        'aulas' => $aulas_com_progresso
                    ];
                }
                if ($total_aulas_desbloqueadas > 0) {
                    $progresso_percentual = round(($aulas_concluidas_desbloqueadas / $total_aulas_desbloqueadas) * 100);
                }
            }
        }
    } catch (PDOException $e) {
        $mensagem_erro = "Erro de banco de dados: " . htmlspecialchars($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($curso['titulo'] ?? 'Curso'); ?> - Área de Membros</title>
    <?php
    // Carregar configurações do sistema (inclui cor primária)
    include __DIR__ . '/../../config/load_settings.php';
    
    // Adiciona favicon se configurado
    $favicon_url_raw = getSystemSetting('favicon_url', '');
    if (!empty($favicon_url_raw)) {
        $favicon_url = ltrim($favicon_url_raw, '/');
        if (strpos($favicon_url, 'http') !== 0) {
            if (strpos($favicon_url, 'uploads/') === 0) {
                $favicon_url = '/' . $favicon_url;
            } else {
                $favicon_url = '/' . $favicon_url;
            }
        }
        $favicon_ext = strtolower(pathinfo($favicon_url, PATHINFO_EXTENSION));
        $favicon_type = 'image/x-icon';
        if ($favicon_ext === 'png') {
            $favicon_type = 'image/png';
        } elseif ($favicon_ext === 'svg') {
            $favicon_type = 'image/svg+xml';
        }
        echo '<link rel="icon" type="' . htmlspecialchars($favicon_type) . '" href="' . htmlspecialchars($favicon_url) . '">' . "\n";
    }
    ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .prose { /* TailwindCSS Typography plugin classes can be added here or in global CSS */
            --tw-prose-body: #d1d5db; 
            --tw-prose-headings: #f9fafb; 
            --tw-prose-links: #fb923c; 
        }
        .module-card.active { border-color: var(--accent-primary) !important; box-shadow: 0 0 15px var(--accent-primary); transform: scale(1.05); }
        .module-card:hover { border-color: var(--accent-primary); }
        .lesson-item.active { background-color: #7c2d12; color: #ffedd5; font-weight: 600; }
        .lesson-item.active .lucide-play-circle { color: #fdba74; }
        .aspect-video { aspect-ratio: 16 / 9; }
        .header-bg {
            background: linear-gradient(to right, var(--accent-primary), var(--accent-primary-hover));
        }
        .lesson-item.locked { 
            cursor: not-allowed; 
            opacity: 0.6; 
            background-color: #2d3748; /* Mais escuro para indicar bloqueio */
        }
        .lesson-item.locked:hover {
            background-color: #2d3748; /* Não muda ao hover */
        }
        .lesson-item.locked .lucide-play-circle, .lesson-item.locked .lucide-lock, .lesson-item.locked .lucide-file-text {
            color: #718096; /* Cinza para ícones bloqueados */
        }
        
        /* ===== INÍCIO: PLAYER YOUTUBE CUSTOMIZADO (CSS DO YMin) ===== */
        .ymin{
         --aspect:16/9; --crop:2000px; --accent:var(--accent-primary); --bar-color:var(--accent); --track-color:#202532; /* <-- COR PRIMÁRIA DO SISTEMA */
         position:relative; width:100%; aspect-ratio:var(--aspect); background:#000; overflow:hidden;
         /* Adicionado para se encaixar no layout */
         border-radius: 0.75rem; /* 12px */
         box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        .ymin .frame{position:relative;width:100%;height:100%;background:#000;overflow:hidden}
        .ymin iframe{position:absolute;inset:0;width:100%;height:calc(100% + var(--crop));top:calc(var(--crop)*-0.5);border:0;display:block;opacity:0;transition:opacity .18s ease}
        .ymin.ready iframe{opacity:1}
        .ymin .veil{position:absolute;inset:0;background:#000;z-index:8;opacity:1;transition:opacity .18s ease}
        .ymin.ready .veil{opacity:0;pointer-events:none}
        .ymin .clickzone{position:absolute;inset:0;z-index:9}

        /* Capas (com ícone) */
        .ymin .overlay{position:absolute;inset:0;z-index:10;display:grid;place-items:center;background:rgba(0,0,0,.5);pointer-events:none}
        .ymin .overlay[hidden]{display:none}
.ymin .cover{display:grid;place-items:center;text-align:center}
.ymin .icon{width:110px;max-width:26vw;height:auto;filter:drop-shadow(0 10px 28px rgba(0,0,0,.6));animation:pulse 1.6s ease-in-out infinite;
         filter: brightness(0) invert(1); /* <-- FORÇA O ÍCONE GRANDE DE PLAY A SER BRANCO */
        }
@keyframes pulse{0%{transform:scale(1)}50%{transform:scale(1.06)}100%{transform:scale(1)}}

        /* HUD + barra (interativa) */
        .ymin .hud.ui{position:absolute;left:0;right:0;bottom:0;z-index:12;height:10px;pointer-events:auto}
        .ymin .progress{position:absolute;left:0;right:0;bottom:0;height:10px;background:var(--track-color);border:0;overflow:hidden;cursor:pointer}
        .ymin .progress .bar{position:absolute;left:0;top:0;bottom:0;width:0;background:var(--bar-color);transition:width .08s linear}

        .ymin .timecode.ui{
         position:absolute; left:12px; bottom:14px; z-index:13;
         padding:4px 8px; border-radius:8px; background:rgba(0,0,0,.55);
         color:#fff; font:600 12px/1.2 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,"Noto Sans","Apple Color Emoji","Segoe UI Emoji"; /* <-- COR DO TEXTO (BRANCO) */
        }

        .ymin .ctrls-right.ui{
         position:absolute; right:10px; bottom:12px; z-index:13; display:flex; gap:8px;
        }
        .ymin .btn{
         width:40px; height:40px; border:0; border-radius:10px; background:var(--accent); color:#fff; /* <-- COR DO BOTÃO (LARANJA) E ÍCONE (BRANCO) */
         display:grid; place-items:center; cursor:pointer; box-shadow:0 6px 18px rgba(0,0,0,.35);
         transition:transform .12s ease, filter .12s ease;
        }
        .ymin .btn:hover{transform:translateY(-1px);filter:brightness(.9)}
.ymin .btn img{width:22px;height:22px;display:block;pointer-events:none;
         filter: brightness(0) invert(1); /* <-- FORÇA OS ÍCONES DOS BOTÕES A SEREM BRANCOS */
        }

:fullscreen .ymin .frame{aspect-ratio:auto;height:100vh}
        :-webkit-full-screen .ymin .frame{aspect-ratio:auto;height:100vh}

        .ymin .ui{opacity:1;transition:opacity .18s ease, transform .18s ease}
        .ymin.controls-hidden .ui{opacity:0; transform:translateY(12px); pointer-events:none}

        /* ===== Vertical (Shorts) ===== */
        .ymin.vertical{
         --aspect:9/16;
         width:min(520px, 100%);
         max-height:84vh;
         margin:0 auto;
         border-radius:14px;
        }
        .ymin.vertical iframe{
         width:calc(100% + var(--crop));
         height:100%;
         left:calc(var(--crop)*-0.5);
         top:0;
        }
        /* ===== FIM: PLAYER YOUTUBE CUSTOMIZADO (CSS DO YMin) ===== */
    </style>
</head>
<body class="text-gray-200 antialiased" style="background-color: #07090d;">
    
    <!-- Cabeçalho da Área de Membros -->
    <header class="text-white p-6 shadow-md sticky top-0 z-50" style="background-color: #07090d;">
        <div class="max-w-7xl mx-auto flex justify-between items-center">
            <div class="flex items-center space-x-4">
                <a href="/member_area_dashboard" class="text-white/90 hover:text-white transition-colors">
                    <i data-lucide="arrow-left-circle" class="w-7 h-7"></i>
                </a>
                <h1 class="text-2xl font-bold"><?php echo htmlspecialchars($curso['titulo'] ?? 'Detalhes do Curso'); ?></h1>
            </div>
            <div class="flex items-center space-x-4">
                <span class="font-medium hidden md:block">Olá, <?php echo htmlspecialchars($cliente_nome); ?>!</span>
                <a href="/member_logout" class="flex items-center space-x-2 text-white/90 hover:text-white transition-colors">
                    <i data-lucide="log-out" class="w-5 h-5"></i>
                    <span class="hidden sm:block">Sair</span>
                </a>
            </div>
        </div>
    </header>

    <?php if ($mensagem_erro): ?>
        <div class="flex h-[calc(100vh-64px)] items-center justify-center p-8">
            <div class="bg-red-900 border border-red-700 text-red-200 px-6 py-4 rounded-lg text-center max-w-lg">
                <p class="font-bold text-lg">Ocorreu um Erro</p>
                <p><?php echo $mensagem_erro; ?></p>
                 <a href="/member_area_dashboard" class="mt-4 inline-block bg-red-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-red-700 transition">Voltar aos Meus Cursos</a>
            </div>
        </div>
    <?php elseif (!$curso): ?>
        <div class="flex h-[calc(100vh-64px)] items-center justify-center p-8">
             <div class="bg-gray-800 border border-gray-700 text-gray-300 px-6 py-4 rounded-lg text-center">
                <p>Carregando...</p>
            </div>
        </div>
    <?php else: 
    // Gerar token CSRF para uso em requisições JavaScript
    $csrf_token_js = generate_csrf_token();
    ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
    <script>
        // Variável global para token CSRF
        window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
    </script>
    <div id="course-container" class="min-h-screen">
        <!-- Banner do Topo do Curso -->
        <header class="relative h-64 md:h-80 bg-gray-800 bg-cover bg-center" style="background-image: url('<?php echo htmlspecialchars($curso['banner_url'] ?? ($upload_dir . $curso['produto_foto'] ?? '')); ?>')">
            <div class="absolute inset-0 bg-gradient-to-t from-gray-900 via-gray-900/70 to-transparent"></div>
            <div class="relative h-full flex flex-col justify-end p-6 md:p-10 max-w-7xl mx-auto">
                <h1 class="text-3xl md:text-5xl font-extrabold text-white drop-shadow-lg"><?php echo htmlspecialchars($curso['titulo']); ?></h1>
                <p class="mt-2 text-lg text-gray-300 max-w-2xl drop-shadow-md"><?php echo htmlspecialchars($curso['descricao'] ?? $curso['produto_descricao']); ?></p>
            </div>
        </header>

        <main class="max-w-7xl mx-auto p-4 md:p-8 w-full">
            <?php if (empty($modulos_com_aulas) || $total_aulas_desbloqueadas === 0): ?>
                <div class="bg-gray-800 border border-gray-700 p-8 rounded-lg text-center text-gray-400">
                    <i data-lucide="video-off" class="mx-auto w-16 h-16 text-gray-600"></i>
                    <p class="mt-4 font-semibold text-lg text-gray-200">Este curso ainda não tem conteúdo disponível.</p>
                    <p>Entre em contato com o suporte se isso for um erro ou verifique as datas de liberação.</p>
                </div>
            <?php else: ?>

                <!-- Player e Aulas (Oculto por padrão, visível após selecionar um módulo) -->
                <div id="player-wrapper" class="hidden">
                    <!-- Barra de Progresso -->
                    <div class="mb-8">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-semibold" style="color: var(--accent-primary);">SEU PROGRESSO</span>
                            <span class="text-sm font-bold text-white"><?php echo $progresso_percentual; ?>% Completo</span>
                        </div>
                        <div class="w-full bg-gray-700 rounded-full h-2.5">
                            <div class="h-2.5 rounded-full" style="width: <?php echo $progresso_percentual; ?>%; background-color: var(--accent-primary);"></div>
                        </div>
                    </div>

                    <!-- Player e Lista de Aulas -->
                    <div id="player-section" class="flex flex-col lg:flex-row gap-8 mb-12">
                        <!-- Coluna Esquerda: Player e Detalhes -->
                        <div class="lg:w-2/3 w-full">
                            
                            <!-- [INÍCIO DA MUDANÇA] Container do Player YMin -->
                            <!-- Este div será o "host" para o player YMin ou para o placeholder. -->
                            <!-- Removido 'aspect-video' daqui, pois o YMin ou o placeholder interno controlarão o aspecto. -->
                            <div id="player-host" class="bg-black rounded-xl shadow-2xl mb-6">
                                <!-- Placeholder inicial que será substituído -->
                                <div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                    <i data-lucide="play-circle" class="w-16 h-16 text-gray-600 mb-4"></i>
                                    <p class="text-lg font-semibold">Selecione um módulo e uma aula para começar.</p>
                                </div>
                            </div>
                            <!-- [FIM DA MUDANÇA] Container do Player YMin -->

                            <div class="bg-gray-800 p-6 rounded-xl shadow-lg">
                                <h2 id="lesson-title" class="text-2xl font-bold text-white mb-4">Selecione um módulo para começar</h2>
                                <div id="lesson-description" class="prose max-w-none">
                                    <p>A descrição e materiais da aula aparecerão aqui.</p>
                                </div>
                                <div class="mt-6 pt-4 border-t border-gray-700 flex justify-end">
                                    <!-- O botão agora será atualizado dinamicamente -->
                                    <button id="mark-as-complete-btn" class="text-white font-bold py-2.5 px-5 rounded-lg transition duration-300 flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed hidden">
                                        <i data-lucide="check-square" class="w-5 h-5"></i>
                                        <span>Marcar como Concluída</span>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <!-- Coluna Direita: Lista de Aulas do Módulo Ativo -->
                        <aside class="lg:w-1/3 w-full bg-gray-800 rounded-xl shadow-lg p-4 flex-shrink-0 h-fit lg:sticky top-20">
                            <h3 id="module-title-aside" class="font-bold text-xl text-white mb-4 px-2">Aulas do Módulo</h3>
                            <div id="lesson-list-container" class="space-y-2 max-h-[70vh] overflow-y-auto pr-2">
                               <p class="text-gray-400 px-2">Selecione um módulo abaixo para ver as aulas.</p>
                            </div>
                        </aside>
                    </div>
                </div>

                <!-- Seção de Módulos (Sempre visível) -->
                <div>
                    <h2 class="text-3xl font-bold text-white mb-6">Módulos do Curso</h2>
                    <div id="modules-grid" class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                        <?php foreach ($modulos_com_aulas as $index => $item): ?>
                            <?php
                            $module = $item['modulo'];
                            $is_module_locked = $module['is_locked'];
                            $module_button_classes = "module-card group relative rounded-lg overflow-hidden border-2 border-gray-700 transition-all duration-300 text-left";
                            $module_button_classes .= $is_module_locked ? ' opacity-50 cursor-not-allowed' : '';
                            ?>
                            <button class="<?php echo $module_button_classes; ?>" 
                                    data-module-id="<?php echo $module['id']; ?>" 
                                    data-module-index="<?php echo $index; ?>"
                                    <?php echo $is_module_locked ? 'disabled' : ''; ?>
                                    >
                                <div class="aspect-[3/4]">
                                    <?php 
                                    $imagem_capa = '';
                                    if (!empty($module['imagem_capa_url'])) {
                                        // O caminho no banco está como 'uploads/imagem_capa_modulo_xxx.png' (sem barra inicial)
                                        $caminho_banco = $module['imagem_capa_url'];
                                        
                                        // Verifica se o arquivo existe usando caminho absoluto
                                        // __DIR__ está em views/member/, então sobe 2 níveis para chegar à raiz
                                        $file_path_absoluto = __DIR__ . '/../../' . $caminho_banco;
                                        
                                        if (file_exists($file_path_absoluto)) {
                                            // Se existe, constrói a URL com / inicial
                                            $imagem_capa = '/' . $caminho_banco;
                                        }
                                        
                                        // Debug temporário - remover depois
                                        // Descomente a linha abaixo e veja o código fonte da página (Ctrl+U) para ver os valores
                                        echo "<!-- DEBUG MODULO: caminho_banco=$caminho_banco | file_path=$file_path_absoluto | exists=" . (file_exists($file_path_absoluto) ? 'SIM' : 'NAO') . " | imagem_capa=" . ($imagem_capa ?: 'VAZIO') . " -->";
                                    }
                                    ?>
                                    <?php if (!empty($imagem_capa)): ?>
                                        <img src="<?php echo htmlspecialchars($imagem_capa); ?>" alt="Capa do <?php echo htmlspecialchars($module['titulo']); ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
                                    <?php else: ?>
                                        <div class="w-full h-full bg-gray-700 flex items-center justify-center">
                                            <i data-lucide="image" class="w-12 h-12 text-gray-500"></i>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent"></div>
                                <div class="absolute bottom-0 left-0 right-0 p-4">
                                    <h4 class="font-bold text-lg text-white"><?php echo htmlspecialchars($module['titulo']); ?></h4>
                                    <?php if ($is_module_locked): ?>
                                        <?php if ($module['lock_reason'] === 'paid_module_no_access' && $module['produto_atrelado'] && !empty($module['produto_atrelado']['checkout_hash'])): ?>
                                            <!-- Módulo Pago Bloqueado -->
                                            <div class="mt-2 space-y-2">
                                                <span class="text-xs text-yellow-400 flex items-center">
                                                    <i data-lucide="dollar-sign" class="w-4 h-4 mr-1"></i>
                                                    Módulo Pago
                                                </span>
                                                <div class="text-xs text-white">
                                                    <p class="font-semibold"><?php echo htmlspecialchars($module['produto_atrelado']['nome']); ?></p>
                                                    <p class="text-yellow-400">R$ <?php echo number_format($module['produto_atrelado']['preco'], 2, ',', '.'); ?></p>
                                                </div>
                                                <a href="/checkout?p=<?php echo htmlspecialchars($module['produto_atrelado']['checkout_hash']); ?>" 
                                                   class="block w-full mt-2 text-white font-bold py-2 px-4 rounded-lg transition text-center text-sm"
                                                   style="background-color: var(--accent-primary);"
                                                   onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'"
                                                   onmouseout="this.style.backgroundColor='var(--accent-primary)'"
                                                   onclick="event.stopPropagation();">
                                                    <i data-lucide="shopping-cart" class="w-4 h-4 inline-block mr-1"></i>
                                                    Comprar Produto
                                                </a>
                                            </div>
                                        <?php elseif ($module['lock_reason'] === 'paid_module_no_access' && $module['produto_atrelado']): ?>
                                            <!-- Módulo Pago sem checkout_hash configurado -->
                                            <div class="mt-2 space-y-2">
                                                <span class="text-xs text-yellow-400 flex items-center">
                                                    <i data-lucide="dollar-sign" class="w-4 h-4 mr-1"></i>
                                                    Módulo Pago
                                                </span>
                                                <div class="text-xs text-white">
                                                    <p class="font-semibold"><?php echo htmlspecialchars($module['produto_atrelado']['nome']); ?></p>
                                                    <p class="text-red-400">Checkout não configurado para este produto.</p>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <!-- Módulo Bloqueado por Release Days -->
                                            <span class="text-xs text-red-400 flex items-center mt-1">
                                                <i data-lucide="lock" class="w-4 h-4 mr-1"></i> 
                                                Disponível em: <?php echo $module['available_at']; ?>
                                            </span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-xs" style="color: var(--accent-primary);"><?php echo count($item['aulas']); ?> aulas</span>
                                    <?php endif; ?>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
    <?php endif; ?>

    <script>
        /* =================================================================== */
        /* ====== INÍCIO: TECNOLOGIA DO PLAYER (COPIADO DO YMin) ====== */
        /* =================================================================== */

        /* ÍCONES personalizados (PNG) */
        const ICONS = {
         back5: "https://iili.io/KCUAyMJ.png",
         fwd5: "https://iili.io/KCU5QhF.png",
         play: "https://iili.io/KCUYGS4.png",
         fs: "https://iili.io/KCUaDBe.png"
        };

        /* Tempo para auto-ocultar controles (ms) */
        const HIDE_DELAY_MS = 2200;

        /* ===================== YOUTUBE PLAYER API (Carregador) ===================== */
        (function(){
         if (!window._ytApi) {
         window._ytApi = {};
         window._ytApi.promise = new Promise((resolve) => {
           window._ytApi._resolve = resolve;
           const s = document.createElement('script');
           s.src = 'https://www.youtube.com/iframe_api';
           document.head.appendChild(s);
           const prev = window.onYouTubeIframeAPIReady;
           window.onYouTubeIframeAPIReady = function(){
           if (typeof prev === 'function') try { prev(); } catch {}
           window._ytApi._resolve();
           };
         });
         }})();
        const ytApiReady = window._ytApi.promise;

        let yminPlayer=null, yminRaf=0, yminRoot=null, yminPlaying=false, yminFirst=false, idleTimer=0, scrubbing=false;

        /* Barra "fake" pra UX */
        const REACH_AT = 0.90, PEAK_AT = 0.70, ACCEL_SHAPE = 0.6;
        function fakeFromReal(p){
         p=Math.max(0,Math.min(1,p));
         if(p<=REACH_AT){ const t=p/REACH_AT; return PEAK_AT*Math.pow(t,ACCEL_SHAPE); }
         const t=(p-REACH_AT)/(1-REACH_AT); return PEAK_AT+(1-PEAK_AT)*(1-Math.pow(1-t,3));
        }
        function formatTime(s){
         s = Math.max(0, Math.floor(s||0));
         const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
         if (h>0) return `${h}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
         return `${m}:${String(sec).padStart(2,'0')}`;}

        /* ====== MOUNT COM IMAGENS (ícones customizados) ====== */
        function mountYMinHTML(root){
         const mountId='yt-mount-'+Math.random().toString(36).slice(2,8);
         root.innerHTML=`
         <div class="frame">
           <div class="clickzone" aria-hidden="true"></div>
           <div id="${mountId}"></div>
           <div class="veil" aria-hidden="true"></div>

           <div class="overlay start"><div class="cover">
           <img class="icon" src="${ICONS.play}" alt="Play">
           </div></div>

           <div class="overlay paused" hidden><div class="cover">
           <img class="icon" src="${ICONS.play}" alt="Play">
           </div></div>

           <div class="hud ui"><div class="progress"><div class="bar"></div></div></div>
           <div class="timecode ui"><span class="cur">0:00</span> / <span class="dur">0:00</span></div>

           <div class="ctrls-right ui">
           <button class="btn back5" type="button" aria-label="Voltar 5 segundos" title="Voltar 5s">
             <img src="${ICONS.back5}" alt="Voltar 5s">
           </button>
           <button class="btn fwd5" type="button" aria-label="Avançar 5 segundos" title="Avançar 5s">
             <img src="${ICONS.fwd5}" alt="Avançar 5s">
           </button>
           <button class="btn fsbtn" type="button" aria-label="Tela cheia" title="Tela cheia">
             <img src="${ICONS.fs}" alt="Tela cheia">
           </button>
           </div>
         </div>
         `;
         return mountId;
        }
        function destroyYMin(){
         cancelAnimationFrame(yminRaf); yminRaf=0;
         try{ yminPlayer && yminPlayer.destroy && yminPlayer.destroy(); }catch{}
         yminPlayer=null; yminRoot=null; yminPlaying=false; yminFirst=false; scrubbing=false;
         clearTimeout(idleTimer);
        }
        function showControls(root){
         root.classList.remove('controls-hidden');
         clearTimeout(idleTimer);
         idleTimer = setTimeout(()=>{ if (!scrubbing) root.classList.add('controls-hidden'); }, HIDE_DELAY_MS);
        }
        function clamp01(x){ return Math.max(0, Math.min(1, x)); }

        async function createYMin(root, videoId){
         destroyYMin(); yminRoot=root;
         const mountId = mountYMinHTML(root);

         const isVertical = root.classList.contains('vertical') || root.dataset.vertical === '1';
         if (isVertical) { root.style.setProperty('--aspect','9/16'); }

         const frame   = root.querySelector('.frame');
         const clickzone = root.querySelector('.clickzone');
         const startOv  = root.querySelector('.overlay.start');
         const pausedOv = root.querySelector('.overlay.paused');
         const barEl   = root.querySelector('.progress .bar');
         const progress = root.querySelector('.progress');
         const curEl   = root.querySelector('.timecode .cur');
         const durEl   = root.querySelector('.timecode .dur');
         const fsBtn   = root.querySelector('.fsbtn');
         const back5Btn = root.querySelector('.back5');
         const fwd5Btn  = root.querySelector('.fwd5');

         setTimeout(() => { try { root.classList.add('ready'); } catch {} }, 1500);
         showControls(root);

         await ytApiReady;

         yminPlayer = new YT.Player(mountId,{
         videoId, host:'https://www.youtube-nocookie.com',
         playerVars:{autoplay:1,mute:1,controls:0,disablekb:1,fs:0,modestbranding:1,rel:0,iv_load_policy:3,playsinline:1},
         events:{
           onReady(){
           try{yminPlayer.mute();yminPlayer.playVideo();}catch{}
           requestAnimationFrame(()=>root.classList.add('ready'));
           setTimeout(()=>{ try { root.classList.add('ready'); } catch {} }, 400);
           loop();
           },
           onStateChange(e){
           if(e.data===YT.PlayerState.PLAYING){
             yminPlaying=true; if(yminFirst){ startOv.hidden=true; pausedOv.hidden=true; }
           }else if(e.data===YT.PlayerState.PAUSED){
             yminPlaying=false; if(yminFirst){ pausedOv.hidden=false; }
           }else if(e.data===YT.PlayerState.ENDED){
             yminPlaying=false; try{yminPlayer.seekTo(0,true);yminPlayer.pauseVideo();}catch{} pausedOv.hidden=false;
           }
           }
         }
         });

         function firstPlay(){ yminFirst=true; startOv.hidden=true; try{yminPlayer.seekTo(0,true);yminPlayer.unMute();}catch{} play(); }
         function play(){ try{yminPlayer.playVideo();}catch{} }
         function pause(){ try{yminPlayer.pauseVideo();}catch{} }
         function toggle(){ showControls(root); yminPlaying ? pause() : (yminFirst ? play() : firstPlay()); }

         clickzone.addEventListener('click', toggle);
         root.addEventListener('mousemove', ()=>showControls(root), {passive:true});
         root.addEventListener('touchstart', ()=>showControls(root), {passive:true});
         root.addEventListener('touchmove', ()=>showControls(root), {passive:true});

         function enterFs(el){ (el.requestFullscreen||el.webkitRequestFullscreen||el.msRequestFullscreen||el.mozRequestFullScreen)?.call(el); }
         function exitFs(){ (document.exitFullscreen||document.webkitExitFullscreen||document.msExitFullscreen||document.mozCancelFullScreen)?.call(document); }
         function isFs(){ return document.fullscreenElement||document.webkitFullscreenElement||document.msFullscreenElement||document.mozFullScreenElement; }
         fsBtn.addEventListener('click', e=>{ e.stopPropagation(); showControls(root); isFs()?exitFs():enterFs(frame); });

         function seekBy(delta){
         try{
           const cur = yminPlayer?.getCurrentTime?.()||0;
           const dur = yminPlayer?.getDuration?.()||0;
           if (dur>0){
           let t = Math.max(0, Math.min(dur-0.1, cur + delta));
           yminPlayer.seekTo(t, true);
           }
         }catch{}
         }
         back5Btn.addEventListener('click', (e)=>{ e.stopPropagation(); showControls(root); seekBy(-5); });
         fwd5Btn .addEventListener('click', (e)=>{ e.stopPropagation(); showControls(root); seekBy(+5); });

         function pctFromEvent(ev){
         const r = progress.getBoundingClientRect();
         const x = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left;
         return clamp01(x / r.width);
         }
         function preview(p){ barEl.style.width = (fakeFromReal(p)*100).toFixed(2)+'%'; }
         function seekToPct(p){
         const dur = yminPlayer?.getDuration?.() || 0;
         if (dur>0) yminPlayer.seekTo(dur * clamp01(p), true);
         }
         function startScrub(ev){
         ev.preventDefault(); scrubbing = true; showControls(root);
         const p = pctFromEvent(ev); preview(p); seekToPct(p);
         window.addEventListener('mousemove', moveScrub);
         window.addEventListener('touchmove', moveScrub, {passive:false});
         window.addEventListener('mouseup', endScrub);
         window.addEventListener('touchend', endScrub);
         }
         function moveScrub(ev){
         ev.preventDefault();
         if(!scrubbing) return;
         const p = pctFromEvent(ev); preview(p); seekToPct(p);
         }
         function endScrub(ev){
         if(!scrubbing) return;
         scrubbing=false;
         const p = pctFromEvent(ev); preview(p); seekToPct(p);
         window.removeEventListener('mousemove', moveScrub);
         window.removeEventListener('touchmove', moveScrub);
         window.removeEventListener('mouseup', endScrub);
         window.removeEventListener('touchend', endScrub);
         showControls(root);
         }
         progress.addEventListener('mousedown', startScrub);
         progress.addEventListener('touchstart', startScrub, {passive:true});

         function loop(){
         cancelAnimationFrame(yminRaf);
         const tick=()=>{
           try{
           const cur=yminPlayer?.getCurrentTime?.()||0;
           const dur=yminPlayer?.getDuration?.()||0;
           if(dur>0){
             curEl.textContent = formatTime(cur);
             durEl.textContent = formatTime(dur);
             if(!scrubbing){
             const pReal = cur/dur;
             barEl.style.width = (fakeFromReal(pReal)*100).toFixed(2)+'%';
             }
           }
           }catch{}
           yminRaf=requestAnimationFrame(tick);
         };
         yminRaf=requestAnimationFrame(tick);
         }
        }
        /* =================================================================== */
        /* ====== FIM: TECNOLOGIA DO PLAYER (COPIADO DO YMin) ======== */
        /* =================================================================== */


        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();
            
            const allModulesData = <?php echo json_encode($modulos_com_aulas); ?>;
            const clienteEmail = "<?php echo htmlspecialchars($cliente_email); ?>";
            const currentProductId = "<?php echo htmlspecialchars($produto_id); ?>";
            const aulaFilesDirPublic = "<?php echo htmlspecialchars($aula_files_dir_public); ?>";
            
            if (!allModulesData || allModulesData.length === 0) return;

            const playerWrapper = document.getElementById('player-wrapper');
            // [INÍCIO DA MUDANÇA] Referências do Player
            const playerHost = document.getElementById('player-host'); // Novo container do player
            const initialPlaceholderHTML = playerHost.innerHTML; // Salva o placeholder inicial
            // [FIM DA MUDANÇA]
            
            const lessonTitle = document.getElementById('lesson-title');
            const lessonDescription = document.getElementById('lesson-description');
            const lessonListContainer = document.getElementById('lesson-list-container');
            const moduleCards = document.querySelectorAll('.module-card');
            const moduleTitleAside = document.getElementById('module-title-aside');
            const markAsCompleteBtn = document.getElementById('mark-as-complete-btn');
            
            let currentModuleId = null;
            let currentLessonData = null; // Guarda os dados da aula atualmente carregada

            
            // [INÍCIO DA MUDANÇA] Função loadLesson atualizada para usar YMin
            function loadLesson(lesson) {
                // 1. Destrói qualquer player YMin anterior
                destroyYMin(); 

                if (!lesson) { // Reset player if no lesson
                    playerHost.innerHTML = initialPlaceholderHTML; // Restaura placeholder inicial
                    lucide.createIcons();
                    lessonTitle.textContent = 'Nenhuma aula selecionada';
                    lessonDescription.innerHTML = '<p>Selecione uma aula na lista ao lado.</p>';
                    markAsCompleteBtn.classList.add('hidden');
                    currentLessonData = null;
                    return;
                }
                
                // 2. Lida com aula bloqueada
                if (lesson.is_locked) {
                    playerHost.innerHTML = `<div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                                <i data-lucide="lock" class="w-16 h-16 text-gray-600 mb-4"></i>
                                                <p class="text-lg font-semibold">Aula Bloqueada</p>
                                                <p class="text-sm">Disponível em: ${lesson.available_at}</p>
                                            </div>`;
                    lucide.createIcons();
                    lessonTitle.textContent = 'Aula Bloqueada';
                    lessonDescription.innerHTML = `<p class="text-red-400 flex items-center"><i data-lucide="lock" class="w-5 h-5 mr-2"></i> Esta aula estará disponível em: ${lesson.available_at}.</p><p>Volte mais tarde para acessá-la!</p>`;
                    markAsCompleteBtn.classList.add('hidden');
                    currentLessonData = null;
                    lucide.createIcons(); // Render the lock icon in the description
                    return;
                }

                currentLessonData = lesson;

                // 3. Lógica de exibição: Tenta encontrar um ID de vídeo do YouTube
                let videoId = null;
                let isShort = false;
                if ((lesson.tipo_conteudo === 'video' || lesson.tipo_conteudo === 'mixed') && lesson.url_video) {
                    // Regex do player YMin para extrair o ID
                    const match = lesson.url_video.match(/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/i);
                    if (match && match[1]) {
                        videoId = match[1];
                        isShort = /youtube\.com\/shorts\//i.test(lesson.url_video);
                    }
                }

                // 4. Carrega o Player YMin ou o Placeholder de "Sem Vídeo"
                if (videoId) {
                    // Encontrou um vídeo do YouTube -> Carrega o YMin
                    playerHost.innerHTML = ''; // Limpa o placeholder
                    const playerDiv = document.createElement('div');
                    // Adiciona a classe 'ymin' e 'controls-hidden' (e 'vertical' se for short)
                    playerDiv.className = `ymin controls-hidden ${isShort ? 'vertical' : ''}`;
                    playerHost.appendChild(playerDiv);
                    
                    // Chama a função principal do YMin
                    createYMin(playerDiv, videoId);
                } else {
                    // Não é um vídeo do YouTube (pode ser 'files' ou URL inválida) -> Mostra placeholder
                    playerHost.innerHTML = `<div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                                <i data-lucide="video-off" class="w-16 h-16 text-gray-600 mb-4"></i>
                                                <p class="text-lg font-semibold">Esta aula não contém vídeo.</p>
                                                <p class="text-sm">Verifique os materiais de apoio abaixo.</p>
                                            </div>`;
                    lucide.createIcons();
                }


                // 5. Carrega Título, Descrição e Arquivos (lógica original mantida)
                lessonTitle.textContent = lesson.titulo;

                let descriptionHtml = (lesson.descricao || 'Esta aula não possui descrição.')
                    .replace(/</g, "&lt;").replace(/>/g, "&gt;") // Basic HTML escaping
                    .replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" class="hover:underline" style="color: var(--accent-primary);">$1</a>') // Link detection
                    .replace(/\n/g, '<br>');
                
                // Adicionar arquivos de apoio como botões CTA
                if ((lesson.tipo_conteudo === 'files' || lesson.tipo_conteudo === 'mixed') && lesson.files && lesson.files.length > 0) {
                    descriptionHtml += '<h4 class="text-lg font-bold text-white mt-6 mb-3">Materiais de Apoio</h4>';
                    descriptionHtml += '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">'; // Responsive grid container
                    lesson.files.forEach(file => {
                        const filePath = `${aulaFilesDirPublic}${file.nome_salvo}`;
                        descriptionHtml += `
                            <a href="${filePath}" target="_blank" class="text-white font-bold py-3 px-6 rounded-lg transition duration-300 text-base flex items-center justify-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                                <i data-lucide="download" class="w-5 h-5 flex-shrink-0"></i>
                                <span>${file.nome_original}</span>
                            </a>
                        `;
                    });
                    descriptionHtml += '</div>'; // Close the grid div
                } else if ((lesson.tipo_conteudo === 'files' || lesson.tipo_conteudo === 'mixed') && (!lesson.files || lesson.files.length === 0)) {
                    descriptionHtml += '<p class="text-gray-500 mt-4">Nenhum material de apoio disponível para esta aula.</p>';
                }


                lessonDescription.innerHTML = descriptionHtml;
                lucide.createIcons(); // Re-render icons if new ones were added in descriptionHtml

                // 6. Highlight na aula ativa (lógica original mantida)
                document.querySelectorAll('.lesson-item').forEach(item => {
                    item.classList.toggle('active', item.dataset.lessonId == lesson.id);
                });

                // 7. Atualiza o botão "Marcar como Concluída" (lógica original mantida)
                // AQUI USAMOS O ESTADO ATUAL DA AULA (lesson.concluida)
                updateMarkAsCompleteButton(lesson.concluida);
            }
            // [FIM DA MUDANÇA] Função loadLesson

            // [INÍCIO DA MUDANÇA] Função de atualização do botão
            function updateMarkAsCompleteButton(isConcluida) {
                if (!markAsCompleteBtn || !currentLessonData || currentLessonData.is_locked) { 
                    markAsCompleteBtn.classList.add('hidden'); // Oculta se a aula estiver bloqueada ou nenhuma aula selecionada
                    return;
                }
                markAsCompleteBtn.classList.remove('hidden'); // Mostra o botão
                markAsCompleteBtn.disabled = false; // Garante que o botão está habilitado

                if (isConcluida) {
                    // Estado "Concluída" - Pronta para DESMARCAR
                    markAsCompleteBtn.innerHTML = '<i data-lucide="x-square" class="w-5 h-5"></i><span>Desmarcar Conclusão</span>';
                    markAsCompleteBtn.classList.remove('bg-green-600', 'hover:bg-green-700', 'bg-gray-600', 'cursor-not-allowed');
                    markAsCompleteBtn.classList.add('bg-yellow-600', 'hover:bg-yellow-700'); // Cor amarela para desmarcar
                } else {
                    // Estado "Não Concluída" - Pronta para MARCAR
                    markAsCompleteBtn.innerHTML = '<i data-lucide="check-square" class="w-5 h-5"></i><span>Marcar como Concluída</span>';
                    markAsCompleteBtn.classList.remove('bg-yellow-600', 'hover:bg-yellow-700', 'bg-gray-600', 'cursor-not-allowed');
                    markAsCompleteBtn.classList.add('bg-green-600', 'hover:bg-green-700'); // Cor verde para marcar
                }
                lucide.createIcons(); // Renderiza os novos ícones (check-square ou x-square)
            }
            // [FIM DA MUDANÇA] Função de atualização do botão


            function displayLessonsForModule(moduleIndex) {
                const moduleData = allModulesData[moduleIndex];
                if (!moduleData) return;

                currentModuleId = moduleData.modulo.id;

                // Highlight active module card
                moduleCards.forEach(card => {
                    card.classList.toggle('active', card.dataset.moduleId == currentModuleId);
                });
                
                moduleTitleAside.textContent = moduleData.modulo.titulo;
                lessonListContainer.innerHTML = ''; // Clear previous lessons

                if (moduleData.aulas.length === 0) {
                    lessonListContainer.innerHTML = '<p class="text-gray-400 px-2">Este módulo não possui aulas.</p>';
                    loadLesson(null); // Clear the player
                    return;
                }

                let firstAvailableLesson = null;

                moduleData.aulas.forEach(aula => {
                    const lessonButton = document.createElement('button');
                    let iconHtml = '';
                    let textClass = 'text-gray-300'; // Default class for unlocked, not completed lessons

                    if (aula.is_locked) {
                        lessonButton.className = 'lesson-item w-full text-left flex items-center space-x-3 p-3 rounded-lg locked';
                        iconHtml = `<i data-lucide="lock" class="w-5 h-5 flex-shrink-0 text-gray-500"></i>`;
                        textClass = 'text-gray-500'; // Make text dimmer for locked lessons
                    } else {
                        lessonButton.className = 'lesson-item w-full text-left flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-700 transition';
                        
                        // Determine icon(s) based on content type
                        let videoIcon = '';
                        let fileIcon = '';

                        if (aula.tipo_conteudo === 'video' || aula.tipo_conteudo === 'mixed') {
                            videoIcon = `<i data-lucide="play-circle" class="w-5 h-5 flex-shrink-0 ${aula.concluida ? 'text-green-500' : 'text-gray-500'}"></i>`;
                        }
                        if (aula.tipo_conteudo === 'files' || aula.tipo_conteudo === 'mixed') {
                            fileIcon = `<i data-lucide="file-text" class="w-5 h-5 flex-shrink-0 ${aula.concluida ? 'text-green-500' : 'text-gray-500'}"></i>`;
                        }
                        // Combine them, possibly with a small space
                        iconHtml = videoIcon + (videoIcon && fileIcon ? '<span class="w-1"></span>' : '') + fileIcon;


                        if (aula.concluida) {
                            textClass = 'text-gray-400 line-through'; // [MUDANÇA] Mantém o line-through para concluídas
                        } else {
                             textClass = 'text-gray-300';
                             if (!firstAvailableLesson) { // Keep track of the first unlocked lesson
                                 firstAvailableLesson = aula;
                             }
                        }
                    }

                    lessonButton.dataset.lessonId = aula.id;
                    lessonButton.innerHTML = `
                        <div class="flex items-center space-x-1">
                            ${iconHtml}
                        </div>
                        <span class="${textClass}">${aula.titulo}</span>
                        ${aula.concluida && !aula.is_locked ? '<i data-lucide="check" class="w-4 h-4 text-green-500 ml-auto flex-shrink-0"></i>' : ''}
                        ${aula.is_locked ? `<span class="ml-auto text-xs text-gray-500">Disp. ${aula.available_at}</span>` : ''}
                    `;
                    
                    // [MUDANÇA] A aula é carregada ao clicar, mesmo se bloqueada (a loadLesson tratará o bloqueio)
                    lessonButton.addEventListener('click', () => loadLesson(aula));
                    
                    lessonListContainer.appendChild(lessonButton);
                });
                lucide.createIcons();
                
                // Auto-load the first unlocked lesson of this module, or the very first one if none are unlocked.
                loadLesson(firstAvailableLesson || moduleData.aulas[0]);
            }
            
            // Event listeners for module cards
            moduleCards.forEach(card => {
                card.addEventListener('click', (e) => {
                    // Prevent click if clicking on "Comprar Produto" link
                    if (e.target.closest('a[href*="/checkout"]')) {
                        return; // Let the link work normally
                    }
                    
                    // Only allow click if module is not locked
                    if (card.disabled) return; 

                    playerWrapper.classList.remove('hidden'); // Make the player section visible
                    
                    const moduleIndex = parseInt(card.dataset.moduleIndex, 10);
                    displayLessonsForModule(moduleIndex);

                    // Scroll to player
                    playerWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });

            // [INÍCIO DA MUDANÇA] Lógica de clique do botão "Marcar/Desmarcar"
            markAsCompleteBtn.addEventListener('click', async () => {
                // Checa se há uma aula carregada, se o botão está desabilitado (ex: durante uma chamada de API) ou se a aula está bloqueada
                if (!currentLessonData || markAsCompleteBtn.disabled || currentLessonData.is_locked) return;

                // Desabilita o botão temporariamente para evitar cliques duplos
                markAsCompleteBtn.disabled = true;

                const isCompleted = currentLessonData.concluida;
                const action = isCompleted ? 'unmark_lesson_complete' : 'mark_lesson_complete';
                const newConcluidaState = !isCompleted;

                try {
                    const response = await fetch(`/api/member_api.php?action=${action}`, {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': window.csrfToken || ''
                        },
                        body: JSON.stringify({
                            aluno_email: clienteEmail,
                            aula_id: currentLessonData.id,
                            csrf_token: window.csrfToken || ''
                        })
                    });
                    const result = await response.json();

                    if (result.success) {
                        // 1. Atualiza o estado da aula atual
                        currentLessonData.concluida = newConcluidaState; 
                        
                        // 2. Atualiza o estado global (em allModulesData)
                        allModulesData.forEach(moduleItem => {
                            moduleItem.aulas.forEach(aula => {
                                if (aula.id === currentLessonData.id) {
                                    aula.concluida = newConcluidaState;
                                }
                            });
                        });

                        // 3. Atualiza o visual do botão
                        updateMarkAsCompleteButton(newConcluidaState);
                        
                        // 4. Re-renderiza a lista de aulas para refletir a mudança (ex: ícone, line-through)
                        const activeModuleIndex = Array.from(moduleCards).findIndex(card => card.classList.contains('active'));
                        if (activeModuleIndex !== -1) {
                            displayLessonsForModule(activeModuleIndex);
                        }

                        // 5. Atualiza a barra de progresso geral
                        updateOverallProgress();

                    } else {
                        console.error(`Erro ao ${action}: ` + (result.error || 'Erro desconhecido.'));
                        // Se a ação de desmarcar falhar, avisa o usuário (pois pode ser problema de backend)
                        if (action === 'unmark_lesson_complete') {
                            console.warn('Atenção: A ação "unmark_lesson_complete" falhou. Verifique se ela foi implementada no seu "member_api.php".');
                            // Não reverta o estado aqui, apenas re-habilite o botão
                        }
                    }
                } catch (error) {
                    console.error(`Erro de rede/API ao ${action}:`, error);
                } finally {
                    // Re-habilita o botão após a conclusão da API (com sucesso ou falha)
                    // A função updateMarkAsCompleteButton já faz isso, mas podemos garantir
                    if (currentLessonData && !currentLessonData.is_locked) {
                         markAsCompleteBtn.disabled = false;
                         // Garante que o botão está no estado correto caso a API falhe e não atualize
                         updateMarkAsCompleteButton(currentLessonData.concluida);
                    }
                }
            });
            // [FIM DA MUDANÇA] Lógica de clique do botão "Marcar/Desmarcar"


            function updateOverallProgress() {
                let currentTotalAulas = 0;
                let currentAulasConcluidas = 0;

                allModulesData.forEach(moduleItem => {
                    moduleItem.aulas.forEach(aula => {
                        // Only count UNLOCKED lessons for overall progress
                        if (!aula.is_locked) { 
                            currentTotalAulas++;
                            if (aula.concluida) {
                                currentAulasConcluidas++;
                            }
                        }
                    });
                });

                const newProgressPercent = currentTotalAulas > 0 ? Math.round((currentAulasConcluidas / currentTotalAulas) * 100) : 0;
                
                document.querySelector('#player-wrapper .font-bold.text-white').textContent = `${newProgressPercent}% Completo`;
                const progressBar = document.querySelector('#player-wrapper .h-2\\.5');
                if (progressBar) {
                    progressBar.style.width = `${newProgressPercent}%`;
                    progressBar.style.backgroundColor = 'var(--accent-primary)';
                }
            }

            // Initial call to update progress bar on page load
            updateOverallProgress();
            
            // Render icons for paid module buttons (if any)
            lucide.createIcons();
        });
    </script>
</body>
</html>