<?php
// Este arquivo é incluído a partir do index.php,
// então a verificação de login e a conexão com o banco ($pdo) já existem.

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../helpers/security_helper.php';

// Obter o ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;

// Se por algum motivo o ID do usuário não estiver definido, redireciona para o login
if ($usuario_id_logado === 0) {
    header("location: /login");
    exit;
}

$mensagem = '';
$produto = null;
$curso = null;
$modulos_com_aulas = [];
$upload_dir = 'uploads/';
$aula_files_dir = 'uploads/aula_files/';

// Garante que o diretório de arquivos de aula exista
if (!is_dir($aula_files_dir)) {
    mkdir($aula_files_dir, 0755, true);
}


// 1. Validar e buscar o produto_id
if (!isset($_GET['produto_id']) || !is_numeric($_GET['produto_id'])) {
    header("Location: /index?pagina=area_membros");
    exit;
}
$produto_id = (int)$_GET['produto_id'];

try {
    // 2. Buscar o produto e verificar se é do tipo 'area_membros' E pertence ao usuário logado
    $stmt_produto = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND tipo_entrega = 'area_membros' AND usuario_id = ?");
    $stmt_produto->execute([$produto_id, $usuario_id_logado]);
    $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        // Se o produto não for encontrado ou não pertencer ao usuário, redireciona
        $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Produto não encontrado ou você não tem permissão para acessá-lo.</div>";
        header("Location: /index?pagina=area_membros");
        exit;
    }

    // 3. Sincronizar com a tabela 'cursos'
    $stmt_curso = $pdo->prepare("SELECT * FROM cursos WHERE produto_id = ?");
    $stmt_curso->execute([$produto_id]);
    $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);

    if (!$curso) {
        // Se o curso não existe, cria um novo
        $stmt_insert_curso = $pdo->prepare("INSERT INTO cursos (produto_id, titulo, descricao, imagem_url) VALUES (?, ?, ?, ?)");
        $stmt_insert_curso->execute([$produto_id, $produto['nome'], $produto['descricao'], $produto['foto'] ? 'uploads/' . $produto['foto'] : null]);
        
        // Busca o curso recém-criado
        $stmt_curso->execute([$produto_id]);
        $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
    }
    $curso_id = $curso['id'];

    // 4. Lógica de manipulação de dados (POST requests)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verifica CSRF (security_helper.php já foi incluído no início)
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Token CSRF inválido ou ausente.</div>";
            header("Location: /index?pagina=gerenciar_curso&produto_id=" . $produto_id);
            exit;
        }
        
        $should_redirect = false; // Flag para controlar o redirecionamento

        // Função auxiliar para upload de arquivos (segura)
        function handle_file_upload($file_key, $target_dir, $current_file_path = null) {
            // security_helper.php já foi incluído no início
            
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                // Deleta o arquivo antigo se existir (somente se o caminho for do diretório de uploads)
                if ($current_file_path && file_exists($current_file_path) && strpos($current_file_path, 'uploads/') === 0) {
                    @unlink($current_file_path);
                }
                
                // Valida e faz upload seguro - APENAS JPEG ou PNG para imagens de curso/módulo
                $upload_result = validate_image_upload($_FILES[$file_key], $target_dir, $file_key, 5, true);
                if ($upload_result['success']) {
                    return $upload_result['file_path'];
                }
            }
            return null; // Retorna null se não houver upload ou falhar
        }

        // Salvar Banner do Curso
        if (isset($_POST['salvar_banner_curso'])) {
            $should_redirect = true;
            $novo_banner_path = handle_file_upload('banner_curso', $upload_dir, $curso['banner_url']);
            if ($novo_banner_path) {
                $stmt = $pdo->prepare("UPDATE cursos SET banner_url = ? WHERE id = ?");
                $stmt->execute([$novo_banner_path, $curso_id]);
                $mensagem = "<div class='bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded' role='alert'>Banner do curso atualizado!</div>";
            } else if (!empty($_POST['remove_banner'])) { // Lógica para remover banner
                if ($curso['banner_url'] && file_exists($curso['banner_url']) && strpos($curso['banner_url'], 'uploads/') === 0) {
                    unlink($curso['banner_url']);
                }
                $stmt = $pdo->prepare("UPDATE cursos SET banner_url = NULL WHERE id = ?");
                $stmt->execute([$curso_id]);
                $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Banner do curso removido!</div>";
            } else {
                 $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Nenhuma imagem de banner enviada ou selecionada para remover.</div>";
            }
        }

        // Adicionar Módulo
        if (isset($_POST['adicionar_modulo'])) {
            $should_redirect = true;
            $titulo_modulo = trim($_POST['titulo_modulo']);
            $release_days_modulo = (int)($_POST['release_days_modulo'] ?? 0);
            $is_paid_module = isset($_POST['is_paid_module']) ? 1 : 0;
            $linked_product_id = null;
            
            if ($is_paid_module) {
                $linked_product_id = !empty($_POST['linked_product_id']) ? (int)$_POST['linked_product_id'] : null;
                
                // Validar que o produto pertence ao mesmo infoprodutor
                if ($linked_product_id) {
                    $stmt_check_prod = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND usuario_id = ?");
                    $stmt_check_prod->execute([$linked_product_id, $usuario_id_logado]);
                    if ($stmt_check_prod->rowCount() === 0) {
                        $linked_product_id = null;
                        $is_paid_module = 0;
                        $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Produto selecionado inválido. Módulo criado como gratuito.</div>";
                    }
                } else {
                    $is_paid_module = 0;
                    $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Módulo pago selecionado mas nenhum produto escolhido. Módulo criado como gratuito.</div>";
                }
            }
            
            if (!empty($titulo_modulo)) {
                $stmt = $pdo->prepare("INSERT INTO modulos (curso_id, titulo, release_days, is_paid_module, linked_product_id) VALUES (?, ?, ?, ?, ?)"); 
                $stmt->execute([$curso_id, $titulo_modulo, $release_days_modulo, $is_paid_module, $linked_product_id]); 
                if (empty($mensagem)) {
                    $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Módulo adicionado!</div>";
                }
            } else {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>O título do módulo não pode estar vazio.</div>";
            }
        }
        
        // Editar Módulo (Título, Capa, Release Days e Módulo Pago)
        if (isset($_POST['editar_modulo'])) {
            $should_redirect = true;
            $modulo_id_edit = $_POST['modulo_id'];
            $titulo_modulo_edit = trim($_POST['titulo_modulo']);
            $release_days_edit = (int)($_POST['release_days_modulo'] ?? 0);
            $is_paid_module = isset($_POST['is_paid_module']) ? 1 : 0;
            $linked_product_id = null;
            
            if ($is_paid_module) {
                $linked_product_id = !empty($_POST['linked_product_id']) ? (int)$_POST['linked_product_id'] : null;
                
                // Validar que o produto pertence ao mesmo infoprodutor
                if ($linked_product_id) {
                    $stmt_check_prod = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND usuario_id = ?");
                    $stmt_check_prod->execute([$linked_product_id, $usuario_id_logado]);
                    if ($stmt_check_prod->rowCount() === 0) {
                        $linked_product_id = null;
                        $is_paid_module = 0;
                        $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Produto selecionado inválido. Módulo atualizado como gratuito.</div>";
                    }
                } else {
                    $is_paid_module = 0;
                    $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Módulo pago selecionado mas nenhum produto escolhido. Módulo atualizado como gratuito.</div>";
                }
            }

            // Busca dados atuais do módulo para pegar o caminho da imagem antiga
            $stmt_old_mod = $pdo->prepare("SELECT imagem_capa_url FROM modulos WHERE id = ? AND curso_id = ?");
            $stmt_old_mod->execute([$modulo_id_edit, $curso_id]);
            $old_module = $stmt_old_mod->fetch(PDO::FETCH_ASSOC);

            if ($old_module) {
                $nova_imagem_path = handle_file_upload('imagem_capa_modulo', $upload_dir, $old_module['imagem_capa_url']);
                
                if ($nova_imagem_path) { // Se uma nova imagem foi enviada
                    $stmt = $pdo->prepare("UPDATE modulos SET titulo = ?, imagem_capa_url = ?, release_days = ?, is_paid_module = ?, linked_product_id = ? WHERE id = ? AND curso_id = ?"); 
                    $stmt->execute([$titulo_modulo_edit, $nova_imagem_path, $release_days_edit, $is_paid_module, $linked_product_id, $modulo_id_edit, $curso_id]); 
                    if (empty($mensagem)) {
                        $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Módulo atualizado!</div>";
                    }
                } else if (!empty($_POST['remove_imagem_capa_modulo'])) { // Lógica para remover a imagem de capa
                    if ($old_module['imagem_capa_url'] && file_exists($old_module['imagem_capa_url']) && strpos($old_module['imagem_capa_url'], 'uploads/') === 0) {
                        unlink($old_module['imagem_capa_url']);
                    }
                    $stmt = $pdo->prepare("UPDATE modulos SET titulo = ?, imagem_capa_url = NULL, release_days = ?, is_paid_module = ?, linked_product_id = ? WHERE id = ? AND curso_id = ?"); 
                    $stmt->execute([$titulo_modulo_edit, $release_days_edit, $is_paid_module, $linked_product_id, $modulo_id_edit, $curso_id]); 
                    if (empty($mensagem)) {
                        $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Módulo e imagem de capa atualizados!</div>";
                    }
                } else { // Se nenhuma imagem nova foi enviada e não foi pedido para remover, atualiza apenas o título e campos de módulo pago
                    $stmt = $pdo->prepare("UPDATE modulos SET titulo = ?, release_days = ?, is_paid_module = ?, linked_product_id = ? WHERE id = ? AND curso_id = ?"); 
                    $stmt->execute([$titulo_modulo_edit, $release_days_edit, $is_paid_module, $linked_product_id, $modulo_id_edit, $curso_id]); 
                    if (empty($mensagem)) {
                        $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Módulo atualizado!</div>";
                    }
                }
            } else {
                 $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Módulo não encontrado para edição.</div>";
            }
        }

        // Adicionar Aula
        if (isset($_POST['adicionar_aula'])) {
            $should_redirect = true;
            $modulo_id = $_POST['modulo_id'];
            $titulo_aula = trim($_POST['titulo_aula']);
            $url_video = trim($_POST['url_video']); // Pode ser vazio
            $descricao_aula = trim($_POST['descricao_aula']);
            $release_days_aula = (int)($_POST['release_days_aula'] ?? 0);
            $tipo_conteudo = $_POST['tipo_conteudo'] ?? 'video'; // 'video', 'files', 'mixed'

            // Verifica se o módulo realmente pertence a este curso
            $stmt_check_modulo = $pdo->prepare("SELECT id FROM modulos WHERE id = ? AND curso_id = ?");
            $stmt_check_modulo->execute([$modulo_id, $curso_id]);
            if ($stmt_check_modulo->rowCount() === 0) {
                 $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Módulo inválido para este curso.</div>";
            } elseif (empty($titulo_aula)) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>O título da aula é obrigatório.</div>";
            } else {
                // Validações de conteúdo baseadas no tipo
                // NOTE: 'aula_files' will have name[0] as empty if no file is selected.
                $has_new_files = isset($_FILES['aula_files']) && !empty($_FILES['aula_files']['name'][0]);

                if ($tipo_conteudo === 'video' && empty($url_video)) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas de vídeo, a URL do vídeo é obrigatória.</div>";
                } elseif ($tipo_conteudo === 'files' && !$has_new_files) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas de arquivos, pelo menos um arquivo é obrigatório.</div>";
                } elseif ($tipo_conteudo === 'mixed' && empty($url_video) && !$has_new_files) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas mistas, a URL do vídeo e pelo menos um arquivo são obrigatórios.</div>";
                } else {
                    $stmt = $pdo->prepare("INSERT INTO aulas (modulo_id, titulo, url_video, descricao, release_days, tipo_conteudo) VALUES (?, ?, ?, ?, ?, ?)"); 
                    $stmt->execute([$modulo_id, $titulo_aula, $url_video, $descricao_aula, $release_days_aula, $tipo_conteudo]); 
                    $nova_aula_id = $pdo->lastInsertId();

                    // Upload de múltiplos arquivos para a aula (seguro)
                    if ($has_new_files) {
                        // security_helper.php já foi incluído no início
                        
                        // Whitelist de tipos permitidos para arquivos de aula
                        $allowed_aula_types = [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-powerpoint',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'text/plain',
                            'application/zip',
                            'application/x-rar-compressed',
                            'image/jpeg',
                            'image/jpg',
                            'image/png'
                        ];
                        $allowed_aula_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', 'jpg', 'jpeg', 'png'];
                        
                        foreach ($_FILES['aula_files']['name'] as $key => $name) {
                            if ($_FILES['aula_files']['error'][$key] === UPLOAD_ERR_OK) {
                                $file_array = [
                                    'name' => $_FILES['aula_files']['name'][$key],
                                    'type' => $_FILES['aula_files']['type'][$key],
                                    'tmp_name' => $_FILES['aula_files']['tmp_name'][$key],
                                    'error' => $_FILES['aula_files']['error'][$key],
                                    'size' => $_FILES['aula_files']['size'][$key]
                                ];
                                
                                $upload_result = validate_uploaded_file($file_array, $allowed_aula_types, $allowed_aula_extensions, 50 * 1024 * 1024, $aula_files_dir, 'aula_file');
                                
                                if ($upload_result['success']) {
                                    $stmt_insert_file = $pdo->prepare("INSERT INTO aula_arquivos (aula_id, nome_original, nome_salvo, caminho_arquivo, tipo_mime, tamanho_bytes) VALUES (?, ?, ?, ?, ?, ?)");
                                    $stmt_insert_file->execute([
                                        $nova_aula_id,
                                        $name,
                                        basename($upload_result['file_path']),
                                        $upload_result['file_path'],
                                        $file_array['type'],
                                        $file_array['size']
                                    ]);
                                }
                            }
                        }
                    }
                    $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Aula adicionada!</div>";
                }
            }
        }
        
        // Editar Aula
        if (isset($_POST['editar_aula_form'])) {
            $should_redirect = true;
            $aula_id_edit = $_POST['aula_id'];
            $titulo_aula = trim($_POST['titulo_aula']);
            $url_video = trim($_POST['url_video']);
            $descricao_aula = trim($_POST['descricao_aula']);
            $release_days_aula = (int)($_POST['release_days_aula'] ?? 0);
            $tipo_conteudo = $_POST['tipo_conteudo'] ?? 'video';

            // Valida se a aula pertence a um módulo deste curso
            $stmt_check_aula = $pdo->prepare("SELECT a.id FROM aulas a JOIN modulos m ON a.modulo_id = m.id WHERE a.id = ? AND m.curso_id = ?");
            $stmt_check_aula->execute([$aula_id_edit, $curso_id]);

            if ($stmt_check_aula->rowCount() === 0) {
                 $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Aula não encontrada ou não pertence a este curso.</div>";
            } elseif (empty($titulo_aula)) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>O título da aula é obrigatório.</div>";
            } else {
                // Validações de conteúdo baseadas no tipo
                $has_new_files = isset($_FILES['aula_files']) && !empty($_FILES['aula_files']['name'][0]);
                $has_existing_files_to_keep = !empty($_POST['existing_files']);

                if ($tipo_conteudo === 'video' && empty($url_video)) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas de vídeo, a URL do vídeo é obrigatória.</div>";
                } elseif ($tipo_conteudo === 'files' && !$has_new_files && !$has_existing_files_to_keep) {
                    // Se o tipo é 'files' e não há arquivos novos e nem existentes marcados, erro.
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas de arquivos, pelo menos um arquivo é obrigatório.</div>";
                } elseif ($tipo_conteudo === 'mixed' && empty($url_video) && !$has_new_files && !$has_existing_files_to_keep) {
                    // Se o tipo é 'mixed' e não há vídeo, nem arquivos novos, nem existentes.
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas mistas, a URL do vídeo e pelo menos um arquivo são obrigatórios.</div>";
                } else {
                    $stmt = $pdo->prepare("UPDATE aulas SET titulo = ?, url_video = ?, descricao = ?, release_days = ?, tipo_conteudo = ? WHERE id = ?");
                    $stmt->execute([$titulo_aula, $url_video, $descricao_aula, $release_days_aula, $tipo_conteudo, $aula_id_edit]);

                    // Gerenciar arquivos existentes (deletar)
                    $existing_files_to_keep = $_POST['existing_files'] ?? [];
                    // Busca todos os arquivos da aula
                    $stmt_all_files = $pdo->prepare("SELECT id, caminho_arquivo FROM aula_arquivos WHERE aula_id = ?");
                    $stmt_all_files->execute([$aula_id_edit]);
                    $all_files = $stmt_all_files->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($all_files as $file) {
                        if (!in_array($file['id'], $existing_files_to_keep)) {
                            // Deleta o arquivo do sistema de arquivos
                            if (file_exists($file['caminho_arquivo'])) {
                                unlink($file['caminho_arquivo']);
                            }
                            // Deleta o registro do banco de dados
                            $stmt_delete_file = $pdo->prepare("DELETE FROM aula_arquivos WHERE id = ?");
                            $stmt_delete_file->execute([$file['id']]);
                        }
                    }

                    // Upload de novos arquivos (seguro)
                    if ($has_new_files) {
                        // security_helper.php já foi incluído no início
                        
                        // Whitelist de tipos permitidos para arquivos de aula
                        $allowed_aula_types = [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-powerpoint',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'text/plain',
                            'application/zip',
                            'application/x-rar-compressed'
                        ];
                        $allowed_aula_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar'];
                        
                        foreach ($_FILES['aula_files']['name'] as $key => $name) {
                            if ($_FILES['aula_files']['error'][$key] === UPLOAD_ERR_OK) {
                                $file_array = [
                                    'name' => $_FILES['aula_files']['name'][$key],
                                    'type' => $_FILES['aula_files']['type'][$key],
                                    'tmp_name' => $_FILES['aula_files']['tmp_name'][$key],
                                    'error' => $_FILES['aula_files']['error'][$key],
                                    'size' => $_FILES['aula_files']['size'][$key]
                                ];
                                
                                $upload_result = validate_uploaded_file($file_array, $allowed_aula_types, $allowed_aula_extensions, 50 * 1024 * 1024, $aula_files_dir, 'aula_file');
                                
                                if ($upload_result['success']) {
                                    $stmt_insert_file = $pdo->prepare("INSERT INTO aula_arquivos (aula_id, nome_original, nome_salvo, caminho_arquivo, tipo_mime, tamanho_bytes) VALUES (?, ?, ?, ?, ?, ?)");
                                    $stmt_insert_file->execute([
                                        $aula_id_edit,
                                        $name,
                                        basename($upload_result['file_path']),
                                        $upload_result['file_path'],
                                        $file_array['type'],
                                        $file_array['size']
                                    ]);
                                }
                            }
                        }
                    }
                    $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Aula atualizada!</div>";
                }
            }
        }

        // Deletar Módulo
        if (isset($_POST['deletar_modulo'])) {
            $should_redirect = true;
            $modulo_id_del = $_POST['modulo_id'];

            // Primeiro, verifica se o módulo pertence a este curso antes de deletar
            $stmt_check_modulo = $pdo->prepare("SELECT imagem_capa_url FROM modulos WHERE id = ? AND curso_id = ?");
            $stmt_check_modulo->execute([$modulo_id_del, $curso_id]);
            $module_to_delete = $stmt_check_modulo->fetch(PDO::FETCH_ASSOC);

            if ($module_to_delete) {
                // Deleta a imagem de capa se existir
                if ($module_to_delete['imagem_capa_url'] && file_exists($module_to_delete['imagem_capa_url']) && strpos($module_to_delete['imagem_capa_url'], 'uploads/') === 0) {
                    unlink($module_to_delete['imagem_capa_url']);
                }
                
                // Antes de deletar o módulo, precisamos deletar os arquivos das aulas para evitar órfãos
                $stmt_get_aula_files = $pdo->prepare("
                    SELECT af.caminho_arquivo 
                    FROM aula_arquivos af
                    JOIN aulas a ON af.aula_id = a.id
                    WHERE a.modulo_id = ?
                ");
                $stmt_get_aula_files->execute([$modulo_id_del]);
                $files_to_delete = $stmt_get_aula_files->fetchAll(PDO::FETCH_COLUMN);

                foreach ($files_to_delete as $file_path) {
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }

                // Deleta o módulo (e suas aulas em cascata devido à FOREIGN KEY ON DELETE CASCADE)
                $stmt = $pdo->prepare("DELETE FROM modulos WHERE id = ? AND curso_id = ?");
                $stmt->execute([$modulo_id_del, $curso_id]);
                $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Módulo e suas aulas foram deletados.</div>";
            } else {
                 $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Módulo não encontrado ou não pertence a este curso.</div>";
            }
        }
        
        // Deletar Aula
        if (isset($_POST['deletar_aula'])) {
            $should_redirect = true;
            $aula_id_del = $_POST['aula_id'];

            // Verifica se a aula pertence a um módulo deste curso
            $stmt_check_aula = $pdo->prepare("
                SELECT a.id, af.caminho_arquivo 
                FROM aulas a 
                JOIN modulos m ON a.modulo_id = m.id 
                LEFT JOIN aula_arquivos af ON a.id = af.aula_id
                WHERE a.id = ? AND m.curso_id = ?
            ");
            $stmt_check_aula->execute([$aula_id_del, $curso_id]);
            $aula_files_to_delete = $stmt_check_aula->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($aula_files_to_delete)) {
                foreach ($aula_files_to_delete as $file_info) {
                    if ($file_info['caminho_arquivo'] && file_exists($file_info['caminho_arquivo'])) {
                        unlink($file_info['caminho_arquivo']);
                    }
                }
                // Deleta a aula (e seus arquivos em cascata se a FK estiver configurada)
                $stmt = $pdo->prepare("DELETE FROM aulas WHERE id = ?");
                $stmt->execute([$aula_id_del]);
                $mensagem = "<div class='bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded' role='alert'>Aula deletada.</div>";
            } else {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Aula não encontrada ou não pertence a este curso.</div>";
            }
        }
        
        // CORREÇÃO: Lógica de redirecionamento centralizada
        if ($should_redirect) {
            $_SESSION['flash_message'] = $mensagem;
            // AQUI ESTÁ A CORREÇÃO: Garantir que o redirecionamento inclua a página correta no index
            header("Location: /index?pagina=gerenciar_curso&produto_id=" . $produto_id);
            exit;
        }
    }
    
    // Pega a mensagem da sessão, se houver, e depois limpa
    if (isset($_SESSION['flash_message'])) {
        $mensagem = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
    }

    // 5. Buscar todos os produtos do infoprodutor para módulos pagos
    $stmt_produtos = $pdo->prepare("SELECT id, nome, preco FROM produtos WHERE usuario_id = ? ORDER BY nome ASC");
    $stmt_produtos->execute([$usuario_id_logado]);
    $produtos_disponiveis = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

    // 6. Buscar todos os módulos e aulas para exibição
    // NEW: Include release_days, is_paid_module, linked_product_id in SELECT for modulos
    $stmt_modulos = $pdo->prepare("SELECT id, curso_id, titulo, imagem_capa_url, ordem, release_days, is_paid_module, linked_product_id FROM modulos WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
    $stmt_modulos->execute([$curso_id]);
    $modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($modulos as $modulo) {
        // NEW: Buscar informações do produto atrelado se for módulo pago
        $produto_atrelado = null;
        if ($modulo['is_paid_module'] && $modulo['linked_product_id']) {
            $stmt_prod_atrelado = $pdo->prepare("SELECT id, nome, preco FROM produtos WHERE id = ? AND usuario_id = ?");
            $stmt_prod_atrelado->execute([$modulo['linked_product_id'], $usuario_id_logado]);
            $produto_atrelado = $stmt_prod_atrelado->fetch(PDO::FETCH_ASSOC);
        }
        $modulo['produto_atrelado'] = $produto_atrelado;
        
        // NEW: Include release_days and tipo_conteudo in SELECT for aulas
        $stmt_aulas = $pdo->prepare("SELECT id, modulo_id, titulo, url_video, descricao, ordem, release_days, tipo_conteudo FROM aulas WHERE modulo_id = ? ORDER BY ordem ASC, id ASC");
        $stmt_aulas->execute([$modulo['id']]);
        $aulas = $stmt_aulas->fetchAll(PDO::FETCH_ASSOC);

        // Fetch files for each lesson
        foreach ($aulas as &$aula) {
            $stmt_files = $pdo->prepare("SELECT id, nome_original, caminho_arquivo FROM aula_arquivos WHERE aula_id = ? ORDER BY ordem ASC, id ASC");
            $stmt_files->execute([$aula['id']]);
            $aula['files'] = $stmt_files->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($aula); // Break the reference

        $modulos_com_aulas[] = [
            'modulo' => $modulo,
            'aulas' => $aulas
        ];
    }

} catch (PDOException $e) {
    $mensagem = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded' role='alert'>Erro de banco de dados: " . htmlspecialchars($e->getMessage()) . "</div>";
}

?>

<?php
// Gerar token CSRF para uso nos formulários
$csrf_token = generate_csrf_token();
?>

<div class="container mx-auto">
    <div class="flex items-center mb-6">
        <a href="/index?pagina=area_membros" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="mr-4">
            <i data-lucide="arrow-left-circle" class="w-8 h-8"></i>
        </a>
        <div>
            <h1 class="text-3xl font-bold text-white">Gerenciar Conteúdo</h1>
            <p class="text-gray-400">Curso: <?php echo htmlspecialchars($curso['titulo'] ?? 'Carregando...'); ?></p>
        </div>
    </div>

    <?php if ($mensagem) echo "<div class='mb-6'>$mensagem</div>"; ?>

    <!-- Personalizar Aparência do Curso -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold mb-4 text-white">Personalizar Aparência do Curso</h2>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="mb-4">
                <label for="banner_curso" class="block text-gray-300 text-sm font-semibold mb-2">Banner do Topo</label>
                <?php if (!empty($curso['banner_url']) && file_exists($curso['banner_url'])): ?>
                    <div class="mb-2">
                        <img src="<?php echo htmlspecialchars($curso['banner_url']); ?>" alt="Banner atual" class="w-full h-48 object-cover rounded-lg border border-dark-border">
                        <label class="mt-2 flex items-center text-sm text-gray-400">
                            <input type="checkbox" name="remove_banner" value="1" class="h-4 w-4 mr-1 text-red-400 focus:ring-red-500 rounded"> Remover banner existente
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" id="banner_curso" name="banner_curso" class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold" style="--file-bg: color-mix(in srgb, var(--accent-primary) 20%, transparent); --file-text: var(--accent-primary);" onmouseover="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 30%, transparent)')" onmouseout="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 20%, transparent)')" accept="image/*">
                <p class="mt-1 text-xs text-gray-400">Recomendado: 1920x400px</p>
            </div>
            <button type="submit" name="salvar_banner_curso" class="text-white font-bold py-2 px-5 rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Banner</button>
        </form>
    </div>

    <!-- Adicionar Novo Módulo -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold mb-4 text-white">Adicionar Novo Módulo</h2>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" class="space-y-4"> <!-- Changed to space-y-4 for vertical stacking -->
            <?php
            if (!isset($csrf_token)) {
                $csrf_token = generate_csrf_token();
            }
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div>
                <label for="titulo_modulo_add" class="block text-gray-300 text-sm font-semibold mb-2">Título do Módulo</label>
                <input type="text" id="titulo_modulo_add" name="titulo_modulo" placeholder="Ex: Módulo 1 - Introdução" required class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
            </div>
            <!-- NEW: Release Days for Add Module -->
            <div>
                <label for="release_days_modulo_add" class="block text-gray-300 text-sm font-semibold mb-2">Liberar após (dias)</label>
                <input type="number" id="release_days_modulo_add" name="release_days_modulo" value="0" min="0" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="0 = Liberação imediata">
                <p class="mt-1 text-xs text-gray-400">Defina quantos dias após a compra do curso este módulo será liberado para o aluno.</p>
            </div>
            <!-- NEW: Módulo Pago -->
            <div>
                <label class="flex items-center gap-2 mb-2">
                    <input type="checkbox" id="is_paid_module_add" name="is_paid_module" value="1" class="form-checkbox" onchange="togglePaidModuleFields('add')">
                    <span class="text-gray-300 text-sm font-semibold">Módulo Pago</span>
                </label>
                <p class="mt-1 text-xs text-gray-400">Marque esta opção se este módulo requer a compra de um produto adicional.</p>
            </div>
            <!-- NEW: Campos condicionais para módulo pago -->
            <div id="paid_module_fields_add" class="hidden space-y-3">
                <div>
                    <label for="linked_product_id_add" class="block text-gray-300 text-sm font-semibold mb-2">Produto Atrelado</label>
                    <select id="linked_product_id_add" name="linked_product_id" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
                        <option value="">Selecione um produto...</option>
                        <?php if (empty($produtos_disponiveis)): ?>
                            <option value="" disabled>Nenhum produto disponível</option>
                        <?php else: ?>
                            <?php foreach ($produtos_disponiveis as $prod): ?>
                                <option value="<?php echo $prod['id']; ?>"><?php echo htmlspecialchars($prod['nome']); ?> - R$ <?php echo number_format($prod['preco'], 2, ',', '.'); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <p class="mt-1 text-xs text-gray-400">Selecione o produto que deve ser comprado para acessar este módulo.</p>
                    <?php if (empty($produtos_disponiveis)): ?>
                        <p class="mt-1 text-xs text-red-400">Você precisa criar pelo menos um produto antes de criar um módulo pago.</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex justify-end">
                <button type="submit" name="adicionar_modulo" class="text-white font-bold py-3 px-6 rounded-lg transition duration-300 flex items-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="plus" class="w-5 h-5"></i>
                    <span>Adicionar Módulo</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Listagem de Módulos e Aulas -->
    <div class="space-y-6">
        <?php if (empty($modulos_com_aulas)): ?>
            <div class="bg-dark-card p-8 rounded-lg shadow-md text-center text-gray-400 border border-[#32e768]">
                <i data-lucide="folder-open" class="mx-auto w-16 h-16 text-gray-500"></i>
                <p class="mt-4">Nenhum módulo foi criado para este curso ainda.</p>
                <p>Use o formulário acima para começar.</p>
            </div>
        <?php else: ?>
            <?php foreach ($modulos_com_aulas as $item): ?>
                <div class="bg-dark-card rounded-lg shadow-md overflow-hidden border border-dark-border">
                    <div class="bg-dark-elevated p-4 flex justify-between items-center border-b border-dark-border">
                        <div class="flex items-center gap-4">
                            <?php if (!empty($item['modulo']['imagem_capa_url']) && file_exists($item['modulo']['imagem_capa_url'])): ?>
                                <img src="<?php echo htmlspecialchars($item['modulo']['imagem_capa_url']); ?>" alt="Capa do módulo" class="w-24 h-16 object-cover rounded-md border border-dark-border">
                            <?php else: ?>
                                <div class="w-24 h-16 bg-dark-card rounded-md flex items-center justify-center border border-dark-border">
                                    <i data-lucide="image-off" class="w-8 h-8 text-gray-500"></i>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h3 class="text-xl font-bold text-white">
                                    <?php echo htmlspecialchars($item['modulo']['titulo']); ?>
                                    <?php if ($item['modulo']['release_days'] > 0): ?>
                                        <span class="ml-2 text-sm font-medium" style="color: var(--accent-primary);">(Liberado em <?php echo $item['modulo']['release_days']; ?> dias)</span>
                                    <?php endif; ?>
                                </h3>
                                <?php if ($item['modulo']['is_paid_module'] && $item['modulo']['produto_atrelado']): ?>
                                    <div class="mt-1 flex items-center gap-2">
                                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold" style="background-color: var(--accent-primary); color: white;">
                                            <i data-lucide="dollar-sign" class="w-3 h-3 mr-1"></i>
                                            Módulo Pago
                                        </span>
                                        <span class="text-xs text-gray-400">
                                            Produto: <?php echo htmlspecialchars($item['modulo']['produto_atrelado']['nome']); ?> - R$ <?php echo number_format($item['modulo']['produto_atrelado']['preco'], 2, ',', '.'); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex items-center space-x-2">
                             <button class="add-lesson-btn text-sm text-white font-semibold py-2 px-4 rounded-lg transition flex items-center space-x-1" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'" data-modulo-id="<?php echo $item['modulo']['id']; ?>" data-modulo-titulo="<?php echo htmlspecialchars($item['modulo']['titulo']); ?>">
                                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                                <span>Nova Aula</span>
                            </button>
                            <button class="edit-module-btn p-2 rounded-lg bg-yellow-500 text-white hover:bg-yellow-600 transition"
                                data-modulo-id="<?php echo $item['modulo']['id']; ?>"
                                data-modulo-titulo="<?php echo htmlspecialchars($item['modulo']['titulo']); ?>"
                                data-imagem-url="<?php echo htmlspecialchars($item['modulo']['imagem_capa_url'] ?? ''); ?>"
                                data-release-days="<?php echo htmlspecialchars($item['modulo']['release_days']); ?>"
                                data-is-paid-module="<?php echo $item['modulo']['is_paid_module'] ? '1' : '0'; ?>"
                                data-linked-product-id="<?php echo htmlspecialchars($item['modulo']['linked_product_id'] ?? ''); ?>">
                                <i data-lucide="edit" class="w-5 h-5"></i>
                            </button>
                            <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" onsubmit="return confirm('Tem certeza que deseja deletar este módulo e todas as suas aulas?');">
                                <?php
                                if (!isset($csrf_token)) {
                                    $csrf_token = generate_csrf_token();
                                }
                                ?>
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="modulo_id" value="<?php echo $item['modulo']['id']; ?>">
                                <button type="submit" name="deletar_modulo" class="text-white bg-red-500 p-2 rounded-lg hover:bg-red-600 transition">
                                    <i data-lucide="trash-2" class="w-5 h-5"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <div class="p-4">
                        <?php if (empty($item['aulas'])): ?>
                            <p class="text-gray-400 text-center py-4">Nenhuma aula neste módulo.</p>
                        <?php else: ?>
                            <ul class="space-y-3 sortable-aulas" data-modulo-id="<?php echo $item['modulo']['id']; ?>">
                                <?php foreach ($item['aulas'] as $aula): ?>
                                    <li class="flex justify-between items-center p-3 bg-dark-elevated rounded-md border border-dark-border hover:bg-dark-card aula-item" data-aula-id="<?php echo $aula['id']; ?>">
                                        <div class="flex items-center space-x-3 cursor-grab">
                                            <i data-lucide="grip-vertical" class="w-5 h-5 text-gray-500 flex-shrink-0"></i>
                                            <?php if ($aula['tipo_conteudo'] === 'video' || $aula['tipo_conteudo'] === 'mixed'): ?>
                                                <i data-lucide="play-circle" class="w-5 h-5 text-gray-400 flex-shrink-0"></i>
                                            <?php endif; ?>
                                            <?php if ($aula['tipo_conteudo'] === 'files' || $aula['tipo_conteudo'] === 'mixed'): ?>
                                                <i data-lucide="file-text" class="w-5 h-5 text-gray-400 flex-shrink-0"></i>
                                            <?php endif; ?>
                                            <span class="font-medium text-gray-300">
                                                <?php echo htmlspecialchars($aula['titulo']); ?>
                                                <?php if ($aula['release_days'] > 0): ?>
                                                    <span class="ml-2 text-sm font-medium" style="color: var(--accent-primary);">(Liberada em <?php echo $aula['release_days']; ?> dias)</span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                        <div class="flex items-center space-x-2 flex-shrink-0">
                                            <button class="edit-lesson-btn text-blue-400 hover:text-blue-300 p-1 rounded-full"
                                                data-aula-id="<?php echo $aula['id']; ?>"
                                                data-titulo="<?php echo htmlspecialchars($aula['titulo']); ?>"
                                                data-url-video="<?php echo htmlspecialchars($aula['url_video'] ?? ''); ?>"
                                                data-descricao="<?php echo htmlspecialchars($aula['descricao'] ?? ''); ?>"
                                                data-release-days="<?php echo htmlspecialchars($aula['release_days']); ?>"
                                                data-tipo-conteudo="<?php echo htmlspecialchars($aula['tipo_conteudo']); ?>"
                                                data-files='<?php echo json_encode($aula['files']); ?>'>
                                                <i data-lucide="edit" class="w-5 h-5"></i>
                                            </button>
                                            <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" onsubmit="return confirm('Tem certeza que deseja deletar esta aula?');" class="inline-block">
                                                <?php
                                                if (!isset($csrf_token)) {
                                                    $csrf_token = generate_csrf_token();
                                                }
                                                ?>
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="aula_id" value="<?php echo $aula['id']; ?>">
                                                <button type="submit" name="deletar_aula" class="text-red-400 hover:text-red-300 p-1 rounded-full">
                                                    <i data-lucide="x-circle" class="w-5 h-5"></i>
                                                </button>
                                            </form>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal para Adicionar Aula -->
<div id="add-lesson-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden overflow-y-auto">
    <div class="bg-dark-card rounded-xl shadow-2xl w-full max-w-4xl h-[90vh] max-h-[90vh] transform transition-all opacity-0 scale-95 border border-[#32e768] flex flex-col my-4" id="add-lesson-modal-content">
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="flex flex-col h-full min-h-0">
            <?php
            if (!isset($csrf_token)) {
                $csrf_token = generate_csrf_token();
            }
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="p-6 border-b border-dark-border flex-shrink-0"><h2 class="text-2xl font-bold text-white">Adicionar Nova Aula em <span id="modal-modulo-titulo-add" style="color: var(--accent-primary);"></span></h2></div>
            <div class="p-6 space-y-4 overflow-y-auto flex-1 min-h-0">
                <input type="hidden" name="modulo_id" id="modal-modulo-id-add">
                <div><label for="add_titulo_aula" class="block text-gray-300 text-sm font-semibold mb-2">Título da Aula</label><input type="text" id="add_titulo_aula" name="titulo_aula" required class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Ex: Aula 1 - Bem-vindo ao curso"></div>
                
                <!-- Tipo de Conteúdo da Aula (Add) -->
                <div>
                    <label for="add_tipo_conteudo" class="block text-gray-300 text-sm font-semibold mb-2">Tipo de Conteúdo</label>
                    <select id="add_tipo_conteudo" name="tipo_conteudo" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
                        <option value="video">Somente Vídeo</option>
                        <option value="files">Somente Arquivos</option>
                        <option value="mixed">Vídeo e Arquivos</option>
                    </select>
                </div>

                <!-- URL do Vídeo (Add) -->
                <div id="add-video-url-container">
                    <label for="add_url_video" class="block text-gray-300 text-sm font-semibold mb-2">URL do Vídeo (YouTube)</label>
                    <input type="url" id="add_url_video" name="url_video" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="https://www.youtube.com/watch?v=...">
                </div>

                <!-- Upload de Arquivos (Add) -->
                <div id="add-files-upload-container">
                    <label for="add_aula_files" class="block text-gray-300 text-sm font-semibold mb-2">Upload de Arquivos</label>
                    <input type="file" id="add_aula_files" name="aula_files[]" multiple class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold" style="--file-bg: color-mix(in srgb, var(--accent-primary) 20%, transparent); --file-text: var(--accent-primary);" onmouseover="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 30%, transparent)')" onmouseout="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 20%, transparent)')">
                    <p class="mt-1 text-xs text-gray-400">Múltiplos arquivos (PDF, imagens, zip, etc.)</p>
                </div>

                <div><label for="add_descricao_aula" class="block text-gray-300 text-sm font-semibold mb-2">Descrição / Materiais</label><textarea id="add_descricao_aula" name="descricao_aula" rows="5" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Links, textos de apoio, etc."></textarea></div>
                <!-- Release Days for Add Lesson -->
                <div>
                    <label for="add_release_days_aula" class="block text-gray-300 text-sm font-semibold mb-2">Liberar após (dias)</label>
                    <input type="number" id="add_release_days_aula" name="release_days_aula" value="0" min="0" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="0 = Liberação imediata">
                    <p class="mt-1 text-xs text-gray-400">Defina quantos dias após a compra do curso esta aula será liberada para o aluno.</p>
                </div>
            </div>
            <div class="px-6 py-4 bg-dark-elevated rounded-b-xl flex justify-end items-center space-x-4 border-t border-dark-border flex-shrink-0">
                <button type="button" class="modal-cancel-btn bg-dark-card text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-elevated border border-dark-border">Cancelar</button>
                <button type="submit" name="adicionar_aula" class="text-white font-bold py-2 px-5 rounded-lg" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Aula</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para Editar Aula -->
