<?php
// config.php
if (!defined('BYTENFT_TRANSAK_PROTOCOL')) {
    define('BYTENFT_TRANSAK_PROTOCOL', is_ssl() ? 'https://' : 'http://');
}

if (!defined('BYTENFT_TRANSAK_HOST')) {
    define('BYTENFT_TRANSAK_HOST', 'pay.bytenft.xyz');
}

if (!defined('BYTENFT_TRANSAK_BASE_URL')) {
	define('BYTENFT_TRANSAK_BASE_URL', BYTENFT_TRANSAK_PROTOCOL . BYTENFT_TRANSAK_HOST);
}
