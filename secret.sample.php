<?php
/* MODELLO. Copia questo file in `secret.php` (stessa cartella) e valorizza
 * i campi che ti servono. `secret.php` NON va versionato: è già in
 * .gitignore e già negato via .htaccess / gallery.conf.
 *
 * Tutti i campi sono opzionali: senza secret.php, config.php usa i fallback
 * (host 'localhost', API disabilitata finché non imposti un token).
 */
return [
    // Genera con:  php -r 'echo bin2hex(random_bytes(24));'
    'API_TOKEN'     => '',

    // Domini ammessi per generare i link (protegge da Host header spoofing)
    'ALLOWED_HOSTS' => ['tuo-dominio.tld'],

    // Percorso del DB, se diverso da <cartella-app>/gallery.db
    // 'DB_PATH'    => '/var/lib/gallery/gallery.db',
];
