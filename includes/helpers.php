<?php
if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Remove caracteres não numéricos de uma string (útil para WhatsApp/Telefone).
 *
 * @param string|null $numero O número com possível máscara.
 * @return string O número sem máscara.
 */
function removerMascaraWhatsApp($numero) {
    return preg_replace('/[^0-9]/', '', (string)$numero);
}

// Adicione outras funções helper aqui conforme necessário. 