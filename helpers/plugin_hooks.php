<?php
/**
 * Sistema de Hooks/Actions para Plugins
 * Permite que plugins se integrem ao sistema existente
 */

if (!function_exists('register_hook')) {
    /**
     * Registra um hook/action
     * @param string $hook_name Nome do hook
     * @param callable $callback Função callback
     * @param int $priority Prioridade (menor = executa primeiro, padrão: 10)
     */
    function register_hook($hook_name, $callback, $priority = 10) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks)) {
            $plugin_hooks = [];
        }
        
        if (!isset($plugin_hooks[$hook_name])) {
            $plugin_hooks[$hook_name] = [];
        }
        
        $plugin_hooks[$hook_name][] = [
            'callback' => $callback,
            'priority' => $priority
        ];
        
        // Ordena por prioridade
        usort($plugin_hooks[$hook_name], function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
    }
}

if (!function_exists('do_action')) {
    /**
     * Executa todos os hooks registrados para uma ação
     * @param string $hook_name Nome do hook
     * @param mixed ...$args Argumentos para passar aos callbacks
     * @return mixed Retorna o último valor retornado ou null
     */
    function do_action($hook_name, ...$args) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks) || !isset($plugin_hooks[$hook_name])) {
            return null;
        }
        
        $result = null;
        foreach ($plugin_hooks[$hook_name] as $hook) {
            if (is_callable($hook['callback'])) {
                $result = call_user_func_array($hook['callback'], $args);
            }
        }
        
        return $result;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Aplica filtros a um valor (hooks que modificam dados)
     * @param string $filter_name Nome do filtro
     * @param mixed $value Valor a ser filtrado
     * @param mixed ...$args Argumentos adicionais
     * @return mixed Valor filtrado
     */
    function apply_filters($filter_name, $value, ...$args) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks) || !isset($plugin_hooks[$filter_name])) {
            return $value;
        }
        
        foreach ($plugin_hooks[$filter_name] as $hook) {
            if (is_callable($hook['callback'])) {
                $value = call_user_func_array($hook['callback'], array_merge([$value], $args));
            }
        }
        
        return $value;
    }
}

if (!function_exists('remove_hook')) {
    /**
     * Remove um hook específico
     * @param string $hook_name Nome do hook
     * @param callable $callback Callback a remover
     */
    function remove_hook($hook_name, $callback) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks) || !isset($plugin_hooks[$hook_name])) {
            return;
        }
        
        $plugin_hooks[$hook_name] = array_filter($plugin_hooks[$hook_name], function($hook) use ($callback) {
            return $hook['callback'] !== $callback;
        });
    }
}

// Alias para compatibilidade (add_action = register_hook)
if (!function_exists('add_action')) {
    /**
     * Alias para register_hook (compatibilidade WordPress-style)
     * @param string $hook_name Nome do hook
     * @param callable $callback Função callback
     * @param int $priority Prioridade (menor = executa primeiro, padrão: 10)
     */
    function add_action($hook_name, $callback, $priority = 10) {
        return register_hook($hook_name, $callback, $priority);
    }
}

