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

    // Percorso del database, se diverso da <cartella-app>/gallery.db.
    //
    // CONSIGLIATO: tenerlo fuori dal docroot. Cosi' la cartella del codice non
    // deve essere scrivibile dall'utente del server web, e un'eventuale falla
    // non permette di piazzare file eseguibili accanto all'applicazione.
    //
    // ATTENZIONE: SQLite in modalita' WAL (attiva per impostazione predefinita
    // in config.php) deve poter creare gallery.db-wal e gallery.db-shm NELLA
    // CARTELLA che contiene il database — non basta che il file .db sia
    // scrivibile. La cartella indicata qui va quindi assegnata all'utente del
    // server web:
    //
    //   sudo mkdir -p /var/lib/gallery
    //   sudo mv <app>/gallery.db /var/lib/gallery/
    //   sudo chown -R www-data:www-data /var/lib/gallery
    //   sudo chmod 750 /var/lib/gallery
    //
    // 'DB_PATH'    => '/var/lib/gallery/gallery.db',
];