<div id="edit-lesson-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden overflow-y-auto">
    <div class="bg-dark-card rounded-xl shadow-2xl w-full max-w-4xl h-[90vh] max-h-[90vh] transform transition-all opacity-0 scale-95 border border-[#32e768] flex flex-col my-4" id="edit-lesson-modal-content">
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="flex flex-col h-full min-h-0">
            <?php
            if (!isset($csrf_token)) {
                $csrf_token = generate_csrf_token();
            }
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="p-6 border-b border-dark-border flex-shrink-0"><h2 class="text-2xl font-bold text-white">Editar Aula</h2></div>
            <div class="p-6 space-y-4 overflow-y-auto flex-1 min-h-0">
                <input type="hidden" name="aula_id" id="edit_aula_id">
                <div><label for="edit_titulo_aula" class="block text-gray-300 text-sm font-semibold mb-2">Título da Aula</label><input type="text" id="edit_titulo_aula" name="titulo_aula" required class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Ex: Aula 1 - Bem-vindo ao curso"></div>

                <!-- Tipo de Conteúdo da Aula (Edit) -->
                <div>
                    <label for="edit_tipo_conteudo" class="block text-gray-300 text-sm font-semibold mb-2">Tipo de Conteúdo</label>
                    <select id="edit_tipo_conteudo" name="tipo_conteudo" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
                        <option value="video">Somente Vídeo</option>
                        <option value="files">Somente Arquivos</option>
                        <option value="mixed">Vídeo e Arquivos</option>
                    </select>
                </div>

                <!-- URL do Vídeo (Edit) -->
                <div id="edit-video-url-container">
                    <label for="edit_url_video" class="block text-gray-300 text-sm font-semibold mb-2">URL do Vídeo (YouTube)</label>
                    <input type="url" id="edit_url_video" name="url_video" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="https://www.youtube.com/watch?v=...">
                </div>

                <!-- Arquivos Existentes (Edit) -->
                <div id="edit-existing-files-container" class="space-y-2">
                    <p class="block text-gray-300 text-sm font-semibold mb-2">Arquivos Atuais:</p>
                    <div id="existing-files-list">
                        <!-- Arquivos serão carregados aqui via JS -->
                    </div>
                </div>

                <!-- Upload de Novos Arquivos (Edit) -->
                <div id="edit-new-files-upload-container">
                    <label for="edit_aula_files" class="block text-gray-300 text-sm font-semibold mb-2">Upload de Novos Arquivos</label>
                    <input type="file" id="edit_aula_files" name="aula_files[]" multiple class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold" style="--file-bg: color-mix(in srgb, var(--accent-primary) 20%, transparent); --file-text: var(--accent-primary);" onmouseover="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 30%, transparent)')" onmouseout="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 20%, transparent)')">
                    <p class="mt-1 text-xs text-gray-400">Múltiplos arquivos (PDF, imagens, zip, etc.)</p>
                </div>

                <div><label for="edit_descricao_aula" class="block text-gray-300 text-sm font-semibold mb-2">Descrição / Materiais</label><textarea id="edit_descricao_aula" name="descricao_aula" rows="5" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Links, textos de apoio, etc."></textarea></div>
                <!-- Release Days for Edit Lesson -->
                <div>
                    <label for="edit_release_days_aula" class="block text-gray-300 text-sm font-semibold mb-2">Liberar após (dias)</label>
                    <input type="number" id="edit_release_days_aula" name="release_days_aula" value="0" min="0" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="0 = Liberação imediata">
                    <p class="mt-1 text-xs text-gray-400">Defina quantos dias após a compra do curso esta aula será liberada para o aluno.</p>
                </div>
            </div>
            <div class="px-6 py-4 bg-dark-elevated rounded-b-xl flex justify-end items-center space-x-4 border-t border-dark-border flex-shrink-0">
                <button type="button" class="modal-cancel-btn bg-dark-card text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-elevated border border-dark-border">Cancelar</button>
                <button type="submit" name="editar_aula_form" class="text-white font-bold py-2 px-5 rounded-lg" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para Editar Módulo -->
