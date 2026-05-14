<?php
// Este arquivo é incluído a partir do index.php,
// então a verificação de login e a conexão com o banco ($pdo) já existem.

// Obter o ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;

// Se por algum motivo o ID do usuário não estiver definido, redireciona para o login
if ($usuario_id_logado === 0) {
    header("location: /login");
    exit;
}

// Busca todos os produtos que são do tipo 'Área de Membros' E que pertencem ao usuário logado
try {
    $stmt = $pdo->prepare("
        SELECT id, nome, foto 
        FROM produtos 
        WHERE tipo_entrega = 'area_membros'
        AND usuario_id = ? 
        ORDER BY data_criacao DESC
    ");
    $stmt->execute([$usuario_id_logado]);
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Em um cenário real, seria bom logar este erro.
    echo "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Erro ao buscar cursos: " . htmlspecialchars($e->getMessage()) . "</div>";
    $cursos = []; // Garante que a variável exista para evitar erros no HTML
}

$upload_dir = 'uploads/'; // Pasta onde as imagens estão salvas
?>

<div class="container mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-white">Área de Membros</h1>
    </div>

    <!-- Listagem de Cursos -->
    <div class="bg-dark-card p-8 rounded-lg shadow-md" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold mb-6 text-white">Gerenciar Cursos</h2>
        
        <?php if (empty($cursos)): ?>
            <div class="text-center py-12 text-gray-400">
                <i data-lucide="video-off" class="mx-auto w-16 h-16 text-gray-500"></i>
                <p class="mt-4">Nenhum produto foi configurado para entrega via Área de Membros.</p>
                <p>Vá para a <a href="/index?pagina=produtos" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="hover:underline font-semibold">página de produtos</a> para configurar um.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-8">
                <?php foreach ($cursos as $curso): ?>
                    <div class="group bg-dark-elevated rounded-lg overflow-hidden border border-dark-border hover:shadow-xl transition-shadow duration-300 flex flex-col">
                        <div class="relative h-64 bg-dark-card">
                             <?php if ($curso['foto']): ?>
                                <img src="<?php echo $upload_dir . htmlspecialchars($curso['foto']); ?>" alt="<?php echo htmlspecialchars($curso['nome']); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center">
                                    <i data-lucide="image-off" class="text-gray-500 w-16 h-16"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-4 flex-grow flex flex-col justify-between">
                            <div>
                                <h3 class="font-bold text-lg text-white mb-4 truncate" title="<?php echo htmlspecialchars($curso['nome']); ?>">
                                    <?php echo htmlspecialchars($curso['nome']); ?>
                                </h3>
                            </div>
                            <div class="mt-2 flex flex-col gap-2"> <!-- Alterado para flex-col e gap-2 para empilhar botões -->
                                <a href="/curso_preview?produto_id=<?php echo $curso['id']; ?>" target="_blank" class="flex-1 text-center bg-dark-card text-gray-300 font-bold py-2 px-3 rounded-lg hover:bg-dark-elevated hover:text-white transition duration-300 flex items-center justify-center space-x-2 text-sm border border-dark-border">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                    <span>Pré-visualizar</span>
                                </a>
                                <a href="/index?pagina=gerenciar_curso&produto_id=<?php echo $curso['id']; ?>" class="flex-1 text-center text-white font-bold py-2 px-3 rounded-lg transition duration-300 flex items-center justify-center space-x-2 text-sm" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                                    <i data-lucide="edit" class="w-4 h-4"></i>
                                    <span>Gerenciar</span>
                                </a>
                                <!-- NOVO BOTÃO 'OFERTAS' -->
                                <a href="/index?pagina=infoprodutor_member_offers&source_product_id=<?php echo $curso['id']; ?>" class="flex-1 text-center bg-purple-500 text-white font-bold py-2 px-3 rounded-lg hover:bg-purple-600 transition duration-300 flex items-center justify-center space-x-2 text-sm">
                                    <i data-lucide="tag" class="w-4 h-4"></i>
                                    <span>Ofertas</span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>