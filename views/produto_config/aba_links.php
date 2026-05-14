<?php
// Aba Links - Link do checkout para copiar
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$domainName = $_SERVER['HTTP_HOST'];
$checkout_link = $protocol . $domainName . '/checkout?p=' . $produto['checkout_hash'];
?>

<div class="space-y-6">
    <div>
        <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
            <i data-lucide="link" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Link do Checkout
        </h2>
        
        <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border space-y-6">
            <!-- Link Completo -->
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Link Completo do Checkout</label>
                <div class="flex items-center gap-2">
                    <input type="text" id="checkout-link-input" readonly value="<?php echo htmlspecialchars($checkout_link); ?>" class="form-input flex-1 bg-dark-card">
                    <button type="button" id="copy-link-btn" class="text-white font-semibold py-2.5 px-6 rounded-lg transition-all duration-300 flex items-center gap-2 whitespace-nowrap" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                        <i data-lucide="copy" class="w-4 h-4"></i>
                        <span id="copy-link-text">Copiar Link</span>
                    </button>
                </div>
                <p class="text-xs text-gray-400 mt-2">Use este link para compartilhar o checkout do produto.</p>
            </div>

        </div>
    </div>

    <!-- Links das Ofertas -->
    <?php
    // Buscar ofertas ativas do produto
    $stmt_ofertas = $pdo->prepare("SELECT * FROM produto_ofertas WHERE produto_id = ? AND is_active = 1 ORDER BY created_at DESC");
    $stmt_ofertas->execute([$id_produto]);
    $ofertas = $stmt_ofertas->fetchAll(PDO::FETCH_ASSOC);
    ?>
    
    <?php if (!empty($ofertas)): ?>
    <div>
        <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
            <i data-lucide="tag" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Links das Ofertas
        </h2>
        
        <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border space-y-4">
            <?php foreach ($ofertas as $oferta): 
                $oferta_link = $protocol . $domainName . '/checkout?p=' . $oferta['checkout_hash'];
            ?>
                <div class="bg-dark-card p-4 rounded-lg border border-dark-border">
                    <div class="flex items-start justify-between gap-4 mb-3">
                        <div class="flex-1">
                            <div class="flex items-center gap-3 mb-2">
                                <h3 class="font-semibold text-white"><?php echo htmlspecialchars($oferta['nome']); ?></h3>
                                <span class="bg-green-900/30 text-green-400 text-xs font-bold px-2 py-0.5 rounded border border-green-500/50">Ativa</span>
                            </div>
                            <p class="text-sm text-gray-400">
                                <span class="text-gray-500">Preço:</span>
                                <span class="text-white font-bold ml-1">R$ <?php echo number_format($oferta['preco'], 2, ',', '.'); ?></span>
                            </p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="text" readonly value="<?php echo htmlspecialchars($oferta_link); ?>" class="form-input flex-1 bg-dark-elevated text-sm" id="oferta-link-links-<?php echo $oferta['id']; ?>">
                        <button type="button" class="copy-oferta-link-links text-white font-semibold py-2.5 px-6 rounded-lg transition-all duration-300 flex items-center gap-2 whitespace-nowrap" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'" data-link-id="oferta-link-links-<?php echo $oferta['id']; ?>">
                            <i data-lucide="copy" class="w-4 h-4"></i>
                            <span class="copy-oferta-text-<?php echo $oferta['id']; ?>">Copiar Link</span>
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const copyBtn = document.getElementById('copy-link-btn');
    const copyText = document.getElementById('copy-link-text');
    const linkInput = document.getElementById('checkout-link-input');

    // Copiar link principal
    copyBtn?.addEventListener('click', () => {
        linkInput.select();
        document.execCommand('copy');
        
        const originalText = copyText.textContent;
        copyText.textContent = 'Copiado!';
        copyBtn.classList.add('bg-green-600');
        
        setTimeout(() => {
            copyText.textContent = originalText;
            copyBtn.classList.remove('bg-green-600');
        }, 2000);
    });

    // Copiar links das ofertas
    document.querySelectorAll('.copy-oferta-link-links').forEach(btn => {
        btn.addEventListener('click', function() {
            const linkId = this.getAttribute('data-link-id');
            const linkInput = document.getElementById(linkId);
            if (linkInput) {
                linkInput.select();
                document.execCommand('copy');
                
                const ofertaId = linkId.replace('oferta-link-links-', '');
                const copyText = document.querySelector('.copy-oferta-text-' + ofertaId);
                if (copyText) {
                    const originalText = copyText.textContent;
                    copyText.textContent = 'Copiado!';
                    this.classList.add('bg-green-600');
                    
                    setTimeout(() => {
                        copyText.textContent = originalText;
                        this.classList.remove('bg-green-600');
                    }, 2000);
                }
            }
        });
    });
});
</script>