<div id="edit-module-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-dark-card rounded-xl shadow-2xl w-full max-w-2xl transform transition-all opacity-0 scale-95" style="border-color: var(--accent-primary);" id="edit-module-modal-content">
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data">
            <?php
            if (!isset($csrf_token)) {
                $csrf_token = generate_csrf_token();
            }
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="p-6 border-b border-dark-border"><h2 class="text-2xl font-bold text-white">Editar Módulo</h2></div>
            <div class="p-6 space-y-4">
                <input type="hidden" name="modulo_id" id="modal-modulo-id-edit">
                <div><label for="modal-titulo-modulo-edit" class="block text-gray-300 text-sm font-semibold mb-2">Título do Módulo</label><input type="text" id="modal-titulo-modulo-edit" name="titulo_modulo" required class="form-input-style"></div>
                <div>
                    <label for="imagem_capa_modulo" class="block text-gray-300 text-sm font-semibold mb-2">Imagem de Capa do Módulo</label>
                    <img id="modal-imagem-preview" src="" alt="Preview da imagem" class="w-48 h-auto object-cover rounded-lg border border-dark-border mb-2 hidden">
                    <input type="file" id="imagem_capa_modulo" name="imagem_capa_modulo" class="w-full text-sm text-gray-300 bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:cursor-pointer cursor-pointer" style="--file-bg: color-mix(in srgb, var(--accent-primary) 20%, transparent); --file-text: var(--accent-primary);" onmouseover="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 30%, transparent)')" onmouseout="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 20%, transparent)')" accept="image/*">
                    <label class="mt-2 flex items-center text-sm text-gray-400">
                        <input type="checkbox" name="remove_imagem_capa_modulo" value="1" id="remove_imagem_capa_modulo" class="h-4 w-4 mr-1 bg-dark-elevated border-dark-border rounded cursor-pointer" style="color: var(--accent-primary); --tw-ring-color: var(--accent-primary);"> Remover imagem de capa existente
                    </label>
                </div>
                <!-- Release Days for Edit Module -->
                <div>
                    <label for="modal-release-days-modulo-edit" class="block text-gray-300 text-sm font-semibold mb-2">Liberar após (dias)</label>
                    <input type="number" id="modal-release-days-modulo-edit" name="release_days_modulo" value="0" min="0" class="form-input-style" placeholder="0 = Liberação imediata">
                    <p class="mt-1 text-xs text-gray-400">Defina quantos dias após a compra do curso este módulo será liberado para o aluno.</p>
                </div>
                <!-- NEW: Módulo Pago (Edit) -->
                <div>
                    <label class="flex items-center gap-2 mb-2">
                        <input type="checkbox" id="is_paid_module_edit" name="is_paid_module" value="1" class="form-checkbox" onchange="togglePaidModuleFields('edit')">
                        <span class="text-gray-300 text-sm font-semibold">Módulo Pago</span>
                    </label>
                    <p class="mt-1 text-xs text-gray-400">Marque esta opção se este módulo requer a compra de um produto adicional.</p>
                </div>
                <!-- NEW: Campos condicionais para módulo pago (Edit) -->
                <div id="paid_module_fields_edit" class="hidden space-y-3">
                    <div>
                        <label for="linked_product_id_edit" class="block text-gray-300 text-sm font-semibold mb-2">Produto Atrelado</label>
                        <select id="linked_product_id_edit" name="linked_product_id" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
                            <option value="">Selecione um produto...</option>
                            <?php if (empty($produtos_disponiveis)): ?>
                                <option value="" disabled>Nenhum produto disponível</option>
                            <?php else: ?>
                                <?php foreach ($produtos_disponiveis as $prod): ?>
                                    <option value="<?php echo $prod['id']; ?>"><?php echo htmlspecialchars($prod['nome']); ?> - R$ <?php echo number_format($prod['preco'], 2, ',', '.'); ?></option>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </select>
                        <p class="mt-1 text-xs text-gray-400">Selecione o produto que deve ser comprado para acessar este módulo.</p>
                        <?php if (empty($produtos_disponiveis)): ?>
                            <p class="mt-1 text-xs text-red-400">Você precisa criar pelo menos um produto antes de criar um módulo pago.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-dark-elevated rounded-b-xl flex justify-end items-center space-x-4 border-t border-dark-border">
                <button type="button" class="modal-cancel-btn bg-dark-card text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-elevated border border-dark-border">Cancelar</button>
                <button type="submit" name="editar_modulo" class="text-white font-bold py-2 px-5 rounded-lg" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<style>
