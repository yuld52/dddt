<?php
/**
 * API de Gerenciamento de Plugins
 */
// Desabilita exibição de erros para não quebrar o JSON
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Função auxiliar para remover diretório recursivamente (deve vir antes de ser usada)
if (!function_exists('rmdir_recursive')) {
    function rmdir_recursive($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        $files = @array_diff(@scandir($dir), ['.', '..']);
        if ($files === false) {
            return false;
        }
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                rmdir_recursive($path);
            } else {
                @unlink($path);
            }
        }
        return @rmdir($dir);
    }
}

// Inicia o buffer de saída no início do script
ob_start();

// A sessão já é iniciada pelo config.php, mas garantimos que está ativa
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

// Verificar se é admin (a sessão já foi iniciada pelo config.php)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Não autenticado']);
    ob_end_flush();
    exit;
}

if (!isset($_SESSION["tipo"]) || $_SESSION["tipo"] !== 'admin') {
    ob_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado. Apenas administradores podem gerenciar plugins.']);
    ob_end_flush();
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list_plugins':
            // Verifica se a tabela existe, se não, retorna array vazio
            try {
                $stmt = $pdo->query("SELECT * FROM plugins ORDER BY nome ASC");
                $plugins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Se a tabela não existir, retorna array vazio
                if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "não existe") !== false) {
                    $plugins = [];
                } else {
                    throw $e;
                }
            }
            
            // Auto-instala plugins que estão na pasta mas não estão no banco
            $plugins_dir = __DIR__ . '/../plugins/';
            if (is_dir($plugins_dir)) {
                $folders = @scandir($plugins_dir);
                if ($folders !== false) {
                    $installed_folders = array_column($plugins, 'pasta');
                    
                    foreach ($folders as $folder) {
                        if ($folder === '.' || $folder === '..' || !is_dir($plugins_dir . $folder)) {
                            continue;
                        }
                        
                        // Se não está instalado mas tem arquivo principal, instala automaticamente
                        if (!in_array($folder, $installed_folders)) {
                            $plugin_file = $plugins_dir . $folder . '/' . $folder . '.php';
                            if (file_exists($plugin_file)) {
                                // Lê informações do plugin
                                $plugin_info = [
                                    'nome' => ucfirst(str_replace('_', ' ', $folder)),
                                    'versao' => '1.0.0'
                                ];
                                
                                $info_file = $plugins_dir . $folder . '/plugin.json';
                                if (file_exists($info_file)) {
                                    $info_content = @file_get_contents($info_file);
                                    if ($info_content !== false) {
                                        $info = json_decode($info_content, true);
                                        if ($info && json_last_error() === JSON_ERROR_NONE) {
                                            $plugin_info = array_merge($plugin_info, $info);
                                        }
                                    }
                                }
                                
                                // Instala automaticamente
                                try {
                                    $stmt = $pdo->prepare("INSERT INTO plugins (nome, pasta, versao, ativo) VALUES (?, ?, ?, 0)");
                                    $stmt->execute([$plugin_info['nome'], $folder, $plugin_info['versao'] ?? '1.0.0']);
                                    
                                    // Executa install.php se existir
                                    $install_file = $plugins_dir . $folder . '/install.php';
                                    if (file_exists($install_file)) {
                                        define('PLUGIN_INSTALL', true);
                                        @require_once $install_file;
                                    }
                                    
                                    // Recarrega lista de plugins
                                    $stmt = $pdo->query("SELECT * FROM plugins ORDER BY nome ASC");
                                    $plugins = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                } catch (PDOException $e) {
                                    // Ignora erro se tabela não existir ou plugin já existir
                                }
                            }
                        }
                    }
                }
            }
            
            // Verifica se os arquivos existem
            foreach ($plugins as &$plugin) {
                $plugin_file = __DIR__ . '/../plugins/' . $plugin['pasta'] . '/' . $plugin['pasta'] . '.php';
                $plugin['arquivo_existe'] = file_exists($plugin_file);
            }
            
            ob_clean(); // Limpa qualquer output anterior
            echo json_encode(['success' => true, 'plugins' => $plugins]);
            ob_end_flush();
            exit;
            
        case 'scan_plugins_folder':
            // Escaneia a pasta plugins/ procurando por plugins não instalados
            $plugins_dir = __DIR__ . '/../plugins/';
            $discovered_plugins = [];
            
            if (is_dir($plugins_dir)) {
                $folders = scandir($plugins_dir);
                
                foreach ($folders as $folder) {
                    if ($folder === '.' || $folder === '..' || !is_dir($plugins_dir . $folder)) {
                        continue;
                    }
                    
                    // Verifica se existe o arquivo principal do plugin
                    $plugin_file = $plugins_dir . $folder . '/' . $folder . '.php';
                    if (file_exists($plugin_file)) {
                        // Verifica se já está instalado
                        try {
                            $stmt = $pdo->prepare("SELECT id, nome, versao, ativo FROM plugins WHERE pasta = ?");
                            $stmt->execute([$folder]);
                            $installed = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            // Tenta ler plugin.json se existir
                            $info_file = $plugins_dir . $folder . '/plugin.json';
                            $plugin_info = [
                                'nome' => ucfirst(str_replace('_', ' ', $folder)),
                                'versao' => '1.0.0',
                                'descricao' => ''
                            ];
                            
                            if (file_exists($info_file)) {
                                $info = json_decode(file_get_contents($info_file), true);
                                if ($info) {
                                    $plugin_info = array_merge($plugin_info, $info);
                                }
                            }
                            
                            $discovered_plugins[] = [
                                'pasta' => $folder,
                                'nome' => $plugin_info['nome'],
                                'versao' => $plugin_info['versao'],
                                'descricao' => $plugin_info['descricao'] ?? '',
                                'instalado' => $installed !== false,
                                'instalado_id' => $installed ? $installed['id'] : null,
                                'instalado_nome' => $installed ? $installed['nome'] : null,
                                'instalado_versao' => $installed ? $installed['versao'] : null,
                                'instalado_ativo' => $installed ? (bool)$installed['ativo'] : false
                            ];
                        } catch (PDOException $e) {
                            // Se a tabela não existir, considera não instalado
                            $discovered_plugins[] = [
                                'pasta' => $folder,
                                'nome' => ucfirst(str_replace('_', ' ', $folder)),
                                'versao' => '1.0.0',
                                'descricao' => '',
                                'instalado' => false
                            ];
                        }
                    }
                }
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'plugins' => $discovered_plugins]);
            ob_end_flush();
            exit;
            
        case 'install_plugin':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            
            $plugin_name = $_POST['nome'] ?? '';
            $plugin_pasta = $_POST['pasta'] ?? '';
            $plugin_versao = $_POST['versao'] ?? '1.0.0';
            
            if (empty($plugin_name) || empty($plugin_pasta)) {
                throw new Exception('Nome e pasta do plugin são obrigatórios');
            }
            
            // Verifica se já existe
            $stmt = $pdo->prepare("SELECT id FROM plugins WHERE pasta = ?");
            $stmt->execute([$plugin_pasta]);
            if ($stmt->fetch()) {
                throw new Exception('Plugin já está instalado');
            }
            
            // Verifica se a pasta existe
            $plugin_dir = __DIR__ . '/../plugins/' . $plugin_pasta;
            if (!is_dir($plugin_dir)) {
                throw new Exception('Pasta do plugin não encontrada');
            }
            
            // Verifica se o arquivo principal existe
            $plugin_file = $plugin_dir . '/' . $plugin_pasta . '.php';
            if (!file_exists($plugin_file)) {
                throw new Exception('Arquivo principal do plugin não encontrado');
            }
            
            // Insere no banco
            $stmt = $pdo->prepare("INSERT INTO plugins (nome, pasta, versao, ativo) VALUES (?, ?, ?, 0)");
            $stmt->execute([$plugin_name, $plugin_pasta, $plugin_versao]);
            
            // Executa install.php se existir
            $install_file = $plugin_dir . '/install.php';
            if (file_exists($install_file)) {
                define('PLUGIN_INSTALL', true);
                require_once $install_file;
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Plugin instalado com sucesso']);
            ob_end_flush();
            exit;
            
        case 'toggle_plugin':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            
            $plugin_id = $_POST['id'] ?? 0;
            $novo_status = $_POST['ativo'] ?? 0;
            
            $stmt = $pdo->prepare("UPDATE plugins SET ativo = ? WHERE id = ?");
            $stmt->execute([$novo_status, $plugin_id]);
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Status do plugin atualizado']);
            ob_end_flush();
            exit;
            
        case 'uninstall_plugin':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            
            $plugin_id = $_POST['id'] ?? 0;
            
            // Busca informações do plugin
            $stmt = $pdo->prepare("SELECT pasta FROM plugins WHERE id = ?");
            $stmt->execute([$plugin_id]);
            $plugin = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$plugin) {
                throw new Exception('Plugin não encontrado');
            }
            
            // Executa uninstall.php se existir
            $uninstall_file = __DIR__ . '/../plugins/' . $plugin['pasta'] . '/uninstall.php';
            if (file_exists($uninstall_file)) {
                define('PLUGIN_UNINSTALL', true);
                require_once $uninstall_file;
            }
            
            // Remove do banco
            $stmt = $pdo->prepare("DELETE FROM plugins WHERE id = ?");
            $stmt->execute([$plugin_id]);
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Plugin desinstalado com sucesso']);
            ob_end_flush();
            exit;
            
        case 'upload_plugin':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['plugin_zip'])) {
                throw new Exception('Arquivo ZIP não enviado');
            }
            
            // Validação segura de upload usando security_helper
            require_once __DIR__ . '/../helpers/security_helper.php';
            
            $file = $_FILES['plugin_zip'];
            
            // Whitelist de tipos MIME permitidos para ZIP
            $allowed_types = [
                'application/zip',
                'application/x-zip-compressed',
                'application/x-zip',
                'application/octet-stream' // Alguns servidores retornam isso para ZIP
            ];
            $allowed_extensions = ['zip'];
            $max_size = 50 * 1024 * 1024; // 50MB
            $upload_dir = sys_get_temp_dir() . '/';
            
            $upload_result = validate_uploaded_file($file, $allowed_types, $allowed_extensions, $max_size, $upload_dir, 'plugin_zip');
            
            if (!$upload_result['success']) {
                throw new Exception($upload_result['error'] ?? 'Erro ao validar arquivo ZIP');
            }
            
            // O arquivo foi validado e movido para um local seguro
            // validate_uploaded_file retorna caminho relativo, precisamos do absoluto
            $validated_file_path = $upload_result['file_path'];
            $validated_file_absolute = realpath(__DIR__ . '/../' . $validated_file_path);
            
            if (!$validated_file_absolute || !file_exists($validated_file_absolute)) {
                throw new Exception('Arquivo validado não encontrado');
            }
            
            $temp_dir = null;
            try {
                // Cria pasta temporária
                $temp_dir = sys_get_temp_dir() . '/plugin_' . uniqid();
                if (!@mkdir($temp_dir, 0755, true)) {
                    throw new Exception('Não foi possível criar pasta temporária');
                }
                
                // Verifica se ZipArchive está disponível
                if (!class_exists('ZipArchive')) {
                    throw new Exception('Extensão ZipArchive não está disponível no servidor');
                }
                
                // Extrai ZIP usando o arquivo validado
                $zip = new ZipArchive();
                $zip_result = $zip->open($validated_file_absolute);
                if ($zip_result !== TRUE) {
                    $zip_errors = [
                        ZipArchive::ER_NOZIP => 'Não é um arquivo ZIP válido',
                        ZipArchive::ER_OPEN => 'Não foi possível abrir o arquivo',
                        ZipArchive::ER_READ => 'Erro ao ler o arquivo',
                        ZipArchive::ER_MEMORY => 'Erro de memória',
                    ];
                    $error_msg = $zip_errors[$zip_result] ?? 'Erro ao abrir ZIP (código: ' . $zip_result . ')';
                    throw new Exception('Não foi possível abrir o arquivo ZIP: ' . $error_msg);
                }
                
                $zip->extractTo($temp_dir);
                $zip->close();
                
                // Procura pelo arquivo principal do plugin (primeira pasta dentro do ZIP)
                $files = @scandir($temp_dir);
                if ($files === false) {
                    throw new Exception('Não foi possível ler a pasta temporária');
                }
                
                $plugin_folder = null;
                foreach ($files as $file_item) {
                    if ($file_item !== '.' && $file_item !== '..' && is_dir($temp_dir . '/' . $file_item)) {
                        $plugin_folder = $file_item;
                        break;
                    }
                }
                
                if (!$plugin_folder) {
                    throw new Exception('Estrutura do plugin inválida. O ZIP deve conter uma pasta com o nome do plugin.');
                }
                
                // Verifica se o arquivo principal existe
                $plugin_main_file = $temp_dir . '/' . $plugin_folder . '/' . $plugin_folder . '.php';
                if (!file_exists($plugin_main_file)) {
                    throw new Exception('Arquivo principal do plugin não encontrado. Procure por: ' . $plugin_folder . '/' . $plugin_folder . '.php');
                }
                
                // Move para a pasta plugins
                $target_dir = __DIR__ . '/../plugins/' . $plugin_folder;
                if (is_dir($target_dir)) {
                    // Remove pasta existente
                    if (function_exists('rmdir_recursive')) {
                        rmdir_recursive($target_dir);
                    } else {
                        // Fallback se a função não estiver disponível
                        $files = @array_diff(@scandir($target_dir), ['.', '..']);
                        if ($files !== false) {
                            foreach ($files as $file) {
                                $path = $target_dir . '/' . $file;
                                if (is_dir($path)) {
                                    @array_map('unlink', glob($path . '/*'));
                                    @rmdir($path);
                                } else {
                                    @unlink($path);
                                }
                            }
                        }
                        @rmdir($target_dir);
                    }
                }
                
                if (!@rename($temp_dir . '/' . $plugin_folder, $target_dir)) {
                    throw new Exception('Não foi possível mover o plugin para a pasta plugins/. Verifique permissões.');
                }
                
                // Limpa pasta temporária
                if (function_exists('rmdir_recursive')) {
                    rmdir_recursive($temp_dir);
                } else {
                    // Fallback se a função não estiver disponível
                    $files = @array_diff(@scandir($temp_dir), ['.', '..']);
                    if ($files !== false) {
                        foreach ($files as $file) {
                            $path = $temp_dir . '/' . $file;
                            if (is_dir($path)) {
                                @array_map('unlink', glob($path . '/*'));
                                @rmdir($path);
                            } else {
                                @unlink($path);
                            }
                        }
                    }
                    @rmdir($temp_dir);
                }
                $temp_dir = null; // Marca como limpo
                
                // Remove o arquivo ZIP validado após extração bem-sucedida
                if ($validated_file_absolute && file_exists($validated_file_absolute)) {
                    @unlink($validated_file_absolute);
                }
                
                // Lê informações do plugin (se houver plugin.json)
                $plugin_info = [
                    'nome' => $plugin_folder,
                    'pasta' => $plugin_folder,
                    'versao' => '1.0.0'
                ];
                
                $info_file = $target_dir . '/plugin.json';
                if (file_exists($info_file)) {
                    $info_content = @file_get_contents($info_file);
                    if ($info_content !== false) {
                        $info = json_decode($info_content, true);
                        if ($info && json_last_error() === JSON_ERROR_NONE) {
                            $plugin_info = array_merge($plugin_info, $info);
                        }
                    }
                }
                
                // Verifica se já está instalado
                $already_installed = false;
                try {
                    $stmt = $pdo->prepare("SELECT id FROM plugins WHERE pasta = ?");
                    $stmt->execute([$plugin_folder]);
                    $already_installed = $stmt->fetch() !== false;
                } catch (PDOException $e) {
                    // Se a tabela não existir, considera não instalado
                    $already_installed = false;
                }
                
                // Se não está instalado, instala automaticamente
                if (!$already_installed) {
                    try {
                        $stmt = $pdo->prepare("INSERT INTO plugins (nome, pasta, versao, ativo) VALUES (?, ?, ?, 0)");
                        $stmt->execute([$plugin_info['nome'], $plugin_folder, $plugin_info['versao'] ?? '1.0.0']);
                        
                        // Executa install.php se existir
                        $install_file = $target_dir . '/install.php';
                        if (file_exists($install_file)) {
                            define('PLUGIN_INSTALL', true);
                            @require_once $install_file;
                        }
                        
                        $already_installed = true;
                    } catch (PDOException $e) {
                        // Ignora erro se tabela não existir ou plugin já existir
                    }
                }
                
                ob_clean();
                if ($already_installed) {
                    echo json_encode(['success' => true, 'message' => 'Plugin extraído e instalado automaticamente.', 'plugin' => $plugin_info]);
                } else {
                    echo json_encode(['success' => true, 'message' => 'Plugin extraído com sucesso. Instale-o na lista de plugins.', 'plugin' => $plugin_info]);
                }
                ob_end_flush();
                exit;
            } catch (Exception $e) {
                // Remove o arquivo ZIP validado em caso de erro
                if (isset($validated_file_absolute) && $validated_file_absolute && file_exists($validated_file_absolute)) {
                    @unlink($validated_file_absolute);
                }
                
                // Limpa pasta temporária em caso de erro
                if ($temp_dir && is_dir($temp_dir)) {
                    if (function_exists('rmdir_recursive')) {
                        rmdir_recursive($temp_dir);
                    } else {
                        // Fallback se a função não estiver disponível
                        $files = @array_diff(@scandir($temp_dir), ['.', '..']);
                        if ($files !== false) {
                            foreach ($files as $file) {
                                $path = $temp_dir . '/' . $file;
                                if (is_dir($path)) {
                                    @array_map('unlink', glob($path . '/*'));
                                    @rmdir($path);
                                } else {
                                    @unlink($path);
                                }
                            }
                        }
                        @rmdir($temp_dir);
                    }
                }
                throw $e;
            }
            
        default:
            ob_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Ação não reconhecida']);
            ob_end_flush();
            exit;
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    ob_end_flush();
    exit;
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
    ob_end_flush();
    exit;
}


