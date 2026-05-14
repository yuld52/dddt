<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/config.php';

// Proteção de página: verifica se o usuário está logado E se é um administrador
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["tipo"]) || $_SESSION["tipo"] !== 'admin') {
    header("location: /login");
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Detalhados - Painel Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .form-input-style { 
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: #0f1419;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            color: white;
        }
        .form-input-style:focus {
            outline: none;
            ring: 2px;
            ring-color: #32e768;
            border-color: #32e768;
        }
        .form-input-style::placeholder {
            color: #6b7280;
        }
        .form-input-style option {
            background-color: #0f1419;
            color: white;
        }
        input[type="date"].form-input-style,
        input[type="email"].form-input-style,
        input[type="text"].form-input-style,
        input[type="number"].form-input-style,
        input[type="password"].form-input-style,
        input[type="url"].form-input-style,
        select.form-input-style {
            color-scheme: dark;
        }
    </style>
</head>
<body class="bg-dark-base font-sans">
    <div class="container mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold text-white">Relatórios Detalhados</h1>
                <p class="text-gray-400 mt-1">Gere e visualize relatórios completos da plataforma.</p>
            </div>
            <a href="/admin?pagina=admin_dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
                <span>Voltar ao Dashboard</span>
            </a>
        </div>

        <div class="bg-dark-card p-8 rounded-lg shadow-md border border-[#32e768]">
            <h2 class="text-2xl font-semibold mb-6 text-white">Geração de Relatórios</h2>
            
            <form id="relatorios-form">
                <div class="mb-6 border-b border-dark-border pb-4">
                    <h3 class="text-xl font-semibold text-white mb-3">Filtros de Relatório</h3>
                    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                        <div>
                            <label for="tipo_relatorio" class="block text-sm font-medium text-gray-300">Tipo de Relatório</label>
                            <select id="tipo_relatorio" name="tipo_relatorio" class="form-input-style">
                                <option value="vendas_periodo">Vendas por Período</option>
                                <option value="produtos_vendidos">Produtos Vendidos</option>
                                <option value="atividade_usuarios">Atividade de Usuários</option>
                                <option value="faturamento_vendedores">Faturamento por Vendedor</option>
                            </select>
                        </div>
                        <div>
                            <label for="data_inicio" class="block text-sm font-medium text-gray-300">Data de Início</label>
                            <input type="date" id="data_inicio" name="data_inicio" class="form-input-style">
                        </div>
                        <div>
                            <label for="data_fim" class="block text-sm font-medium text-gray-300">Data de Fim</label>
                            <input type="date" id="data_fim" name="data_fim" class="form-input-style">
                        </div>
                    </div>
                    <div class="mt-6 text-right">
                        <button type="submit" class="bg-[#32e768] text-white font-bold py-2 px-5 rounded-lg hover:bg-[#28d15e] transition duration-300 flex items-center justify-center space-x-2 ml-auto">
                            <i data-lucide="download" class="w-5 h-5"></i>
                            <span>Gerar Relatório</span>
                        </button>
                    </div>
                </div>
            </form>

            <div id="relatorio-output">
                <div class="text-center py-12 text-gray-400">
                    <i data-lucide="bar-chart-2" class="mx-auto w-16 h-16 text-gray-500"></i>
                    <p class="mt-4 font-medium">Os relatórios gerados aparecerão aqui.</p>
                    <p class="mt-1 text-sm">Use os filtros acima para especificar o tipo de relatório e o período desejado. A lógica completa de geração de relatórios será implementada em futuras etapas.</p>
                </div>
            </div>
            
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            // Adiciona um evento para o formulário para exibir a mensagem placeholder,
            // já que a lógica completa ainda não está implementada.
            const relatoriosForm = document.getElementById('relatorios-form');
            const relatorioOutput = document.getElementById('relatorio-output');

            relatoriosForm.addEventListener('submit', function(e) {
                e.preventDefault(); // Impede o envio real do formulário

                // Aqui você pode adicionar uma mensagem temporária de "carregando"
                relatorioOutput.innerHTML = `
                    <div class="text-center py-12 text-gray-400">
                        <svg class="animate-spin h-8 w-8 text-[#32e768] mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
                        </svg>
                        <p class="mt-4 font-medium">Gerando relatório...</p>
                        <p class="mt-1 text-sm">Esta é uma funcionalidade em desenvolvimento.</p>
                    </div>
                `;

                // Simula um atraso para "gerar" o relatório e então mostra a mensagem final
                setTimeout(() => {
                    relatorioOutput.innerHTML = `
                        <div class="text-center py-12 text-gray-400">
                            <i data-lucide="bar-chart-2" class="mx-auto w-16 h-16 text-gray-500"></i>
                            <p class="mt-4 font-medium">Os relatórios gerados aparecerão aqui.</p>
                            <p class="mt-1 text-sm">Use os filtros acima para especificar o tipo de relatório e o período desejado. A lógica completa de geração de relatórios será implementada em futuras etapas.</p>
                        </div>
                    `;
                    lucide.createIcons(); // Re-renderiza ícones do Lucide
                }, 2000); // 2 segundos de atraso
            });
        });
    </script>
</body>
</html>