.form-input-style { 
    @apply w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white; 
    --tw-ring-color: var(--accent-primary);
}
.form-input-style:focus {
    border-color: var(--accent-primary);
}
.sortable-ghost { 
    opacity: 0.4; 
    background: color-mix(in srgb, var(--accent-primary) 20%, transparent); 
} /* Estilo para o item sendo arrastado */

/* Garantir inputs dark theme nos modais */
#add-lesson-modal input[type="text"],
#add-lesson-modal input[type="url"],
#add-lesson-modal input[type="number"],
#add-lesson-modal textarea,
#add-lesson-modal select,
#edit-lesson-modal input[type="text"],
#edit-lesson-modal input[type="url"],
#edit-lesson-modal input[type="number"],
#edit-lesson-modal textarea,
#edit-lesson-modal select,
#edit-module-modal input[type="text"],
#edit-module-modal input[type="number"],
#edit-module-modal input[type="file"] {
    background-color: #0f1419 !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
    color: #ffffff !important;
}

#edit-module-modal input[type="checkbox"] {
    background-color: #0f1419 !important;
    border-color: rgba(255, 255, 255, 0.1) !important;
    accent-color: #32e768 !important;
}

#add-lesson-modal input[type="text"]:focus,
#add-lesson-modal input[type="url"]:focus,
#add-lesson-modal input[type="number"]:focus,
#add-lesson-modal textarea:focus,
#add-lesson-modal select:focus,
#edit-lesson-modal input[type="text"]:focus,
#edit-lesson-modal input[type="url"]:focus,
#edit-lesson-modal input[type="number"]:focus,
#edit-lesson-modal textarea:focus,
#edit-lesson-modal select:focus {
    border-color: #32e768 !important;
    ring-color: #32e768 !important;
}

#add-lesson-modal select option,
#edit-lesson-modal select option {
    background-color: #0f1419 !important;
    color: #ffffff !important;
}
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    const currentProductId = <?php echo $produto_id; ?>;

    // --- Lógica genérica para Modais ---
    function openModal(modal) {
        modal.classList.remove('hidden');
        setTimeout(() => {
            const content = modal.querySelector('.transform');
            if (content) content.classList.remove('opacity-0', 'scale-95');
        }, 10);
    }

    function closeModal(modal) {
        const content = modal.querySelector('.transform');
        if (content) content.classList.add('opacity-0', 'scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            const form = modal.querySelector('form');
            if (form) form.reset();
        }, 200);
    }

    document.querySelectorAll('.modal-cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.closest('.fixed')));
    });

    document.querySelectorAll('.fixed[id$="-modal"]').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal(modal);
        });
    });

    // --- Lógica para Modal de Adicionar Aula ---
    const addLessonModal = document.getElementById('add-lesson-modal');
    const addTipoConteudoSelect = document.getElementById('add_tipo_conteudo');
    const addVideoUrlContainer = document.getElementById('add-video-url-container');
    const addAulaFilesContainer = document.getElementById('add-files-upload-container');
    const addUrlVideoInput = document.getElementById('add_url_video');
    const addAulaFilesInput = document.getElementById('add_aula_files');

    function toggleAddLessonFields() {
        const selectedType = addTipoConteudoSelect.value;
        addUrlVideoInput.required = false;
        addAulaFilesInput.required = false;

        addVideoUrlContainer.style.display = 'none';
        addAulaFilesContainer.style.display = 'none';

        if (selectedType === 'video' || selectedType === 'mixed') {
            addVideoUrlContainer.style.display = 'block';
            addUrlVideoInput.required = true;
        }
        if (selectedType === 'files' || selectedType === 'mixed') {
            addAulaFilesContainer.style.display = 'block';
            addAulaFilesInput.required = true; // Always require new files for 'add' if type is files/mixed
        }
    }

    addTipoConteudoSelect.addEventListener('change', toggleAddLessonFields);


    document.querySelectorAll('.add-lesson-btn').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('modal-modulo-id-add').value = this.dataset.moduloId;
            document.getElementById('modal-modulo-titulo-add').textContent = this.dataset.moduloTitulo;
            document.getElementById('add_release_days_aula').value = 0; // Reset to 0 when opening for new lesson
            addTipoConteudoSelect.value = 'video'; // Default to video
            addUrlVideoInput.value = '';
            document.getElementById('add_descricao_aula').value = '';
            toggleAddLessonFields(); // Apply initial display logic
            openModal(addLessonModal);
        });
    });

    // --- Lógica para Modal de Editar Aula ---
    const editLessonModal = document.getElementById('edit-lesson-modal');
    const editTipoConteudoSelect = document.getElementById('edit_tipo_conteudo');
    const editVideoUrlContainer = document.getElementById('edit-video-url-container');
    const editExistingFilesContainer = document.getElementById('edit-existing-files-container');
    const editNewFilesUploadContainer = document.getElementById('edit-new-files-upload-container');
    const editUrlVideoInput = document.getElementById('edit_url_video');
    const existingFilesList = document.getElementById('existing-files-list');
    const editAulaFilesInput = document.getElementById('edit_aula_files');


    function toggleEditLessonFields() {
        const selectedType = editTipoConteudoSelect.value;
        editUrlVideoInput.required = false;
        editAulaFilesInput.required = false; // Reset required for new uploads

        editVideoUrlContainer.style.display = 'none';
        editExistingFilesContainer.style.display = 'none';
        editNewFilesUploadContainer.style.display = 'none';

        if (selectedType === 'video' || selectedType === 'mixed') {
            editVideoUrlContainer.style.display = 'block';
            editUrlVideoInput.required = true;
        }
        if (selectedType === 'files' || selectedType === 'mixed') {
            editExistingFilesContainer.style.display = 'block';
            editNewFilesUploadContainer.style.display = 'block';
            
            // Check if any existing file checkbox is currently checked
            const anyExistingFileSelectedToKeep = existingFilesList.querySelectorAll('input[name="existing_files[]"]:checked').length > 0;

            if (!anyExistingFileSelectedToKeep) { // If no existing files are selected to be kept, then new uploads become required
                editAulaFilesInput.required = true;
            }
        }
    }

    editTipoConteudoSelect.addEventListener('change', toggleEditLessonFields);
    // Also, re-evaluate required status when a checkbox for existing files is clicked
    existingFilesList.addEventListener('change', (e) => {
        if (e.target.type === 'checkbox' && (editTipoConteudoSelect.value === 'files' || editTipoConteudoSelect.value === 'mixed')) {
            toggleEditLessonFields();
        }
    });


    document.querySelectorAll('.edit-lesson-btn').forEach(button => {
        button.addEventListener('click', function() {
            const aulaId = this.dataset.aulaId;
            const titulo = this.dataset.titulo;
            const urlVideo = this.dataset.urlVideo;
            const descricao = this.dataset.descricao;
            const releaseDays = this.dataset.releaseDays;
            const tipoConteudo = this.dataset.tipoConteudo;
            const files = JSON.parse(this.dataset.files);

            document.getElementById('edit_aula_id').value = aulaId;
            document.getElementById('edit_titulo_aula').value = titulo;
            document.getElementById('edit_url_video').value = urlVideo;
            document.getElementById('edit_descricao_aula').value = descricao;
            document.getElementById('edit_release_days_aula').value = releaseDays;
            document.getElementById('edit_tipo_conteudo').value = tipoConteudo;

            // Preencher lista de arquivos existentes
            existingFilesList.innerHTML = '';
            if (files && files.length > 0) {
                files.forEach(file => {
                    const fileItem = document.createElement('div');
                    fileItem.className = 'flex items-center space-x-2 p-2 bg-dark-elevated rounded-md border border-dark-border';
                    fileItem.innerHTML = `
                        <input type="checkbox" name="existing_files[]" value="${file.id}" id="edit_file_${file.id}" class="h-4 w-4 text-[#32e768] focus:ring-[#32e768] rounded" checked>
                        <label for="edit_file_${file.id}" class="text-sm text-gray-300">${file.nome_original}</label>
                        <a href="${file.caminho_arquivo}" target="_blank" class="ml-auto text-blue-400 hover:text-blue-300 hover:underline"><i data-lucide="download" class="w-4 h-4"></i></a>
                    `;
                    existingFilesList.appendChild(fileItem);
                });
            } else {
                existingFilesList.innerHTML = '<p class="text-sm text-gray-400">Nenhum arquivo enviado para esta aula.</p>';
            }

            // Reset o input de novos arquivos
            editAulaFilesInput.value = '';

            toggleEditLessonFields(); // Aplica a lógica de exibição inicial
            lucide.createIcons();
            openModal(editLessonModal);
        });
    });

    // --- Função para toggle campos de módulo pago ---
    function togglePaidModuleFields(mode) {
        const checkbox = document.getElementById('is_paid_module_' + mode);
        const fieldsContainer = document.getElementById('paid_module_fields_' + mode);
        
        if (checkbox && fieldsContainer) {
            if (checkbox.checked) {
                fieldsContainer.classList.remove('hidden');
            } else {
                fieldsContainer.classList.add('hidden');
                // Limpar seleção do produto
                const productSelect = document.getElementById('linked_product_id_' + mode);
                if (productSelect) {
                    productSelect.value = '';
                }
            }
        }
    }

    // --- Lógica para Modal de Editar Módulo ---
    const editModuleModal = document.getElementById('edit-module-modal');
    const imgPreview = document.getElementById('modal-imagem-preview');
    const removeImageCheckbox = document.getElementById('remove_imagem_capa_modulo');
    document.querySelectorAll('.edit-module-btn').forEach(button => {
        button.addEventListener('click', function() {
            document.getElementById('modal-modulo-id-edit').value = this.dataset.moduloId;
            document.getElementById('modal-titulo-modulo-edit').value = this.dataset.moduloTitulo;
            document.getElementById('modal-release-days-modulo-edit').value = this.dataset.releaseDays;
            
            // NEW: Carregar dados de módulo pago
            const isPaidModule = this.dataset.isPaidModule === '1';
            const linkedProductId = this.dataset.linkedProductId || '';
            const paidModuleCheckbox = document.getElementById('is_paid_module_edit');
            const linkedProductSelect = document.getElementById('linked_product_id_edit');
            
            if (paidModuleCheckbox) {
                paidModuleCheckbox.checked = isPaidModule;
                togglePaidModuleFields('edit');
            }
            if (linkedProductSelect && linkedProductId) {
                linkedProductSelect.value = linkedProductId;
            }
            
            // Garantir que os campos sejam exibidos corretamente
            togglePaidModuleFields('edit');
            
            const imageUrl = this.dataset.imageUrl;
            if (imageUrl) {
                imgPreview.src = imageUrl;
                imgPreview.classList.remove('hidden');
                removeImageCheckbox.checked = false; // Garante que não esteja marcado ao abrir
                removeImageCheckbox.parentElement.style.display = 'flex'; // Mostra a opção de remover
            } else {
                imgPreview.src = '';
                imgPreview.classList.add('hidden');
                removeImageCheckbox.checked = false;
                removeImageCheckbox.parentElement.style.display = 'none'; // Esconde se não houver imagem
            }
            openModal(editModuleModal);
        });
    });

    // --- Lógica de Reordenação (Drag-and-Drop) de Aulas ---
    document.querySelectorAll('.sortable-aulas').forEach(ul => {
        new Sortable(ul, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            handle: '.cursor-grab', // A alça para arrastar será o ícone de 'grip-vertical'
            onEnd: function (evt) {
                const moduloId = evt.from.dataset.moduloId;
                const newOrder = Array.from(evt.from.children).map(item => item.dataset.aulaId);
                
                // Enviar a nova ordem para a API
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="csrf_token"]')?.value || '';
                fetch('/api/api.php?action=reorder_aulas', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        modulo_id: moduloId,
                        aulas_order: newOrder,
                        produto_id: currentProductId, // Passa o ID do produto para validação
                        csrf_token: csrfToken
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Opcional: Feedback visual de sucesso
                        // alert('Ordem das aulas atualizada!');
                        console.log('Ordem das aulas atualizada com sucesso!');
                    } else {
                        // alert('Erro ao reordenar aulas: ' + (data.error || 'Erro desconhecido.'));
                        console.error('Erro ao reordenar aulas:', data.error);
                        // Opcional: Recarregar a página para reverter a ordem visual para a do banco
                        // window.location.reload(); 
                    }
                })
                .catch(error => {
                    console.error('Erro de rede ao reordenar aulas:', error);
                    // alert('Erro de comunicação com o servidor ao reordenar aulas.');
                    // window.location.reload();
                });
            }
        });
    });

    // Chamada inicial para toggleAddLessonFields para garantir que o formulário "Adicionar Aula" esteja correto ao carregar
    toggleAddLessonFields();
});
</script>