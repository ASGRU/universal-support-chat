<?php
/**
 * Plugin Name: Universal Support Chat (Telegram Tickets)
 * Description: Frontend support chat with client accounts, ticket system, and direct Telegram notifications.
 * Version: 0.1
 * Author: Universal
 * Text Domain: universal-support-chat
 * Domain Path: /languages
 */

if (!defined('ABSPATH')) {
    exit;
}

class Support_Chat_Telegram_Plugin {
    const VERSION = '0.1';
    const DB_VERSION = '2.0.0';
    const TEXT_DOMAIN = 'universal-support-chat';
    const OPTION_DB_VERSION = 'support_chat_db_version';
    const OPTION_TELEGRAM_BOT_TOKEN = 'support_chat_telegram_bot_token';
    const OPTION_TELEGRAM_CHAT_ID = 'support_chat_telegram_chat_id';
    const OPTION_TELEGRAM_WEBHOOK_SECRET = 'support_chat_telegram_webhook_secret';
    const OPTION_ALLOW_REGISTRATION = 'support_chat_allow_registration';

    const TABLE_TICKETS = 'support_chat_tickets';
    const TABLE_MESSAGES = 'support_chat_messages';
    const TABLE_TELEGRAM_LINKS = 'support_chat_telegram_links';
    const TABLE_SITES = 'support_chat_sites';
    const TABLE_CLIENTS = 'support_chat_clients';
    private $widget_rendered = false;

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('plugins_loaded', [$this, 'init']);
    }

    public function activate() {
        $this->create_tables();
        add_option(self::OPTION_ALLOW_REGISTRATION, 'yes');
        add_option(self::OPTION_TELEGRAM_WEBHOOK_SECRET, wp_generate_password(40, false, false));
    }

    public function init() {
        $this->maybe_upgrade_schema();
        $this->ensure_runtime_schema();
        load_plugin_textdomain(self::TEXT_DOMAIN, false, dirname(plugin_basename(__FILE__)) . '/languages');

        add_shortcode('support_chat', [$this, 'render_support_shortcode']);
        add_action('wp_body_open', [$this, 'render_floating_widget']);
        add_action('wp_footer', [$this, 'render_floating_widget']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);

        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_post_support_chat_save_settings', [$this, 'handle_save_settings']);
        add_action('admin_post_support_chat_admin_reply', [$this, 'handle_admin_reply']);
        add_action('admin_post_support_chat_change_status', [$this, 'handle_change_status']);

        add_action('admin_post_support_chat_create_ticket', [$this, 'handle_create_ticket']);
        add_action('admin_post_support_chat_send_reply', [$this, 'handle_send_reply']);
        add_action('admin_post_nopriv_support_chat_quick_message', [$this, 'handle_quick_message']);
        add_action('admin_post_support_chat_quick_message', [$this, 'handle_quick_message']);

        add_action('admin_post_nopriv_support_chat_register_user', [$this, 'handle_register_user']);
        add_action('admin_post_support_chat_register_user', [$this, 'handle_register_user']);
        add_action('admin_post_nopriv_support_chat_login_user', [$this, 'handle_login_user']);
        add_action('admin_post_support_chat_login_user', [$this, 'handle_login_user']);
        add_action('admin_post_support_chat_add_site', [$this, 'handle_add_site']);

        add_action('wp_ajax_nopriv_support_chat_widget_state', [$this, 'ajax_widget_state']);
        add_action('wp_ajax_support_chat_widget_state', [$this, 'ajax_widget_state']);
        add_action('wp_ajax_nopriv_support_chat_widget_send', [$this, 'ajax_widget_send']);
        add_action('wp_ajax_support_chat_widget_send', [$this, 'ajax_widget_send']);
        add_action('wp_ajax_nopriv_support_chat_widget_login', [$this, 'ajax_widget_login']);
        add_action('wp_ajax_support_chat_widget_login', [$this, 'ajax_widget_login']);
        add_action('wp_ajax_nopriv_support_chat_widget_register', [$this, 'ajax_widget_register']);
        add_action('wp_ajax_support_chat_widget_register', [$this, 'ajax_widget_register']);
        add_action('wp_ajax_nopriv_support_chat_widget_logout', [$this, 'ajax_widget_logout']);
        add_action('wp_ajax_support_chat_widget_logout', [$this, 'ajax_widget_logout']);
        add_action('wp_ajax_nopriv_support_chat_widget_open', [$this, 'ajax_widget_open']);
        add_action('wp_ajax_support_chat_widget_open', [$this, 'ajax_widget_open']);
        add_action('support_chat_check_unanswered', [$this, 'handle_unanswered_followup'], 10, 3);
        add_filter('rest_pre_serve_request', [$this, 'rest_cors_headers'], 10, 4);
    }

    private function maybe_upgrade_schema() {
        $current = (string) get_option(self::OPTION_DB_VERSION, '');
        if ($current !== self::DB_VERSION) {
            $this->create_tables();
        }
    }

    private function create_tables() {
        global $wpdb;

        $tickets = $wpdb->prefix . self::TABLE_TICKETS;
        $messages = $wpdb->prefix . self::TABLE_MESSAGES;
        $telegram_links = $wpdb->prefix . self::TABLE_TELEGRAM_LINKS;
        $sites = $wpdb->prefix . self::TABLE_SITES;
        $clients = $wpdb->prefix . self::TABLE_CLIENTS;
        $charset = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql_tickets = "CREATE TABLE {$tickets} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            site_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            client_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            subject VARCHAR(190) NOT NULL,
            guest_name VARCHAR(190) NULL,
            guest_email VARCHAR(190) NULL,
            visitor_token VARCHAR(64) NULL,
            external_visitor_id VARCHAR(64) NULL,
            source_url TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            last_message_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id),
            KEY idx_site (site_id),
            KEY idx_client (client_id),
            KEY idx_guest_email (guest_email),
            KEY idx_visitor_token (visitor_token),
            KEY idx_external_visitor (external_visitor_id),
            KEY idx_status (status),
            KEY idx_last_message (last_message_at)
        ) {$charset};";

        $sql_messages = "CREATE TABLE {$messages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ticket_id BIGINT UNSIGNED NOT NULL,
            sender_user_id BIGINT UNSIGNED NOT NULL,
            sender_type VARCHAR(20) NOT NULL,
            message LONGTEXT NOT NULL,
            telegram_sent TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_ticket (ticket_id),
            KEY idx_sender_user (sender_user_id),
            KEY idx_sender_type (sender_type)
        ) {$charset};";

        $sql_telegram_links = "CREATE TABLE {$telegram_links} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            chat_id VARCHAR(64) NOT NULL,
            telegram_message_id BIGINT UNSIGNED NOT NULL,
            ticket_id BIGINT UNSIGNED NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_chat_message (chat_id, telegram_message_id),
            KEY idx_ticket (ticket_id)
        ) {$charset};";

        $sql_sites = "CREATE TABLE {$sites} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(190) NOT NULL,
            site_url VARCHAR(255) NOT NULL,
            api_key VARCHAR(80) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_api_key (api_key),
            KEY idx_site_url (site_url),
            KEY idx_status (status)
        ) {$charset};";

        $sql_clients = "CREATE TABLE {$clients} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id BIGINT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_site_email (site_id, email),
            KEY idx_site (site_id),
            KEY idx_status (status)
        ) {$charset};";

        dbDelta($sql_tickets);
        dbDelta($sql_messages);
        dbDelta($sql_telegram_links);
        dbDelta($sql_sites);
        dbDelta($sql_clients);

        update_option(self::OPTION_DB_VERSION, self::DB_VERSION);
    }

    private function ensure_runtime_schema() {
        global $wpdb;

        $tickets = $this->get_tickets_table();
        $telegram_links = $this->get_telegram_links_table();
        $sites = $this->get_sites_table();
        $clients = $this->get_clients_table();

        $ticket_columns = $wpdb->get_col("SHOW COLUMNS FROM {$tickets}", 0);
        if (is_array($ticket_columns) && !empty($ticket_columns)) {
            if (!in_array('guest_name', $ticket_columns, true)) {
                $wpdb->query("ALTER TABLE {$tickets} ADD COLUMN guest_name VARCHAR(190) NULL AFTER subject");
            }
            if (!in_array('guest_email', $ticket_columns, true)) {
                $wpdb->query("ALTER TABLE {$tickets} ADD COLUMN guest_email VARCHAR(190) NULL AFTER guest_name");
            }
            if (!in_array('visitor_token', $ticket_columns, true)) {
                $wpdb->query("ALTER TABLE {$tickets} ADD COLUMN visitor_token VARCHAR(64) NULL AFTER guest_email");
            }
            if (!in_array('site_id', $ticket_columns, true)) {
                $wpdb->query("ALTER TABLE {$tickets} ADD COLUMN site_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id");
            }
            if (!in_array('client_id', $ticket_columns, true)) {
                $wpdb->query("ALTER TABLE {$tickets} ADD COLUMN client_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER site_id");
            }
            if (!in_array('external_visitor_id', $ticket_columns, true)) {
                $wpdb->query("ALTER TABLE {$tickets} ADD COLUMN external_visitor_id VARCHAR(64) NULL AFTER visitor_token");
            }
            if (!in_array('source_url', $ticket_columns, true)) {
                $wpdb->query("ALTER TABLE {$tickets} ADD COLUMN source_url TEXT NULL AFTER external_visitor_id");
            }
        }

        $telegram_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $telegram_links));
        if ($telegram_exists !== $telegram_links) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query(
                "CREATE TABLE {$telegram_links} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    chat_id VARCHAR(64) NOT NULL,
                    telegram_message_id BIGINT UNSIGNED NOT NULL,
                    ticket_id BIGINT UNSIGNED NOT NULL,
                    created_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_chat_message (chat_id, telegram_message_id),
                    KEY idx_ticket (ticket_id)
                ) {$charset}"
            );
        }

        $sites_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sites));
        if ($sites_exists !== $sites) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query(
                "CREATE TABLE {$sites} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    name VARCHAR(190) NOT NULL,
                    site_url VARCHAR(255) NOT NULL,
                    api_key VARCHAR(80) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_api_key (api_key),
                    KEY idx_site_url (site_url),
                    KEY idx_status (status)
                ) {$charset}"
            );
        }

        $clients_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $clients));
        if ($clients_exists !== $clients) {
            $charset = $wpdb->get_charset_collate();
            $wpdb->query(
                "CREATE TABLE {$clients} (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    site_id BIGINT UNSIGNED NOT NULL,
                    name VARCHAR(190) NOT NULL,
                    email VARCHAR(190) NOT NULL,
                    password_hash VARCHAR(255) NOT NULL,
                    status VARCHAR(20) NOT NULL DEFAULT 'active',
                    created_at DATETIME NOT NULL,
                    updated_at DATETIME NOT NULL,
                    PRIMARY KEY (id),
                    UNIQUE KEY uniq_site_email (site_id, email),
                    KEY idx_site (site_id),
                    KEY idx_status (status)
                ) {$charset}"
            );
        }
    }

    private function get_tickets_table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TICKETS;
    }

    private function get_messages_table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_MESSAGES;
    }

    private function get_telegram_links_table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_TELEGRAM_LINKS;
    }

    private function get_sites_table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SITES;
    }

    private function get_clients_table() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_CLIENTS;
    }

    public function register_admin_menu() {
        add_menu_page(
            'Support Chat Telegram',
            'Support Chat Telegram',
            'manage_options',
            'support-chat-telegram',
            [$this, 'render_admin_page'],
            'dashicons-format-chat',
            56
        );
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', self::TEXT_DOMAIN));
        }

        $ticket_id = isset($_GET['ticket_id']) ? absint(wp_unslash($_GET['ticket_id'])) : 0;
        $status_filter = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : 'all';

        echo '<div class="wrap">';
        echo '<h1>Support Chat Telegram</h1>';

        $this->render_admin_notices();
        $this->render_settings_box();
        $this->render_sites_box();

        if ($ticket_id > 0) {
            $this->render_single_ticket_admin($ticket_id);
        } else {
            $this->render_tickets_list_admin($status_filter);
        }

        echo '</div>';
    }

    private function render_admin_notices() {
        if (empty($_GET['support_chat_notice'])) {
            return;
        }

        $notice = sanitize_text_field(wp_unslash($_GET['support_chat_notice']));
        $map = [
            'settings_saved' => __('Настройки сохранены.', self::TEXT_DOMAIN),
            'reply_sent' => __('Ответ отправлен.', self::TEXT_DOMAIN),
            'status_changed' => __('Статус обновлён.', self::TEXT_DOMAIN),
            'ticket_created' => __('Тикет создан.', self::TEXT_DOMAIN),
            'message_sent' => __('Сообщение отправлено.', self::TEXT_DOMAIN),
            'quick_sent' => __('Сообщение отправлено в поддержку.', self::TEXT_DOMAIN),
            'registered' => __('Аккаунт создан. Вы вошли в систему.', self::TEXT_DOMAIN),
            'login_success' => __('Вы успешно вошли.', self::TEXT_DOMAIN),
            'login_error' => __('Неверный логин или пароль.', self::TEXT_DOMAIN),
            'error' => __('Произошла ошибка. Проверьте данные и повторите попытку.', self::TEXT_DOMAIN),
        ];

        if (!isset($map[$notice])) {
            return;
        }

        $class = ($notice === 'error') ? 'notice notice-error' : 'notice notice-success';
        echo '<div class="' . esc_attr($class) . '"><p>' . esc_html($map[$notice]) . '</p></div>';
    }

    private function render_settings_box() {
        $token = (string) get_option(self::OPTION_TELEGRAM_BOT_TOKEN, '');
        $chat_id = (string) get_option(self::OPTION_TELEGRAM_CHAT_ID, '');
        $webhook_secret = $this->get_or_create_webhook_secret();
        $allow_registration = (string) get_option(self::OPTION_ALLOW_REGISTRATION, 'yes');

        echo '<hr />';
        echo '<h2>Настройки Telegram</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('support_chat_save_settings', 'support_chat_nonce');
        echo '<input type="hidden" name="action" value="support_chat_save_settings" />';

        echo '<table class="form-table" role="presentation">';
        echo '<tr>';
        echo '<th scope="row"><label for="support_chat_bot_token">Bot Token</label></th>';
        echo '<td><input type="text" class="regular-text" id="support_chat_bot_token" name="bot_token" value="' . esc_attr($token) . '" /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="support_chat_id">Chat ID</label></th>';
        echo '<td><input type="text" class="regular-text" id="support_chat_id" name="chat_id" value="' . esc_attr($chat_id) . '" /></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row"><label for="support_chat_webhook_secret">Webhook Secret</label></th>';
        echo '<td><input type="text" class="regular-text" id="support_chat_webhook_secret" name="webhook_secret" value="' . esc_attr($webhook_secret) . '" />';
        echo '<p class="description">Передайте это значение в Telegram при setWebhook как secret_token.</p></td>';
        echo '</tr>';

        echo '<tr>';
        echo '<th scope="row">Регистрация клиентов</th>';
        echo '<td><label><input type="checkbox" name="allow_registration" value="yes" ' . checked($allow_registration, 'yes', false) . ' /> Разрешить регистрацию в виджете поддержки</label></td>';
        echo '</tr>';

        echo '</table>';
        submit_button('Сохранить настройки');
        $webhook_url = rest_url('support-chat/v1/telegram-webhook');
        echo '<p><strong>Webhook URL:</strong> <code>' . esc_html($webhook_url) . '</code></p>';
        echo '<p><strong>Secret Token:</strong> <code>' . esc_html($webhook_secret) . '</code></p>';
        echo '<p>Ответ оператору: используйте reply на сообщение бота в Telegram.</p>';
        echo '</form>';
    }

    private function render_sites_box() {
        if (!current_user_can('manage_options')) {
            return;
        }

        global $wpdb;
        $sites_table = $this->get_sites_table();
        $sites = $wpdb->get_results("SELECT * FROM {$sites_table} ORDER BY id DESC");

        echo '<hr />';
        echo '<h2>Внешние сайты (JS интеграция)</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-bottom:16px;">';
        wp_nonce_field('support_chat_add_site', 'support_chat_nonce');
        echo '<input type="hidden" name="action" value="support_chat_add_site" />';
        echo '<p><label>Название<br /><input type="text" name="name" required style="min-width:320px;" /></label></p>';
        echo '<p><label>URL сайта (например https://example.com)<br /><input type="url" name="site_url" required style="min-width:320px;" /></label></p>';
        submit_button('Добавить сайт', 'secondary', 'submit', false);
        echo '</form>';

        if (empty($sites)) {
            echo '<p>Сайты пока не добавлены.</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Сайт</th><th>URL</th><th>API Key</th><th>Сниппет</th></tr></thead><tbody>';
        foreach ($sites as $site) {
            $endpoint = untrailingslashit(site_url('/wp-json/support-chat/v1'));
            $script_url = plugins_url('assets/support-chat-embed.js', __FILE__);
            $snippet = '<script src="' . esc_url($script_url) . '" data-support-chat-endpoint="' . esc_attr($endpoint) . '" data-support-chat-key="' . esc_attr($site->api_key) . '" data-support-chat-lang="ru"></script>';
            echo '<tr>';
            echo '<td>' . (int) $site->id . '</td>';
            echo '<td>' . esc_html($site->name) . '</td>';
            echo '<td>' . esc_html($site->site_url) . '</td>';
            echo '<td><code>' . esc_html($site->api_key) . '</code></td>';
            echo '<td><textarea readonly rows="3" style="width:100%;">' . esc_textarea($snippet) . '</textarea></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function handle_add_site() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', self::TEXT_DOMAIN));
        }
        check_admin_referer('support_chat_add_site', 'support_chat_nonce');

        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $site_url = isset($_POST['site_url']) ? esc_url_raw(wp_unslash($_POST['site_url'])) : '';
        if ($name === '' || $site_url === '') {
            $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram'), 'error');
        }

        $parsed = wp_parse_url($site_url);
        if (empty($parsed['host'])) {
            $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram'), 'error');
        }

        global $wpdb;
        $table = $this->get_sites_table();
        $now = current_time('mysql');
        $api_key = 'sc_' . wp_generate_password(32, false, false);
        $ok = $wpdb->insert(
            $table,
            [
                'name' => $name,
                'site_url' => untrailingslashit($site_url),
                'api_key' => $api_key,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );

        $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram'), $ok ? 'settings_saved' : 'error');
    }

    private function render_tickets_list_admin($status_filter = 'all') {
        global $wpdb;

        $tickets_table = $this->get_tickets_table();
        $users_table = $wpdb->users;

        $where = '';
        $params = [];

        if (in_array($status_filter, ['open', 'closed'], true)) {
            $where = 'WHERE t.status = %s';
            $params[] = $status_filter;
        }

        $sql = "
            SELECT t.*, u.user_login, u.user_email
            FROM {$tickets_table} t
            LEFT JOIN {$users_table} u ON u.ID = t.user_id
            {$where}
            ORDER BY t.last_message_at DESC
            LIMIT 300
        ";

        if (!empty($params)) {
            $tickets = $wpdb->get_results($wpdb->prepare($sql, $params));
        } else {
            $tickets = $wpdb->get_results($sql);
        }

        $base_url = admin_url('admin.php?page=support-chat-telegram');

        echo '<hr />';
        echo '<h2>Тикеты клиентов</h2>';
        echo '<p>';
        echo '<a href="' . esc_url(add_query_arg(['status' => 'all'], $base_url)) . '">Все</a> | ';
        echo '<a href="' . esc_url(add_query_arg(['status' => 'open'], $base_url)) . '">Открытые</a> | ';
        echo '<a href="' . esc_url(add_query_arg(['status' => 'closed'], $base_url)) . '">Закрытые</a>';
        echo '</p>';

        if (empty($tickets)) {
            echo '<p>Тикетов пока нет.</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>ID</th><th>Тема</th><th>Клиент</th><th>Email</th><th>Статус</th><th>Обновлён</th><th></th>';
        echo '</tr></thead><tbody>';

        foreach ($tickets as $ticket) {
            $view_url = add_query_arg(['page' => 'support-chat-telegram', 'ticket_id' => (int) $ticket->id], admin_url('admin.php'));
            echo '<tr>';
            echo '<td>#' . (int) $ticket->id . '</td>';
            echo '<td>' . esc_html($ticket->subject) . '</td>';
            $client_name = $ticket->user_login ? $ticket->user_login : ($ticket->guest_name ? $ticket->guest_name : 'Guest');
            $client_email = $ticket->user_email ? $ticket->user_email : ($ticket->guest_email ? $ticket->guest_email : '-');
            echo '<td>' . esc_html($client_name) . '</td>';
            echo '<td>' . esc_html($client_email) . '</td>';
            echo '<td>' . esc_html($ticket->status) . '</td>';
            echo '<td>' . esc_html($ticket->last_message_at) . '</td>';
            echo '<td><a class="button" href="' . esc_url($view_url) . '">Открыть</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function render_single_ticket_admin($ticket_id) {
        $ticket = $this->get_ticket($ticket_id);

        if (!$ticket) {
            echo '<p>Тикет не найден.</p>';
            return;
        }

        $user = get_userdata((int) $ticket->user_id);
        $messages = $this->get_ticket_messages($ticket_id);

        echo '<hr />';
        echo '<h2>Тикет #' . (int) $ticket->id . ': ' . esc_html($ticket->subject) . '</h2>';

        if ($user) {
            echo '<p><strong>Клиент:</strong> ' . esc_html($user->user_login) . ' (' . esc_html($user->user_email) . ')</p>';
        } else {
            $guest_name = !empty($ticket->guest_name) ? $ticket->guest_name : 'Guest';
            $guest_email = !empty($ticket->guest_email) ? $ticket->guest_email : '-';
            echo '<p><strong>Клиент:</strong> ' . esc_html($guest_name) . ' (' . esc_html($guest_email) . ')</p>';
        }

        echo '<p><strong>Статус:</strong> ' . esc_html($ticket->status) . '</p>';

        $back_url = admin_url('admin.php?page=support-chat-telegram');
        echo '<p><a class="button" href="' . esc_url($back_url) . '">Назад к списку</a></p>';

        echo '<div style="max-width:900px;background:#fff;border:1px solid #ccd0d4;padding:16px;margin-top:10px;">';
        if (empty($messages)) {
            echo '<p>Сообщений пока нет.</p>';
        } else {
            foreach ($messages as $message) {
                $is_admin = ($message->sender_type === 'admin');
                echo '<div style="margin-bottom:14px;padding:10px;border-radius:8px;' . ($is_admin ? 'background:#f0f6ff;border:1px solid #cddffb;' : 'background:#f6f7f7;border:1px solid #dcdcde;') . '">';
                echo '<div style="font-size:12px;color:#555;">' . ($is_admin ? 'Админ' : 'Клиент') . ' · ' . esc_html($message->created_at) . '</div>';
                echo '<div style="white-space:pre-wrap;line-height:1.45;">' . esc_html($message->message) . '</div>';
                echo '</div>';
            }
        }
        echo '</div>';

        echo '<h3 style="margin-top:20px;">Ответить клиенту</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('support_chat_admin_reply_' . $ticket->id, 'support_chat_nonce');
        echo '<input type="hidden" name="action" value="support_chat_admin_reply" />';
        echo '<input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '" />';
        echo '<textarea name="message" rows="5" class="large-text" required></textarea>';
        submit_button('Отправить ответ');
        echo '</form>';

        echo '<h3>Изменить статус</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('support_chat_change_status_' . $ticket->id, 'support_chat_nonce');
        echo '<input type="hidden" name="action" value="support_chat_change_status" />';
        echo '<input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '" />';
        echo '<select name="status">';
        echo '<option value="open" ' . selected($ticket->status, 'open', false) . '>open</option>';
        echo '<option value="closed" ' . selected($ticket->status, 'closed', false) . '>closed</option>';
        echo '</select> ';
        submit_button('Сохранить статус', 'secondary', 'submit', false);
        echo '</form>';
    }

    public function handle_save_settings() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', self::TEXT_DOMAIN));
        }

        check_admin_referer('support_chat_save_settings', 'support_chat_nonce');

        $token = isset($_POST['bot_token']) ? sanitize_text_field(wp_unslash($_POST['bot_token'])) : '';
        $chat_id = isset($_POST['chat_id']) ? sanitize_text_field(wp_unslash($_POST['chat_id'])) : '';
        $webhook_secret = isset($_POST['webhook_secret']) ? sanitize_text_field(wp_unslash($_POST['webhook_secret'])) : '';
        $allow_registration = isset($_POST['allow_registration']) && wp_unslash($_POST['allow_registration']) === 'yes' ? 'yes' : 'no';

        if ($webhook_secret === '' || !preg_match('/^[A-Za-z0-9_-]{8,128}$/', $webhook_secret)) {
            $webhook_secret = $this->get_or_create_webhook_secret();
        }

        update_option(self::OPTION_TELEGRAM_BOT_TOKEN, $token);
        update_option(self::OPTION_TELEGRAM_CHAT_ID, $chat_id);
        update_option(self::OPTION_TELEGRAM_WEBHOOK_SECRET, $webhook_secret);
        update_option(self::OPTION_ALLOW_REGISTRATION, $allow_registration);

        $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram'), 'settings_saved');
    }

    private function get_or_create_webhook_secret() {
        $secret = (string) get_option(self::OPTION_TELEGRAM_WEBHOOK_SECRET, '');
        if ($secret !== '' && preg_match('/^[A-Za-z0-9_-]{8,128}$/', $secret)) {
            return $secret;
        }
        $secret = wp_generate_password(40, false, false);
        update_option(self::OPTION_TELEGRAM_WEBHOOK_SECRET, $secret);
        return $secret;
    }

    public function handle_create_ticket() {
        if (!is_user_logged_in()) {
            auth_redirect();
        }

        $redirect = $this->get_safe_redirect_url();

        check_admin_referer('support_chat_create_ticket', 'support_chat_nonce');

        $subject = isset($_POST['subject']) ? sanitize_text_field(wp_unslash($_POST['subject'])) : '';
        $message = isset($_POST['message']) ? wp_strip_all_tags(wp_unslash($_POST['message'])) : '';
        $message = trim($message);

        if ($subject === '' || $message === '') {
            $this->redirect_with_notice($redirect, 'error');
        }

        $ticket_id = $this->create_ticket(get_current_user_id(), $subject, $message);

        if (!$ticket_id) {
            $this->redirect_with_notice($redirect, 'error');
        }

        $sent = $this->notify_telegram($ticket_id, 'client', $message, get_current_user_id(), $subject);
        if ($sent) {
            $this->mark_latest_message_telegram_sent($ticket_id, get_current_user_id(), 'client', $message);
        }

        $target = add_query_arg(['ticket_id' => $ticket_id], $redirect);
        $this->redirect_with_notice($target, 'ticket_created');
    }

    public function handle_send_reply() {
        if (!is_user_logged_in()) {
            auth_redirect();
        }

        $redirect = $this->get_safe_redirect_url();

        check_admin_referer('support_chat_send_reply', 'support_chat_nonce');

        $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
        $message = isset($_POST['message']) ? wp_strip_all_tags(wp_unslash($_POST['message'])) : '';
        $message = trim($message);

        if ($ticket_id <= 0 || $message === '') {
            $this->redirect_with_notice($redirect, 'error');
        }

        $ticket = $this->get_ticket($ticket_id);

        if (!$ticket || (int) $ticket->user_id !== get_current_user_id()) {
            $this->redirect_with_notice($redirect, 'error');
        }

        if ($ticket->status !== 'open') {
            $this->redirect_with_notice($redirect, 'error');
        }

        $added = $this->add_message($ticket_id, get_current_user_id(), 'client', $message);

        if (!$added) {
            $this->redirect_with_notice($redirect, 'error');
        }

        $sent = $this->notify_telegram($ticket_id, 'client', $message, get_current_user_id(), $ticket->subject);
        if ($sent) {
            $this->mark_latest_message_telegram_sent($ticket_id, get_current_user_id(), 'client', $message);
        }

        $target = add_query_arg(['ticket_id' => $ticket_id], $redirect);
        $this->redirect_with_notice($target, 'message_sent');
    }

    public function handle_admin_reply() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', self::TEXT_DOMAIN));
        }

        $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
        check_admin_referer('support_chat_admin_reply_' . $ticket_id, 'support_chat_nonce');

        $message = isset($_POST['message']) ? wp_strip_all_tags(wp_unslash($_POST['message'])) : '';
        $message = trim($message);

        if ($ticket_id <= 0 || $message === '') {
            $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram&ticket_id=' . $ticket_id), 'error');
        }

        $ticket = $this->get_ticket($ticket_id);

        if (!$ticket) {
            $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram'), 'error');
        }

        $added = $this->add_message($ticket_id, get_current_user_id(), 'admin', $message);

        if (!$added) {
            $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram&ticket_id=' . $ticket_id), 'error');
        }

        if ($ticket->status !== 'open') {
            $this->update_ticket_status($ticket_id, 'open');
        }

        $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram&ticket_id=' . $ticket_id), 'reply_sent');
    }

    public function handle_change_status() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Access denied.', self::TEXT_DOMAIN));
        }

        $ticket_id = isset($_POST['ticket_id']) ? absint($_POST['ticket_id']) : 0;
        check_admin_referer('support_chat_change_status_' . $ticket_id, 'support_chat_nonce');

        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'open';

        if (!in_array($status, ['open', 'closed'], true)) {
            $status = 'open';
        }

        if ($ticket_id <= 0) {
            $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram'), 'error');
        }

        $this->update_ticket_status($ticket_id, $status);
        $this->redirect_with_notice(admin_url('admin.php?page=support-chat-telegram&ticket_id=' . $ticket_id), 'status_changed');
    }

    public function handle_register_user() {
        $redirect = $this->get_safe_redirect_url();
        check_admin_referer('support_chat_register_user', 'support_chat_nonce');

        $allow_registration = (string) get_option(self::OPTION_ALLOW_REGISTRATION, 'yes');

        if ($allow_registration !== 'yes') {
            $this->redirect_with_notice($redirect, 'error');
        }

        $username = isset($_POST['username']) ? sanitize_user(wp_unslash($_POST['username'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $invite_ticket_id = isset($_POST['invite_ticket_id']) ? absint(wp_unslash($_POST['invite_ticket_id'])) : 0;

        if ($username === '' || $email === '' || $password === '') {
            $this->redirect_with_notice($redirect, 'error');
        }

        if (!is_email($email) || username_exists($username) || email_exists($email)) {
            $this->redirect_with_notice($redirect, 'error');
        }

        $user_id = wp_create_user($username, $password, $email);

        if (is_wp_error($user_id) || !$user_id) {
            $this->redirect_with_notice($redirect, 'error');
        }

        wp_set_current_user($user_id);
        wp_set_auth_cookie($user_id);

        if ($invite_ticket_id > 0) {
            $ticket = $this->get_ticket($invite_ticket_id);
            if ($ticket && (int) $ticket->user_id === 0 && !empty($ticket->guest_email) && strtolower((string) $ticket->guest_email) === strtolower($email)) {
                global $wpdb;
                $tickets_table = $this->get_tickets_table();
                $wpdb->update(
                    $tickets_table,
                    [
                        'user_id' => (int) $user_id,
                        'updated_at' => current_time('mysql'),
                    ],
                    ['id' => (int) $invite_ticket_id],
                    ['%d', '%s'],
                    ['%d']
                );
            }
        }

        $this->redirect_with_notice($redirect, 'registered');
    }

    public function handle_login_user() {
        $redirect = $this->get_safe_redirect_url();
        check_admin_referer('support_chat_login_user', 'support_chat_nonce');

        $login = isset($_POST['login']) ? sanitize_text_field(wp_unslash($_POST['login'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        $remember = isset($_POST['remember']) && wp_unslash($_POST['remember']) === '1';

        if ($login === '' || $password === '') {
            $this->redirect_with_notice($redirect, 'login_error');
        }

        $creds = [
            'user_login' => $login,
            'user_password' => $password,
            'remember' => $remember,
        ];

        $user = wp_signon($creds, is_ssl());
        if (is_wp_error($user)) {
            $this->redirect_with_notice($redirect, 'login_error');
        }

        wp_set_current_user((int) $user->ID);
        $this->redirect_with_notice($redirect, 'login_success');
    }

    public function handle_quick_message() {
        $redirect = $this->get_safe_redirect_url();
        check_admin_referer('support_chat_quick_message', 'support_chat_nonce');

        $message = isset($_POST['message']) ? wp_strip_all_tags(wp_unslash($_POST['message'])) : '';
        $message = trim($message);
        $source_url = isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '';

        if ($message === '') {
            $this->redirect_with_notice($redirect, 'error');
        }

        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $ticket_id = $this->append_user_chat_message($user_id, $message);

            if (!$ticket_id) {
                $this->redirect_with_notice($redirect, 'error');
            }

            $sent = $this->notify_telegram($ticket_id, 'client', $message, $user_id, 'Обращение из онлайн-чата');
            if ($sent) {
                $this->mark_latest_message_telegram_sent($ticket_id, $user_id, 'client', $message);
            }

            $target = add_query_arg(['ticket_id' => $ticket_id], $redirect);
            $this->redirect_with_notice($target, 'ticket_created');
        }

        $guest_name = isset($_POST['guest_name']) ? sanitize_text_field(wp_unslash($_POST['guest_name'])) : '';
        $guest_email = isset($_POST['guest_email']) ? sanitize_email(wp_unslash($_POST['guest_email'])) : '';

        if ($guest_name === '' || $guest_email === '' || !is_email($guest_email)) {
            $this->redirect_with_notice($redirect, 'error');
        }

        $ticket_id = $this->append_guest_chat_message($guest_name, $guest_email, $message, $source_url);
        if (!$ticket_id) {
            $this->redirect_with_notice($redirect, 'error');
        }

        $sent = $this->send_telegram_ticket_message($ticket_id, $guest_name, $guest_email, $message, 'Онлайн-чат (гость)');
        if ($sent) {
            $this->mark_latest_message_telegram_sent($ticket_id, 0, 'client', $message);
        }

        $target = add_query_arg(['ticket_id' => $ticket_id], $redirect);
        $this->redirect_with_notice($target, 'quick_sent');
    }

    private function create_ticket($user_id, $subject, $first_message, $guest_name = '', $guest_email = '', $visitor_token = '') {
        return $this->create_ticket_with_meta($user_id, 0, 0, $subject, $first_message, $guest_name, $guest_email, $visitor_token, '', '');
    }

    private function create_ticket_with_meta($user_id, $site_id, $client_id, $subject, $first_message, $guest_name = '', $guest_email = '', $visitor_token = '', $external_visitor_id = '', $source_url = '') {
        global $wpdb;

        $tickets_table = $this->get_tickets_table();
        $now = current_time('mysql');

        $created = $wpdb->insert(
            $tickets_table,
            [
                'user_id' => (int) $user_id,
                'site_id' => (int) $site_id,
                'client_id' => (int) $client_id,
                'subject' => $subject,
                'guest_name' => $guest_name !== '' ? $guest_name : null,
                'guest_email' => $guest_email !== '' ? $guest_email : null,
                'visitor_token' => $visitor_token !== '' ? $visitor_token : null,
                'external_visitor_id' => $external_visitor_id !== '' ? $external_visitor_id : null,
                'source_url' => $source_url !== '' ? $source_url : null,
                'status' => 'open',
                'created_at' => $now,
                'updated_at' => $now,
                'last_message_at' => $now,
            ],
            ['%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        if (!$created) {
            return 0;
        }

        $ticket_id = (int) $wpdb->insert_id;

        $added = $this->add_message($ticket_id, $user_id, 'client', $first_message);
        if (!$added) {
            return 0;
        }

        return $ticket_id;
    }

    private function append_user_chat_message($user_id, $message, $force_new = false, &$client_message_id = 0) {
        global $wpdb;

        $tickets_table = $this->get_tickets_table();
        if ($force_new) {
            $ticket_id = $this->create_ticket($user_id, 'Онлайн-чат', $message);
            $client_message_id = $ticket_id > 0 ? $this->get_last_message_id($ticket_id, 'client') : 0;
            return $ticket_id;
        }

        $ticket_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$tickets_table} WHERE user_id = %d AND status = 'open' ORDER BY last_message_at DESC LIMIT 1",
                (int) $user_id
            )
        );

        if ($ticket_id <= 0) {
            $ticket_id = $this->create_ticket($user_id, 'Онлайн-чат', $message);
            $client_message_id = $ticket_id > 0 ? $this->get_last_message_id($ticket_id, 'client') : 0;
            return $ticket_id;
        }

        $added = $this->add_message($ticket_id, $user_id, 'client', $message, $client_message_id);
        return $added ? $ticket_id : 0;
    }

    private function append_guest_chat_message($guest_name, $guest_email, $message, $source_url = '', $force_new = false, &$client_message_id = 0, $visitor_token_override = '') {
        global $wpdb;
        $this->ensure_runtime_schema();

        $tickets_table = $this->get_tickets_table();
        $visitor_token = $visitor_token_override !== '' ? $visitor_token_override : $this->get_or_set_visitor_token();
        $ticket_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$tickets_table} WHERE visitor_token = %s AND status = 'open' ORDER BY last_message_at DESC LIMIT 1",
                $visitor_token
            )
        );

        if ($source_url !== '') {
            $message = $message . "\n\n" . 'Страница: ' . $source_url;
        }

        if ($force_new) {
            $ticket_id = $this->create_ticket(0, 'Онлайн-чат (гость)', $message, $guest_name, $guest_email, $visitor_token);
            $client_message_id = $ticket_id > 0 ? $this->get_last_message_id($ticket_id, 'client') : 0;
            return $ticket_id;
        }

        if ($ticket_id <= 0) {
            $ticket_id = $this->create_ticket(0, 'Онлайн-чат (гость)', $message, $guest_name, $guest_email, $visitor_token);
            $client_message_id = $ticket_id > 0 ? $this->get_last_message_id($ticket_id, 'client') : 0;
            return $ticket_id;
        }

        $wpdb->update(
            $tickets_table,
            [
                'guest_name' => $guest_name,
                'guest_email' => $guest_email,
            ],
            ['id' => $ticket_id],
            ['%s', '%s'],
            ['%d']
        );

        $added = $this->add_message($ticket_id, 0, 'client', $message, $client_message_id);
        return $added ? $ticket_id : 0;
    }

    private function add_message($ticket_id, $sender_user_id, $sender_type, $message, &$created_message_id = 0) {
        global $wpdb;

        $messages_table = $this->get_messages_table();
        $tickets_table = $this->get_tickets_table();

        $now = current_time('mysql');

        $ok = $wpdb->insert(
            $messages_table,
            [
                'ticket_id' => (int) $ticket_id,
                'sender_user_id' => (int) $sender_user_id,
                'sender_type' => $sender_type,
                'message' => $message,
                'telegram_sent' => 0,
                'created_at' => $now,
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s']
        );

        if (!$ok) {
            return false;
        }
        $created_message_id = (int) $wpdb->insert_id;

        $wpdb->update(
            $tickets_table,
            [
                'updated_at' => $now,
                'last_message_at' => $now,
            ],
            ['id' => (int) $ticket_id],
            ['%s', '%s'],
            ['%d']
        );

        return true;
    }

    private function get_last_message_id($ticket_id, $sender_type = 'client') {
        global $wpdb;
        $messages_table = $this->get_messages_table();
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$messages_table} WHERE ticket_id = %d AND sender_type = %s ORDER BY id DESC LIMIT 1",
                (int) $ticket_id,
                (string) $sender_type
            )
        );
    }

    private function has_admin_reply_after($ticket_id, $client_message_id) {
        global $wpdb;
        $messages_table = $this->get_messages_table();
        $count = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$messages_table} WHERE ticket_id = %d AND sender_type = 'admin' AND id > %d",
                (int) $ticket_id,
                (int) $client_message_id
            )
        );
        return $count > 0;
    }

    private function normalize_lang_key($lang) {
        $lang = strtolower((string) $lang);
        if (strpos($lang, '_') !== false) {
            $lang = explode('_', $lang)[0];
        }
        if (strpos($lang, '-') !== false) {
            $lang = explode('-', $lang)[0];
        }
        if (!in_array($lang, ['ru', 'en', 'et'], true)) {
            return 'ru';
        }
        return $lang;
    }

    private function is_within_working_hours_utc2() {
        try {
            $tz = new DateTimeZone('Europe/Tallinn');
            $now = new DateTime('now', $tz);
            $day = (int) $now->format('N');
            $hour = (int) $now->format('G');
            if ($day >= 6) {
                return false;
            }
            return $hour >= 9 && $hour < 18;
        } catch (Exception $e) {
            return true;
        }
    }

    private function get_auto_busy_message($lang = 'ru') {
        $lang = $this->normalize_lang_key($lang);
        $map = [
            'ru' => 'Извините, все операторы сейчас заняты. Мы пришлём ответ на вашу почту, как только специалист освободится.',
            'en' => 'Sorry, all operators are currently busy. We will send a reply to your email as soon as a specialist is available.',
            'et' => 'Vabandame, kõik operaatorid on hetkel hõivatud. Saadame vastuse teie e-postile niipea, kui spetsialist vabaneb.',
        ];
        return isset($map[$lang]) ? $map[$lang] : $map['ru'];
    }

    private function maybe_add_auto_message($ticket_id, $client_message_id, $lang = 'ru') {
        if ($ticket_id <= 0 || $client_message_id <= 0) {
            return;
        }
        if ($this->has_admin_reply_after($ticket_id, $client_message_id)) {
            return;
        }

        $text = $this->get_auto_busy_message($lang);
        global $wpdb;
        $messages_table = $this->get_messages_table();
        $last_admin = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT message, created_at FROM {$messages_table} WHERE ticket_id = %d AND sender_type = 'admin' ORDER BY id DESC LIMIT 1",
                (int) $ticket_id
            )
        );
        if ($last_admin && (string) $last_admin->message === $text) {
            $last_ts = strtotime((string) $last_admin->created_at);
            if ($last_ts && (time() - $last_ts) < 600) {
                return;
            }
        }

        $this->add_message((int) $ticket_id, 0, 'admin', $text);
    }

    private function schedule_unanswered_followup($ticket_id, $client_message_id, $lang = 'ru') {
        if ($ticket_id <= 0 || $client_message_id <= 0) {
            return;
        }
        $args = [(int) $ticket_id, (int) $client_message_id, $this->normalize_lang_key($lang)];
        if (!wp_next_scheduled('support_chat_check_unanswered', $args)) {
            wp_schedule_single_event(time() + 120, 'support_chat_check_unanswered', $args);
        }
    }

    public function handle_unanswered_followup($ticket_id, $client_message_id, $lang = 'ru') {
        $this->ensure_runtime_schema();
        $ticket_id = (int) $ticket_id;
        $client_message_id = (int) $client_message_id;
        if ($ticket_id <= 0 || $client_message_id <= 0) {
            return;
        }
        $ticket = $this->get_ticket($ticket_id);
        if (!$ticket || (string) $ticket->status !== 'open') {
            return;
        }
        $this->maybe_add_auto_message($ticket_id, $client_message_id, $lang);
    }

    private function update_ticket_status($ticket_id, $status) {
        global $wpdb;

        $tickets_table = $this->get_tickets_table();
        $now = current_time('mysql');

        $wpdb->update(
            $tickets_table,
            [
                'status' => $status,
                'updated_at' => $now,
            ],
            ['id' => (int) $ticket_id],
            ['%s', '%s'],
            ['%d']
        );
    }

    private function mark_latest_message_telegram_sent($ticket_id, $sender_user_id, $sender_type, $message) {
        global $wpdb;

        $messages_table = $this->get_messages_table();

        $message_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                 FROM {$messages_table}
                 WHERE ticket_id = %d
                   AND sender_user_id = %d
                   AND sender_type = %s
                   AND message = %s
                 ORDER BY id DESC
                 LIMIT 1",
                (int) $ticket_id,
                (int) $sender_user_id,
                $sender_type,
                $message
            )
        );

        if (!$message_id) {
            return;
        }

        $wpdb->update(
            $messages_table,
            ['telegram_sent' => 1],
            ['id' => (int) $message_id],
            ['%d'],
            ['%d']
        );
    }

    private function get_ticket($ticket_id) {
        global $wpdb;

        $tickets_table = $this->get_tickets_table();

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tickets_table} WHERE id = %d",
                $ticket_id
            )
        );
    }

    private function get_user_tickets($user_id) {
        global $wpdb;

        $tickets_table = $this->get_tickets_table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tickets_table} WHERE user_id = %d ORDER BY (status = 'open') DESC, last_message_at DESC",
                $user_id
            )
        );
    }

    private function get_visitor_tickets($visitor_token) {
        global $wpdb;
        $tickets_table = $this->get_tickets_table();

        if ($visitor_token === '') {
            return [];
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$tickets_table} WHERE visitor_token = %s ORDER BY (status = 'open') DESC, last_message_at DESC",
                $visitor_token
            )
        );
    }

    private function get_ticket_messages($ticket_id) {
        global $wpdb;

        $messages_table = $this->get_messages_table();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$messages_table} WHERE ticket_id = %d ORDER BY created_at ASC",
                $ticket_id
            )
        );
    }

    private function get_or_set_visitor_token() {
        $cookie_name = 'support_chat_visitor';
        $existing = isset($_COOKIE[$cookie_name]) ? sanitize_text_field(wp_unslash($_COOKIE[$cookie_name])) : '';
        if ($existing !== '' && preg_match('/^[A-Za-z0-9_-]{20,128}$/', $existing)) {
            return $existing;
        }

        $token = wp_generate_uuid4();
        setcookie($cookie_name, $token, time() + (YEAR_IN_SECONDS * 2), COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
        $_COOKIE[$cookie_name] = $token;

        return $token;
    }

    private function can_current_visitor_access_ticket($ticket, $visitor_token = '') {
        if (!$ticket) {
            return false;
        }

        if (is_user_logged_in()) {
            return (int) $ticket->user_id === get_current_user_id();
        }

        if ($visitor_token === '') {
            $visitor_token = $this->get_or_set_visitor_token();
        }
        return isset($ticket->visitor_token) && hash_equals((string) $ticket->visitor_token, (string) $visitor_token);
    }

    private function resolve_widget_visitor_token() {
        $from_request = isset($_POST['visitor_id']) ? sanitize_text_field((string) wp_unslash($_POST['visitor_id'])) : '';
        if ($from_request !== '' && preg_match('/^[A-Za-z0-9_-]{16,128}$/', $from_request)) {
            return $from_request;
        }
        return $this->get_or_set_visitor_token();
    }

    private function resolve_guest_identity($guest_name, $guest_email, $seed = '') {
        $name = sanitize_text_field((string) $guest_name);
        $email = sanitize_email((string) $guest_email);

        if ($name === '') {
            $name = 'Guest';
        }
        if ($email === '' || !is_email($email)) {
            $raw_seed = strtolower((string) $seed);
            $safe_seed = preg_replace('/[^a-z0-9]/', '', $raw_seed);
            if ($safe_seed === '') {
                $safe_seed = strtolower(wp_generate_password(10, false, false));
            }
            $email = 'guest+' . substr($safe_seed, 0, 24) . '@guest.local';
        }

        return [$name, $email];
    }

    private function format_messages_for_client($messages) {
        $rows = [];
        foreach ($messages as $message) {
            $rows[] = [
                'id' => (int) $message->id,
                'sender_type' => (string) $message->sender_type,
                'message' => (string) $message->message,
                'created_at' => (string) $message->created_at,
            ];
        }

        return $rows;
    }

    public function ajax_widget_state() {
        $this->ensure_runtime_schema();
        $requested_ticket_id = isset($_POST['ticket_id']) ? absint(wp_unslash($_POST['ticket_id'])) : 0;
        $visitor_token = $this->resolve_widget_visitor_token();
        $ticket = null;
        $tickets_for_list = [];

        if ($requested_ticket_id > 0) {
            $candidate = $this->get_ticket($requested_ticket_id);
            if ($this->can_current_visitor_access_ticket($candidate, $visitor_token)) {
                $ticket = $candidate;
            }
        }

        if ($ticket) {
            $messages = $this->format_messages_for_client($this->get_ticket_messages((int) $ticket->id));
            wp_send_json_success([
                'nonce' => wp_create_nonce('support_chat_widget'),
                'ticket_id' => (int) $ticket->id,
                'status' => (string) $ticket->status,
                'tickets' => array_map([$this, 'format_ticket_for_client'], $this->collect_current_visitor_tickets()),
                'viewer' => $this->get_current_widget_viewer(),
                'messages' => $messages,
            ]);
        }

        if (is_user_logged_in()) {
            $tickets_for_list = $this->get_user_tickets(get_current_user_id());
            if (!empty($tickets_for_list)) {
                $ticket = $tickets_for_list[0];
            }
        } else {
            $tickets_for_list = $this->get_visitor_tickets($visitor_token);
            if (!empty($tickets_for_list)) {
                $ticket = $tickets_for_list[0];
            }
        }

        $messages = [];
        if ($ticket) {
            $messages = $this->format_messages_for_client($this->get_ticket_messages((int) $ticket->id));
        }

        wp_send_json_success([
            'nonce' => wp_create_nonce('support_chat_widget'),
            'ticket_id' => $ticket ? (int) $ticket->id : 0,
            'status' => $ticket ? (string) $ticket->status : 'new',
            'tickets' => array_map([$this, 'format_ticket_for_client'], $tickets_for_list),
            'viewer' => $this->get_current_widget_viewer(),
            'messages' => $messages,
        ]);
    }

    public function ajax_widget_send() {
        $this->ensure_runtime_schema();
        if (!check_ajax_referer('support_chat_widget', 'nonce', false)) {
            wp_send_json_error(['error' => 'invalid_nonce', 'nonce' => wp_create_nonce('support_chat_widget')], 403);
        }
        if (!$this->check_rate_limit('widget_send', 20, 60)) {
            wp_send_json_error(['error' => 'rate_limited'], 429);
        }

        $message = isset($_POST['message']) ? wp_strip_all_tags(wp_unslash($_POST['message'])) : '';
        $message = trim($message);
        $lang = isset($_POST['lang']) ? sanitize_text_field((string) wp_unslash($_POST['lang'])) : '';
        if ($lang === '') {
            $lang = (string) get_locale();
        }
        $lang = $this->normalize_lang_key($lang);
        $visitor_token = $this->resolve_widget_visitor_token();
        $force_new_ticket = isset($_POST['force_new_ticket']) && (int) $_POST['force_new_ticket'] === 1;
        if ($message === '') {
            wp_send_json_error(['error' => 'empty_message'], 400);
        }

        $ticket_id = 0;
        $client_message_id = 0;
        $subject = 'Онлайн-чат';
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $ticket_id = $this->append_user_chat_message($user_id, $message, $force_new_ticket, $client_message_id);
            $subject = 'Онлайн-чат';
            if ($ticket_id > 0) {
                $sent = $this->notify_telegram($ticket_id, 'client', $message, $user_id, $subject);
                if ($sent) {
                    $this->mark_latest_message_telegram_sent($ticket_id, $user_id, 'client', $message);
                }
            }
        } else {
            $guest_name = isset($_POST['guest_name']) ? sanitize_text_field(wp_unslash($_POST['guest_name'])) : '';
            $guest_email = isset($_POST['guest_email']) ? sanitize_email(wp_unslash($_POST['guest_email'])) : '';
            [$guest_name, $guest_email] = $this->resolve_guest_identity($guest_name, $guest_email, $visitor_token);

            $ticket_id = $this->append_guest_chat_message($guest_name, $guest_email, $message, '', $force_new_ticket, $client_message_id, $visitor_token);
            $subject = 'Онлайн-чат (гость)';
            if ($ticket_id > 0) {
                $sent = $this->send_telegram_ticket_message($ticket_id, $guest_name, $guest_email, $message, $subject);
                if ($sent) {
                    $this->mark_latest_message_telegram_sent($ticket_id, 0, 'client', $message);
                }
            }
        }

        if ($ticket_id <= 0) {
            wp_send_json_error(['error' => 'send_failed'], 500);
        }

        if ($this->is_within_working_hours_utc2()) {
            $this->schedule_unanswered_followup($ticket_id, $client_message_id, $lang);
        } else {
            $this->maybe_add_auto_message($ticket_id, $client_message_id, $lang);
        }

        $ticket = $this->get_ticket($ticket_id);
        $messages = $this->format_messages_for_client($this->get_ticket_messages($ticket_id));
        wp_send_json_success([
            'ticket_id' => (int) $ticket_id,
            'status' => $ticket ? (string) $ticket->status : 'open',
            'messages' => $messages,
        ]);
    }

    private function format_ticket_for_client($ticket) {
        return [
            'id' => (int) $ticket->id,
            'subject' => (string) $ticket->subject,
            'status' => (string) $ticket->status,
            'last_message_at' => (string) $ticket->last_message_at,
        ];
    }

    private function collect_current_visitor_tickets() {
        if (is_user_logged_in()) {
            return $this->get_user_tickets(get_current_user_id());
        }

        $visitor_token = $this->get_or_set_visitor_token();
        return $this->get_visitor_tickets($visitor_token);
    }

    private function get_current_widget_viewer() {
        if (is_user_logged_in()) {
            $user = wp_get_current_user();
            return [
                'is_logged_in' => true,
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
            ];
        }

        return [
            'is_logged_in' => false,
            'name' => '',
            'email' => '',
        ];
    }

    public function ajax_widget_login() {
        $this->ensure_runtime_schema();
        if (!check_ajax_referer('support_chat_widget', 'nonce', false)) {
            wp_send_json_error(['error' => 'invalid_nonce', 'nonce' => wp_create_nonce('support_chat_widget')], 403);
        }
        if (!$this->check_rate_limit('widget_login', 10, 300)) {
            wp_send_json_error(['error' => 'rate_limited'], 429);
        }

        $login = isset($_POST['login']) ? sanitize_text_field(wp_unslash($_POST['login'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        if ($login === '' || $password === '') {
            wp_send_json_error(['error' => 'login_required'], 400);
        }

        $user = wp_signon(
            [
                'user_login' => $login,
                'user_password' => $password,
                'remember' => true,
            ],
            is_ssl()
        );

        if (is_wp_error($user)) {
            wp_send_json_error(['error' => 'invalid_credentials'], 401);
        }

        wp_set_current_user((int) $user->ID);
        wp_send_json_success([
            'viewer' => [
                'is_logged_in' => true,
                'name' => (string) $user->display_name,
                'email' => (string) $user->user_email,
            ],
            'nonce' => wp_create_nonce('support_chat_widget'),
        ]);
    }

    public function ajax_widget_register() {
        $this->ensure_runtime_schema();
        if (!check_ajax_referer('support_chat_widget', 'nonce', false)) {
            wp_send_json_error(['error' => 'invalid_nonce', 'nonce' => wp_create_nonce('support_chat_widget')], 403);
        }
        if (!$this->check_rate_limit('widget_register', 5, 600)) {
            wp_send_json_error(['error' => 'rate_limited'], 429);
        }

        $allow_registration = (string) get_option(self::OPTION_ALLOW_REGISTRATION, 'yes');
        if ($allow_registration !== 'yes') {
            wp_send_json_error(['error' => 'registration_disabled'], 403);
        }

        $username = isset($_POST['username']) ? sanitize_user(wp_unslash($_POST['username'])) : '';
        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
        $password = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';

        if ($username === '' || $email === '' || $password === '' || !is_email($email) || strlen($password) < 6) {
            wp_send_json_error(['error' => 'invalid_payload'], 400);
        }
        if (username_exists($username) || email_exists($email)) {
            wp_send_json_error(['error' => 'already_exists'], 409);
        }

        $user_id = wp_create_user($username, $password, $email);
        if (is_wp_error($user_id) || !$user_id) {
            wp_send_json_error(['error' => 'register_failed'], 500);
        }

        wp_set_current_user((int) $user_id);
        wp_set_auth_cookie((int) $user_id, true);
        $user = get_user_by('id', (int) $user_id);

        wp_send_json_success([
            'viewer' => [
                'is_logged_in' => true,
                'name' => $user ? (string) $user->display_name : '',
                'email' => $user ? (string) $user->user_email : '',
            ],
            'nonce' => wp_create_nonce('support_chat_widget'),
        ]);
    }

    public function ajax_widget_logout() {
        $nonce = isset($_POST['nonce']) ? sanitize_text_field((string) wp_unslash($_POST['nonce'])) : '';
        if ($nonce !== '' && !wp_verify_nonce($nonce, 'support_chat_widget')) {
            wp_send_json_error(['error' => 'invalid_nonce'], 403);
        }

        wp_logout();
        wp_clear_auth_cookie();
        if (function_exists('wp_destroy_current_session')) {
            wp_destroy_current_session();
        }

        $path_candidates = array_unique(array_filter([
            '/',
            (defined('COOKIEPATH') && COOKIEPATH) ? COOKIEPATH : '',
            (defined('SITECOOKIEPATH') && SITECOOKIEPATH) ? SITECOOKIEPATH : '',
            (defined('ADMIN_COOKIE_PATH') && ADMIN_COOKIE_PATH) ? ADMIN_COOKIE_PATH : '',
            (defined('PLUGINS_COOKIE_PATH') && PLUGINS_COOKIE_PATH) ? PLUGINS_COOKIE_PATH : '',
        ]));
        $domain_candidates = array_unique([
            '',
            (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) ? COOKIE_DOMAIN : '',
        ]);
        $cookie_names = array_unique(array_filter([
            'support_chat_visitor',
            defined('AUTH_COOKIE') ? AUTH_COOKIE : '',
            defined('SECURE_AUTH_COOKIE') ? SECURE_AUTH_COOKIE : '',
            defined('LOGGED_IN_COOKIE') ? LOGGED_IN_COOKIE : '',
            defined('COOKIEHASH') ? ('wordpress_logged_in_' . COOKIEHASH) : '',
            defined('COOKIEHASH') ? ('wordpress_sec_' . COOKIEHASH) : '',
            defined('COOKIEHASH') ? ('wordpress_' . COOKIEHASH) : '',
        ]));

        if (!headers_sent()) {
            foreach ($cookie_names as $cookie_name) {
                foreach ($path_candidates as $cookie_path) {
                    foreach ($domain_candidates as $cookie_domain) {
                        setcookie($cookie_name, '', time() - 3600, $cookie_path, $cookie_domain, false, true);
                        setcookie($cookie_name, '', time() - 3600, $cookie_path, $cookie_domain, true, true);
                    }
                }
            }
        }

        foreach ($cookie_names as $cookie_name) {
            if (isset($_COOKIE[$cookie_name])) {
                unset($_COOKIE[$cookie_name]);
            }
        }

        wp_send_json_success(['ok' => true]);
    }

    public function ajax_widget_open() {
        $this->ensure_runtime_schema();
        if (!check_ajax_referer('support_chat_widget', 'nonce', false)) {
            wp_send_json_error(['error' => 'invalid_nonce', 'nonce' => wp_create_nonce('support_chat_widget')], 403);
        }
        if (!$this->check_rate_limit('widget_open', 20, 300)) {
            wp_send_json_success(['ok' => true]);
        }

        $page_url = isset($_POST['page_url']) ? esc_url_raw((string) wp_unslash($_POST['page_url'])) : '';
        $viewer = is_user_logged_in() ? wp_get_current_user() : null;
        $title = 'Пользователь открыл чат';
        if ($viewer && !empty($viewer->ID)) {
            $title = 'Пользователь открыл чат (авторизован)';
        }
        $text = $title . "\n";
        if ($viewer && !empty($viewer->ID)) {
            $text .= 'Пользователь: ' . (string) $viewer->user_login . "\n";
            $text .= 'Email: ' . (string) $viewer->user_email . "\n";
        } else {
            $text .= 'Пользователь: гость' . "\n";
        }
        if ($page_url !== '') {
            $text .= 'Страница: ' . $page_url . "\n";
        }
        $this->send_telegram_plain($text, 0);
        wp_send_json_success(['ok' => true, 'nonce' => wp_create_nonce('support_chat_widget')]);
    }

    public function register_rest_routes() {
        register_rest_route('support-chat/v1', '/telegram-webhook', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_telegram_webhook'],
        ]);
        register_rest_route('support-chat/v1', '/external/state', [
            'methods' => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_external_state'],
        ]);
        register_rest_route('support-chat/v1', '/external/send', [
            'methods' => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_external_send'],
        ]);
        register_rest_route('support-chat/v1', '/external/register', [
            'methods' => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_external_register'],
        ]);
        register_rest_route('support-chat/v1', '/external/login', [
            'methods' => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_external_login'],
        ]);
        register_rest_route('support-chat/v1', '/external/tickets', [
            'methods' => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_external_tickets'],
        ]);
        register_rest_route('support-chat/v1', '/external/open', [
            'methods' => ['POST', 'OPTIONS'],
            'permission_callback' => '__return_true',
            'callback' => [$this, 'rest_external_open'],
        ]);
    }

    public function rest_cors_headers($served, $result, $request, $server) {
        $route = method_exists($request, 'get_route') ? (string) $request->get_route() : '';
        if (strpos($route, '/support-chat/v1/external/') === 0) {
            $origin = isset($_SERVER['HTTP_ORIGIN']) ? esc_url_raw((string) wp_unslash($_SERVER['HTTP_ORIGIN'])) : '';
            if ($this->is_origin_whitelisted($origin)) {
                header('Access-Control-Allow-Origin: ' . $origin);
                header('Access-Control-Allow-Methods: POST, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type');
                header('Vary: Origin');
            }
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                if (!$this->is_origin_whitelisted($origin)) {
                    status_header(403);
                }
                echo '';
                return true;
            }
        }
        return $served;
    }

    private function is_origin_whitelisted($origin) {
        if ($origin === '') {
            return false;
        }
        $origin_host = wp_parse_url($origin, PHP_URL_HOST);
        if (!$origin_host) {
            return false;
        }

        global $wpdb;
        $table = $this->get_sites_table();
        $sites = $wpdb->get_col("SELECT site_url FROM {$table} WHERE status = 'active'");
        if (empty($sites) || !is_array($sites)) {
            return false;
        }

        foreach ($sites as $site_url) {
            $site_host = wp_parse_url((string) $site_url, PHP_URL_HOST);
            if ($site_host && strtolower((string) $site_host) === strtolower((string) $origin_host)) {
                return true;
            }
        }

        return false;
    }

    private function get_request_ip() {
        $candidates = [
            isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? (string) wp_unslash($_SERVER['HTTP_CF_CONNECTING_IP']) : '',
            isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string) wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']) : '',
            isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : '',
        ];
        foreach ($candidates as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $first = trim(explode(',', $candidate)[0]);
            if ($first !== '') {
                return preg_replace('/[^0-9a-fA-F\:\.]/', '', $first);
            }
        }
        return 'unknown';
    }

    private function check_rate_limit($scope, $limit, $window_seconds) {
        $scope = sanitize_key((string) $scope);
        $limit = max(1, (int) $limit);
        $window_seconds = max(10, (int) $window_seconds);
        $bucket = (int) floor(time() / $window_seconds);
        $key = 'support_chat_rl_' . md5($scope . '|' . $this->get_request_ip() . '|' . $bucket);
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, $window_seconds + 5);
        return true;
    }

    private function get_site_by_api_key($api_key) {
        global $wpdb;
        $table = $this->get_sites_table();
        if ($api_key === '') {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE api_key = %s AND status = 'active' LIMIT 1",
                $api_key
            )
        );
    }

    private function is_origin_allowed_for_site($origin, $site_url) {
        if ($origin === '' || $site_url === '') {
            return false;
        }
        $origin_host = wp_parse_url($origin, PHP_URL_HOST);
        $site_host = wp_parse_url($site_url, PHP_URL_HOST);
        if (!$origin_host || !$site_host) {
            return false;
        }

        return strtolower($origin_host) === strtolower($site_host);
    }

    private function find_external_ticket($site_id, $external_visitor_id) {
        global $wpdb;
        $tickets_table = $this->get_tickets_table();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tickets_table} WHERE site_id = %d AND external_visitor_id = %s ORDER BY (status = 'open') DESC, last_message_at DESC LIMIT 1",
                (int) $site_id,
                $external_visitor_id
            )
        );
    }

    private function find_external_client_ticket($site_id, $client_id, $ticket_id = 0) {
        global $wpdb;
        $tickets_table = $this->get_tickets_table();
        if ($ticket_id > 0) {
            return $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$tickets_table} WHERE id = %d AND site_id = %d AND client_id = %d LIMIT 1",
                    (int) $ticket_id,
                    (int) $site_id,
                    (int) $client_id
                )
            );
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$tickets_table} WHERE site_id = %d AND client_id = %d ORDER BY (status = 'open') DESC, last_message_at DESC LIMIT 1",
                (int) $site_id,
                (int) $client_id
            )
        );
    }

    private function get_external_client_by_email($site_id, $email) {
        global $wpdb;
        $table = $this->get_clients_table();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d AND email = %s AND status = 'active' LIMIT 1",
                (int) $site_id,
                strtolower((string) $email)
            )
        );
    }

    private function get_external_client_by_id($site_id, $client_id) {
        global $wpdb;
        $table = $this->get_clients_table();
        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE site_id = %d AND id = %d AND status = 'active' LIMIT 1",
                (int) $site_id,
                (int) $client_id
            )
        );
    }

    private function generate_external_auth_token($site_id, $client_id) {
        $exp = time() + (30 * DAY_IN_SECONDS);
        $payload = $site_id . '|' . $client_id . '|' . $exp;
        $sig = hash_hmac('sha256', $payload, wp_salt('auth'));
        return rtrim(strtr(base64_encode($payload . '|' . $sig), '+/', '-_'), '=');
    }

    private function verify_external_auth_token($token, $site_id) {
        if ($token === '') {
            return 0;
        }
        $raw = base64_decode(strtr($token, '-_', '+/'));
        if (!$raw) {
            return 0;
        }
        $parts = explode('|', $raw);
        if (count($parts) !== 4) {
            return 0;
        }
        $t_site = (int) $parts[0];
        $t_client = (int) $parts[1];
        $t_exp = (int) $parts[2];
        $sig = (string) $parts[3];
        $payload = $parts[0] . '|' . $parts[1] . '|' . $parts[2];
        $expected = hash_hmac('sha256', $payload, wp_salt('auth'));
        if (!hash_equals($expected, $sig)) {
            return 0;
        }
        if ($t_site !== (int) $site_id || $t_exp < time() || $t_client <= 0) {
            return 0;
        }
        return $t_client;
    }

    private function list_external_client_tickets($site_id, $client_id) {
        global $wpdb;
        $table = $this->get_tickets_table();
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, subject, status, last_message_at FROM {$table} WHERE site_id = %d AND client_id = %d ORDER BY (status = 'open') DESC, last_message_at DESC LIMIT 100",
                (int) $site_id,
                (int) $client_id
            )
        );
    }

    public function rest_external_state($request) {
        $this->ensure_runtime_schema();
        if (!$this->check_rate_limit('external_state', 90, 60)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $api_key = sanitize_text_field((string) $request->get_param('site_key'));
        $visitor_id = sanitize_text_field((string) $request->get_param('visitor_id'));
        $auth_token = sanitize_text_field((string) $request->get_param('auth_token'));
        $ticket_id_param = absint((string) $request->get_param('ticket_id'));
        $origin = (string) $request->get_header('origin');

        $site = $this->get_site_by_api_key($api_key);
        if (!$site) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_site_key'], 403);
        }
        if (!$this->is_origin_allowed_for_site($origin, (string) $site->site_url)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'origin_not_allowed'], 403);
        }
        if ($visitor_id === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'visitor_id_required'], 400);
        }

        $client = null;
        $client_id = $this->verify_external_auth_token($auth_token, (int) $site->id);
        if ($client_id > 0) {
            $client = $this->get_external_client_by_id((int) $site->id, $client_id);
        }

        if ($client) {
            $ticket = $this->find_external_client_ticket((int) $site->id, (int) $client->id, $ticket_id_param);
            $tickets = $this->list_external_client_tickets((int) $site->id, (int) $client->id);
        } else {
            $ticket = $this->find_external_ticket((int) $site->id, $visitor_id);
            $tickets = [];
        }

        $messages = $ticket ? $this->format_messages_for_client($this->get_ticket_messages((int) $ticket->id)) : [];
        return rest_ensure_response([
            'ok' => true,
            'site_id' => (int) $site->id,
            'auth' => $client ? ['name' => (string) $client->name, 'email' => (string) $client->email] : null,
            'ticket_id' => $ticket ? (int) $ticket->id : 0,
            'status' => $ticket ? (string) $ticket->status : 'new',
            'tickets' => $tickets,
            'messages' => $messages,
        ]);
    }

    public function rest_external_send($request) {
        $this->ensure_runtime_schema();
        if (!$this->check_rate_limit('external_send', 30, 60)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $api_key = sanitize_text_field((string) $request->get_param('site_key'));
        $visitor_id = sanitize_text_field((string) $request->get_param('visitor_id'));
        $auth_token = sanitize_text_field((string) $request->get_param('auth_token'));
        $lang = $this->normalize_lang_key((string) $request->get_param('lang'));
        $ticket_id_param = absint((string) $request->get_param('ticket_id'));
        $force_new_ticket = absint((string) $request->get_param('force_new_ticket')) === 1;
        $guest_name = sanitize_text_field((string) $request->get_param('guest_name'));
        $guest_email = sanitize_email((string) $request->get_param('guest_email'));
        $message = trim(wp_strip_all_tags((string) $request->get_param('message')));
        $page_url = esc_url_raw((string) $request->get_param('page_url'));
        $origin = (string) $request->get_header('origin');

        $site = $this->get_site_by_api_key($api_key);
        if (!$site) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_site_key'], 403);
        }
        if (!$this->is_origin_allowed_for_site($origin, (string) $site->site_url)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'origin_not_allowed'], 403);
        }
        if ($visitor_id === '' || $message === '') {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        $client = null;
        $client_id = $this->verify_external_auth_token($auth_token, (int) $site->id);
        if ($client_id > 0) {
            $client = $this->get_external_client_by_id((int) $site->id, $client_id);
        }

        if ($client) {
            $guest_name = (string) $client->name;
            $guest_email = (string) $client->email;
            $ticket = $force_new_ticket ? null : $this->find_external_client_ticket((int) $site->id, (int) $client->id, $ticket_id_param);
        } else {
            [$guest_name, $guest_email] = $this->resolve_guest_identity($guest_name, $guest_email, $visitor_id);
            $ticket = $force_new_ticket ? null : $this->find_external_ticket((int) $site->id, $visitor_id);
        }

        $ticket_id = 0;
        $client_message_id = 0;
        if ($ticket && (int) $ticket->id > 0 && (string) $ticket->status === 'open') {
            $ticket_id = (int) $ticket->id;
            global $wpdb;
            $wpdb->update(
                $this->get_tickets_table(),
                [
                    'guest_name' => $guest_name,
                    'guest_email' => $guest_email,
                    'source_url' => $page_url,
                    'external_visitor_id' => $visitor_id,
                ],
                ['id' => $ticket_id],
                ['%s', '%s', '%s', '%s'],
                ['%d']
            );
            $this->add_message($ticket_id, 0, 'client', $message, $client_message_id);
        } else {
            $subject = 'Онлайн-чат (' . $site->name . ')';
            $ticket_id = $this->create_ticket_with_meta(0, (int) $site->id, $client ? (int) $client->id : 0, $subject, $message, $guest_name, $guest_email, '', $visitor_id, $page_url);
            $client_message_id = $ticket_id > 0 ? $this->get_last_message_id($ticket_id, 'client') : 0;
        }

        if ($ticket_id <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'ticket_create_failed'], 500);
        }

        if ($this->is_within_working_hours_utc2()) {
            $this->schedule_unanswered_followup($ticket_id, $client_message_id, $lang);
        } else {
            $this->maybe_add_auto_message($ticket_id, $client_message_id, $lang);
        }

        $tg = "Сайт: {$site->name}\nURL: {$site->site_url}\n\n";
        $tg .= "Новое сообщение от гостя\n";
        $tg .= 'Тикет #' . (int) $ticket_id . "\n";
        $tg .= 'Имя: ' . $guest_name . "\n";
        $tg .= 'Email: ' . $guest_email . "\n";
        if ($page_url !== '') {
            $tg .= 'Страница: ' . $page_url . "\n";
        }
        $tg .= "\n" . $message;
        $tg .= "\n\nОтветить клиенту: #{$ticket_id} <текст>";
        $this->send_telegram_plain($tg, (int) $ticket_id);

        $fresh = $this->get_ticket((int) $ticket_id);
        $messages = $this->format_messages_for_client($this->get_ticket_messages((int) $ticket_id));

        return rest_ensure_response([
            'ok' => true,
            'ticket_id' => (int) $ticket_id,
            'status' => $fresh ? (string) $fresh->status : 'open',
            'messages' => $messages,
        ]);
    }

    public function rest_external_register($request) {
        $this->ensure_runtime_schema();
        if (!$this->check_rate_limit('external_register', 5, 600)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $api_key = sanitize_text_field((string) $request->get_param('site_key'));
        $name = sanitize_text_field((string) $request->get_param('name'));
        $email = sanitize_email((string) $request->get_param('email'));
        $password = (string) $request->get_param('password');
        $origin = (string) $request->get_header('origin');

        $site = $this->get_site_by_api_key($api_key);
        if (!$site) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_site_key'], 403);
        }
        if (!$this->is_origin_allowed_for_site($origin, (string) $site->site_url)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'origin_not_allowed'], 403);
        }
        if ($name === '' || $email === '' || !is_email($email) || mb_strlen($password) < 6) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_payload'], 400);
        }

        if ($this->get_external_client_by_email((int) $site->id, $email)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'email_exists'], 409);
        }

        global $wpdb;
        $table = $this->get_clients_table();
        $now = current_time('mysql');
        $ok = $wpdb->insert(
            $table,
            [
                'site_id' => (int) $site->id,
                'name' => $name,
                'email' => strtolower($email),
                'password_hash' => wp_hash_password($password),
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        if (!$ok) {
            return new WP_REST_Response(['ok' => false, 'error' => 'register_failed'], 500);
        }

        $client_id = (int) $wpdb->insert_id;
        $token = $this->generate_external_auth_token((int) $site->id, $client_id);
        return rest_ensure_response([
            'ok' => true,
            'auth_token' => $token,
            'client' => ['id' => $client_id, 'name' => $name, 'email' => strtolower($email)],
        ]);
    }

    public function rest_external_login($request) {
        $this->ensure_runtime_schema();
        if (!$this->check_rate_limit('external_login', 10, 300)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $api_key = sanitize_text_field((string) $request->get_param('site_key'));
        $email = sanitize_email((string) $request->get_param('email'));
        $password = (string) $request->get_param('password');
        $origin = (string) $request->get_header('origin');

        $site = $this->get_site_by_api_key($api_key);
        if (!$site) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_site_key'], 403);
        }
        if (!$this->is_origin_allowed_for_site($origin, (string) $site->site_url)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'origin_not_allowed'], 403);
        }

        $client = $this->get_external_client_by_email((int) $site->id, $email);
        if (!$client || !wp_check_password($password, (string) $client->password_hash)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_credentials'], 401);
        }

        $token = $this->generate_external_auth_token((int) $site->id, (int) $client->id);
        return rest_ensure_response([
            'ok' => true,
            'auth_token' => $token,
            'client' => ['id' => (int) $client->id, 'name' => (string) $client->name, 'email' => (string) $client->email],
        ]);
    }

    public function rest_external_tickets($request) {
        $this->ensure_runtime_schema();
        if (!$this->check_rate_limit('external_tickets', 30, 60)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $api_key = sanitize_text_field((string) $request->get_param('site_key'));
        $auth_token = sanitize_text_field((string) $request->get_param('auth_token'));
        $origin = (string) $request->get_header('origin');

        $site = $this->get_site_by_api_key($api_key);
        if (!$site) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_site_key'], 403);
        }
        if (!$this->is_origin_allowed_for_site($origin, (string) $site->site_url)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'origin_not_allowed'], 403);
        }

        $client_id = $this->verify_external_auth_token($auth_token, (int) $site->id);
        if ($client_id <= 0) {
            return new WP_REST_Response(['ok' => false, 'error' => 'unauthorized'], 401);
        }

        $tickets = $this->list_external_client_tickets((int) $site->id, (int) $client_id);
        return rest_ensure_response(['ok' => true, 'tickets' => $tickets]);
    }

    public function rest_external_open($request) {
        $this->ensure_runtime_schema();
        if (!$this->check_rate_limit('external_open', 30, 300)) {
            return rest_ensure_response(['ok' => true]);
        }
        $api_key = sanitize_text_field((string) $request->get_param('site_key'));
        $visitor_id = sanitize_text_field((string) $request->get_param('visitor_id'));
        $auth_token = sanitize_text_field((string) $request->get_param('auth_token'));
        $page_url = esc_url_raw((string) $request->get_param('page_url'));
        $origin = (string) $request->get_header('origin');

        $site = $this->get_site_by_api_key($api_key);
        if (!$site) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_site_key'], 403);
        }
        if (!$this->is_origin_allowed_for_site($origin, (string) $site->site_url)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'origin_not_allowed'], 403);
        }

        $client = null;
        $client_id = $this->verify_external_auth_token($auth_token, (int) $site->id);
        if ($client_id > 0) {
            $client = $this->get_external_client_by_id((int) $site->id, $client_id);
        }

        $text = "Пользователь открыл чат\n";
        $text .= 'Сайт: ' . (string) $site->name . "\n";
        $text .= 'URL сайта: ' . (string) $site->site_url . "\n";
        if ($client) {
            $text .= 'Клиент: ' . (string) $client->name . ' (' . (string) $client->email . ')' . "\n";
        } else {
            $text .= 'Клиент: гость' . "\n";
            if ($visitor_id !== '') {
                $text .= 'Visitor ID: ' . $visitor_id . "\n";
            }
        }
        if ($page_url !== '') {
            $text .= 'Страница: ' . $page_url . "\n";
        }

        $this->send_telegram_plain($text, 0);
        return rest_ensure_response(['ok' => true]);
    }

    public function rest_telegram_webhook($request) {
        $this->ensure_runtime_schema();
        if (!$this->check_rate_limit('telegram_webhook', 120, 60)) {
            return new WP_REST_Response(['ok' => false, 'error' => 'rate_limited'], 429);
        }
        $expected_secret = $this->get_or_create_webhook_secret();
        $incoming_secret = (string) $request->get_header('x-telegram-bot-api-secret-token');
        if ($incoming_secret !== '' && ($expected_secret === '' || !hash_equals($expected_secret, $incoming_secret))) {
            return new WP_REST_Response(['ok' => false, 'error' => 'forbidden'], 403);
        }
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return rest_ensure_response(['ok' => true]);
        }

        $update = null;
        if (!empty($payload['message']) && is_array($payload['message'])) {
            $update = $payload['message'];
        } elseif (!empty($payload['channel_post']) && is_array($payload['channel_post'])) {
            $update = $payload['channel_post'];
        } elseif (!empty($payload['edited_message']) && is_array($payload['edited_message'])) {
            $update = $payload['edited_message'];
        } elseif (!empty($payload['edited_channel_post']) && is_array($payload['edited_channel_post'])) {
            $update = $payload['edited_channel_post'];
        }

        if (!$update || empty($update['chat']['id'])) {
            return rest_ensure_response(['ok' => true]);
        }
        if (!empty($update['from']['is_bot'])) {
            return rest_ensure_response(['ok' => true]);
        }

        $configured_chat_id = trim((string) get_option(self::OPTION_TELEGRAM_CHAT_ID, ''));
        $incoming_chat_id = (string) $update['chat']['id'];

        $raw_text = '';
        if (!empty($update['text'])) {
            $raw_text = (string) $update['text'];
        } elseif (!empty($update['caption'])) {
            $raw_text = (string) $update['caption'];
        }

        $text = trim($raw_text);
        if ($text === '') {
            return rest_ensure_response(['ok' => true]);
        }

        if ($configured_chat_id === '') {
            return rest_ensure_response(['ok' => true]);
        }

        if ($incoming_chat_id !== $configured_chat_id) {
            return rest_ensure_response(['ok' => true]);
        }
        if (empty($update['reply_to_message']['message_id'])) {
            $this->send_telegram_plain('Ошибка: отвечайте через reply на сообщение бота.', 0, $incoming_chat_id);
            return rest_ensure_response(['ok' => true]);
        }

        $reply_to_message_id = (int) $update['reply_to_message']['message_id'];
        $ticket_id = $this->find_ticket_id_by_telegram_message($incoming_chat_id, $reply_to_message_id);
        if ($ticket_id <= 0) {
            $ticket_id = $this->find_ticket_id_by_telegram_message_any_chat($reply_to_message_id);
        }
        $reply_message = $text;

        if ($ticket_id <= 0) {
            $this->send_telegram_plain('Ошибка: не удалось определить чат для ответа.', 0, $incoming_chat_id);
            return rest_ensure_response(['ok' => true]);
        }

        $ticket = $this->get_ticket($ticket_id);
        if (!$ticket) {
            $this->send_telegram_plain('Ошибка: тикет #' . (int) $ticket_id . ' не найден', 0, $incoming_chat_id);
            return rest_ensure_response(['ok' => true]);
        }

        $saved = $this->add_message($ticket_id, 0, 'admin', $reply_message);
        if ($saved) {
            $this->send_telegram_plain('OK: ответ сохранён в тикет #' . (int) $ticket_id, 0, $incoming_chat_id);
        } else {
            global $wpdb;
            $details = $wpdb->last_error ? $wpdb->last_error : 'unknown';
            $this->send_telegram_plain('Ошибка БД при сохранении ответа в тикет #' . (int) $ticket_id . ': ' . $details, 0, $incoming_chat_id);
        }

        return rest_ensure_response(['ok' => true]);
    }

    private function get_safe_redirect_url() {
        $redirect = isset($_POST['_wp_http_referer']) ? wp_unslash($_POST['_wp_http_referer']) : '';

        if (!$redirect) {
            $redirect = wp_get_referer();
        }

        if (!$redirect) {
            $redirect = home_url('/');
        }

        return wp_validate_redirect($redirect, home_url('/'));
    }

    private function redirect_with_notice($url, $notice) {
        $target = add_query_arg(['support_chat_notice' => $notice], $url);
        wp_safe_redirect($target);
        exit;
    }

    private function notify_telegram($ticket_id, $sender_type, $message, $sender_user_id, $subject = '') {
        $user = get_userdata($sender_user_id);
        $sender_name = $user ? $user->user_login : 'unknown';

        if ($subject === '') {
            $ticket = $this->get_ticket($ticket_id);
            if ($ticket) {
                $subject = $ticket->subject;
            }
        }

        $prefix = $sender_type === 'admin' ? 'Ответ администратора' : 'Новое сообщение от клиента';

        $text = "{$prefix}\n";
        $text .= 'Тикет #' . (int) $ticket_id . "\n";
        if ($subject !== '') {
            $text .= 'Тема: ' . $subject . "\n";
        }
        $text .= 'Пользователь: ' . $sender_name . "\n\n";
        $text .= $message;

        return $this->send_telegram_plain($text, (int) $ticket_id);
    }

    private function send_telegram_ticket_message($ticket_id, $guest_name, $guest_email, $message, $subject = '') {
        $text = "Новое сообщение от гостя\n";
        $text .= 'Тикет #' . (int) $ticket_id . "\n";
        if ($subject !== '') {
            $text .= 'Тема: ' . $subject . "\n";
        }
        $text .= 'Имя: ' . $guest_name . "\n";
        $text .= 'Email: ' . $guest_email . "\n\n";
        $text .= $message;

        return $this->send_telegram_plain($text, (int) $ticket_id);
    }

    private function send_telegram_plain($text, $ticket_id = 0, $chat_id_override = '') {
        $token = trim((string) get_option(self::OPTION_TELEGRAM_BOT_TOKEN, ''));
        $chat_id = trim((string) get_option(self::OPTION_TELEGRAM_CHAT_ID, ''));
        if ($chat_id_override !== '') {
            $chat_id = (string) $chat_id_override;
        }

        if ($token === '' || $chat_id === '') {
            return false;
        }

        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 15,
                'body' => [
                    'chat_id' => $chat_id,
                    'text' => $text,
                ],
                'headers' => [
                    'Accept-Charset' => 'utf-8',
                ],
            ]
        );

        if (is_wp_error($response)) {
            return false;
        }

        $code = wp_remote_retrieve_response_code($response);
        $ok = $code >= 200 && $code < 300;

        if ($ok && $ticket_id > 0) {
            $body = wp_remote_retrieve_body($response);
            $decoded = json_decode((string) $body, true);
            if (is_array($decoded) && !empty($decoded['result']['message_id'])) {
                $telegram_message_id = (int) $decoded['result']['message_id'];
                $telegram_chat_id = isset($decoded['result']['chat']['id']) ? (string) $decoded['result']['chat']['id'] : $chat_id;
                $this->save_telegram_message_link($telegram_chat_id, $telegram_message_id, (int) $ticket_id);
            }
        }

        return $ok;
    }

    private function save_telegram_message_link($chat_id, $telegram_message_id, $ticket_id) {
        global $wpdb;
        $this->ensure_runtime_schema();

        if ($chat_id === '' || $telegram_message_id <= 0 || $ticket_id <= 0) {
            return false;
        }

        $table = $this->get_telegram_links_table();
        $now = current_time('mysql');

        $inserted = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$table} (chat_id, telegram_message_id, ticket_id, created_at)
                 VALUES (%s, %d, %d, %s)
                 ON DUPLICATE KEY UPDATE ticket_id = VALUES(ticket_id)",
                (string) $chat_id,
                (int) $telegram_message_id,
                (int) $ticket_id,
                $now
            )
        );

        return $inserted !== false;
    }

    private function find_ticket_id_by_telegram_message($chat_id, $telegram_message_id) {
        global $wpdb;
        $this->ensure_runtime_schema();

        if ($chat_id === '' || $telegram_message_id <= 0) {
            return 0;
        }

        $table = $this->get_telegram_links_table();
        $ticket_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ticket_id FROM {$table} WHERE chat_id = %s AND telegram_message_id = %d LIMIT 1",
                (string) $chat_id,
                (int) $telegram_message_id
            )
        );

        return $ticket_id > 0 ? $ticket_id : 0;
    }

    private function find_ticket_id_by_telegram_message_any_chat($telegram_message_id) {
        global $wpdb;

        if ($telegram_message_id <= 0) {
            return 0;
        }

        $table = $this->get_telegram_links_table();
        $ticket_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ticket_id FROM {$table} WHERE telegram_message_id = %d ORDER BY id DESC LIMIT 1",
                (int) $telegram_message_id
            )
        );

        return $ticket_id > 0 ? $ticket_id : 0;
    }

    public function render_support_shortcode() {
        ob_start();

        $notice = isset($_GET['support_chat_notice']) ? sanitize_text_field(wp_unslash($_GET['support_chat_notice'])) : '';
        $ticket_id = isset($_GET['ticket_id']) ? absint($_GET['ticket_id']) : 0;

        echo '<style>
            .support-chat-wrap{max-width:980px;margin:20px auto;padding:20px;border:1px solid #dcdcde;border-radius:12px;background:#fff}
            .support-chat-grid{display:grid;grid-template-columns:300px 1fr;gap:16px}
            .support-chat-card{border:1px solid #dcdcde;border-radius:10px;padding:14px;background:#f9f9f9}
            .support-chat-ticket{border:1px solid #dcdcde;border-radius:8px;padding:10px;margin-bottom:10px;background:#fff}
            .support-chat-msg{padding:10px;border-radius:8px;margin-bottom:10px;white-space:pre-wrap}
            .support-chat-client{background:#ecf3ff;border:1px solid #cfe0ff}
            .support-chat-admin{background:#f1f1f1;border:1px solid #ddd}
            .support-chat-notice{padding:10px 12px;border-radius:8px;margin-bottom:12px}
            .support-chat-notice-ok{background:#e7f7ea;border:1px solid #b7e0c0}
            .support-chat-notice-error{background:#fdecec;border:1px solid #f3c4c4}
            @media (max-width:900px){.support-chat-grid{grid-template-columns:1fr}}
        </style>';

        echo '<div class="support-chat-wrap">';
        echo '<h2>' . esc_html__('Поддержка', self::TEXT_DOMAIN) . '</h2>';

        if ($notice) {
            $ok = in_array($notice, ['settings_saved', 'reply_sent', 'status_changed', 'ticket_created', 'message_sent', 'quick_sent', 'registered'], true);
            echo '<div class="support-chat-notice ' . esc_attr($ok ? 'support-chat-notice-ok' : 'support-chat-notice-error') . '">';
            $text = $ok ? __('Действие выполнено.', self::TEXT_DOMAIN) : __('Проверьте данные и повторите попытку.', self::TEXT_DOMAIN);
            if ($notice === 'registered') {
                $text = __('Аккаунт создан, вы авторизованы.', self::TEXT_DOMAIN);
            } elseif ($notice === 'quick_sent') {
                $text = __('Сообщение отправлено. Скоро вам ответят.', self::TEXT_DOMAIN);
            }
            echo esc_html($text);
            echo '</div>';
        }

        if (!is_user_logged_in()) {
            $this->render_guest_area();
            echo '</div>';
            return ob_get_clean();
        }

        $this->render_user_area($ticket_id);

        echo '</div>';
        return ob_get_clean();
    }

    private function render_guest_area() {
        $allow_registration = (string) get_option(self::OPTION_ALLOW_REGISTRATION, 'yes');
        $current_url = $this->get_current_page_url();
        $invite_ticket_id = isset($_GET['ticket_id']) ? absint(wp_unslash($_GET['ticket_id'])) : 0;
        $invite_email = isset($_GET['email']) ? sanitize_email(wp_unslash($_GET['email'])) : '';
        $invite_mode = isset($_GET['support_chat_register']) && (int) wp_unslash($_GET['support_chat_register']) === 1;

        echo '<p>' . esc_html__('Чтобы написать в поддержку, войдите в аккаунт или зарегистрируйтесь.', self::TEXT_DOMAIN) . '</p>';

        echo '<div class="support-chat-grid">';
        echo '<div class="support-chat-card">';
        echo '<h3>' . esc_html__('Вход', self::TEXT_DOMAIN) . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('support_chat_login_user', 'support_chat_nonce');
        echo '<input type="hidden" name="action" value="support_chat_login_user" />';
        echo '<p><label>' . esc_html__('Логин или Email', self::TEXT_DOMAIN) . '<br /><input type="text" name="login" required /></label></p>';
        echo '<p><label>' . esc_html__('Пароль', self::TEXT_DOMAIN) . '<br /><input type="password" name="password" required /></label></p>';
        echo '<p><label><input type="checkbox" name="remember" value="1" /> ' . esc_html__('Запомнить меня', self::TEXT_DOMAIN) . '</label></p>';
        echo '<p><button type="submit">' . esc_html__('Войти', self::TEXT_DOMAIN) . '</button></p>';
        echo '</form>';
        echo '</div>';

        if ($allow_registration === 'yes') {
            echo '<div class="support-chat-card">';
            echo '<h3>' . esc_html__('Регистрация', self::TEXT_DOMAIN) . '</h3>';
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
            wp_nonce_field('support_chat_register_user', 'support_chat_nonce');
            echo '<input type="hidden" name="action" value="support_chat_register_user" />';
            if ($invite_mode && $invite_ticket_id > 0 && $invite_email !== '') {
                echo '<input type="hidden" name="invite_ticket_id" value="' . (int) $invite_ticket_id . '" />';
            }
            echo '<p><label>' . esc_html__('Логин', self::TEXT_DOMAIN) . '<br /><input type="text" name="username" required /></label></p>';
            if ($invite_mode && $invite_email !== '') {
                echo '<p><label>' . esc_html__('Email', self::TEXT_DOMAIN) . '<br /><input type="email" name="email" value="' . esc_attr($invite_email) . '" required readonly /></label></p>';
            } else {
                echo '<p><label>' . esc_html__('Email', self::TEXT_DOMAIN) . '<br /><input type="email" name="email" required /></label></p>';
            }
            echo '<p><label>' . esc_html__('Пароль', self::TEXT_DOMAIN) . '<br /><input type="password" name="password" required minlength="6" /></label></p>';
            echo '<p><button type="submit">' . esc_html__('Создать аккаунт', self::TEXT_DOMAIN) . '</button></p>';
            echo '</form>';
            echo '</div>';
        }

        echo '</div>';
    }

    private function render_user_area($ticket_id) {
        $user_id = get_current_user_id();
        $tickets = $this->get_user_tickets($user_id);

        if ($ticket_id > 0) {
            $ticket = $this->get_ticket($ticket_id);
            if (!$ticket || (int) $ticket->user_id !== $user_id) {
                $ticket_id = 0;
            }
        }

        if ($ticket_id === 0 && !empty($tickets)) {
            $ticket_id = (int) $tickets[0]->id;
        }

        echo '<div class="support-chat-grid">';

        echo '<div class="support-chat-card">';
        echo '<h3>' . esc_html__('Мои чаты', self::TEXT_DOMAIN) . '</h3>';

        if (empty($tickets)) {
            echo '<p>' . esc_html__('У вас пока нет обращений.', self::TEXT_DOMAIN) . '</p>';
        } else {
            foreach ($tickets as $ticket) {
                $url = add_query_arg(['ticket_id' => (int) $ticket->id]);
                echo '<div class="support-chat-ticket">';
                echo '<strong><a href="' . esc_url($url) . '">#' . (int) $ticket->id . ' ' . esc_html($ticket->subject) . '</a></strong>';
                echo '<div>' . esc_html__('Статус:', self::TEXT_DOMAIN) . ' ' . esc_html($ticket->status) . '</div>';
                echo '<div style="font-size:12px;color:#666;">' . esc_html($ticket->last_message_at) . '</div>';
                echo '</div>';
            }
        }

        echo '</div>';

        echo '<div class="support-chat-card">';

        if ($ticket_id > 0) {
            $ticket = $this->get_ticket($ticket_id);
            $messages = $this->get_ticket_messages($ticket_id);

            echo '<h3>Чат #' . (int) $ticket->id . ': ' . esc_html($ticket->subject) . '</h3>';
            echo '<p>' . esc_html__('Статус:', self::TEXT_DOMAIN) . ' <strong>' . esc_html($ticket->status) . '</strong></p>';

            if (empty($messages)) {
                echo '<p>' . esc_html__('Сообщений нет.', self::TEXT_DOMAIN) . '</p>';
            } else {
                foreach ($messages as $message) {
                    $class = $message->sender_type === 'client' ? 'support-chat-client' : 'support-chat-admin';
                    $label = $message->sender_type === 'client' ? __('Вы', self::TEXT_DOMAIN) : __('Поддержка', self::TEXT_DOMAIN);
                    echo '<div class="support-chat-msg ' . esc_attr($class) . '">';
                    echo '<div style="font-size:12px;color:#666;">' . esc_html($label) . ' · ' . esc_html($message->created_at) . '</div>';
                    echo esc_html($message->message);
                    echo '</div>';
                }
            }

            if ($ticket->status === 'open') {
                echo '<h4>' . esc_html__('Ответить', self::TEXT_DOMAIN) . '</h4>';
                echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
                wp_nonce_field('support_chat_send_reply', 'support_chat_nonce');
                echo '<input type="hidden" name="action" value="support_chat_send_reply" />';
                echo '<input type="hidden" name="ticket_id" value="' . (int) $ticket->id . '" />';
                echo '<textarea name="message" rows="5" style="width:100%;" required></textarea>';
                echo '<p><button type="submit">' . esc_html__('Отправить', self::TEXT_DOMAIN) . '</button></p>';
                echo '</form>';
            } else {
                echo '<p>' . esc_html__('Чат закрыт. Если вопрос остался, создайте новый чат.', self::TEXT_DOMAIN) . '</p>';
            }
        }

        echo '<hr />';
        echo '<h3>' . esc_html__('Создать новый чат', self::TEXT_DOMAIN) . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('support_chat_create_ticket', 'support_chat_nonce');
        echo '<input type="hidden" name="action" value="support_chat_create_ticket" />';
        echo '<p><label>' . esc_html__('Тема', self::TEXT_DOMAIN) . '<br /><input type="text" name="subject" style="width:100%;" required maxlength="190" /></label></p>';
        echo '<p><label>' . esc_html__('Сообщение', self::TEXT_DOMAIN) . '<br /><textarea name="message" rows="5" style="width:100%;" required></textarea></label></p>';
        echo '<p><button type="submit">' . esc_html__('Создать чат', self::TEXT_DOMAIN) . '</button></p>';
        echo '</form>';

        echo '</div>';

        echo '</div>';
    }

    private function get_current_page_url() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        if ($request_uri === '') {
            $request_uri = '/';
        }

        if ($request_uri[0] !== '/') {
            $request_uri = '/' . $request_uri;
        }

        return home_url($request_uri);
    }

    public function render_floating_widget() {
        if ($this->widget_rendered) {
            return;
        }

        if (is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_trackback()) {
            return;
        }
        $this->widget_rendered = true;

        $current_url = $this->get_current_page_url();
        $allow_registration = (string) get_option(self::OPTION_ALLOW_REGISTRATION, 'yes');

        echo '<style>
            .sc-launcher{position:fixed;right:18px;bottom:18px;z-index:99998;background:#04062b;color:#fff;border:none;border-radius:999px;padding:12px 16px;font-size:15px;font-weight:700;cursor:pointer;box-shadow:0 12px 24px rgba(4,6,43,.28)}
            .sc-teaser{position:fixed;right:18px;bottom:74px;z-index:99998;max-width:300px;background:#fff;color:#101322;border:1px solid #d9dce7;border-radius:14px;padding:12px 14px;box-shadow:0 14px 28px rgba(10,25,70,.18);display:none}
            .sc-teaser strong{display:block;margin-bottom:4px}
            .sc-shell{position:fixed;right:14px;bottom:14px;z-index:99999;width:min(393px,calc(100vw - 16px));height:min(68vh,920px);display:none;overflow:visible}
            .sc-panel{position:absolute;left:0;right:0;bottom:0;top:0;background:#fff;border:1px solid #d8dbe6;border-radius:24px;box-shadow:0 20px 44px rgba(7,20,60,.28);overflow:hidden}
            .sc-close{position:absolute;top:-16px;left:-16px !important;right:auto !important;width:40px;height:40px;border-radius:999px;display:flex;align-items:center;justify-content:center;background:#fff !important;border:1px solid #d8dbe6 !important;box-shadow:unset !important;color:#787c8e;font-size:28px;cursor:pointer;line-height:1;z-index:4;padding:0 !important;margin:0 !important;transform:none !important}
            .sc-view{display:none;height:100%;background:#f2f2f5}
            .sc-view.active{display:flex;flex-direction:column}
            .sc-header{padding:16px 16px 12px 16px;display:flex;align-items:center;justify-content:space-between;gap:10px;background:#fff}
            .sc-brand{display:flex;align-items:center;gap:12px}
            .sc-logo{width:48px;height:48px;border-radius:50%;background:#04062b;color:#fff;display:flex;align-items:center;justify-content:center;font-size:20px}
            .sc-title{font-size:22px;line-height:1.05;font-weight:800;color:#0d1226;margin:0}
            .sc-subtitle{font-size:16px;color:#70758a;margin:0}
            .sc-create-btn{border:none;background:#04062b;color:#fff;border-radius:14px;padding:10px 14px;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap}
            .sc-divider{height:1px;background:#ddd}
            .sc-profile{padding:10px 16px;color:#676d81;font-size:13px;line-height:1.4;border-bottom:1px solid #e3e4ea}
            .sc-profile-main{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
            .sc-profile-label{font-weight:700;color:#666d82}
            .sc-profile-links{margin-top:6px;display:flex;gap:12px;flex-wrap:wrap}
            .sc-profile a{color:#33579b;text-decoration:none;font-weight:600}
            .sc-messages{flex:1;overflow:auto;padding:8px 20px 14px 20px}
            .sc-msg{margin:0 0 18px}
            .sc-msg-head{display:flex;align-items:center;gap:8px;color:#6d7285;font-size:12px;margin-bottom:6px}
            .sc-msg-avatar{width:28px;height:28px;border-radius:50%;background:#e6e7ed;color:#70758a;display:flex;align-items:center;justify-content:center;font-size:14px}
            .sc-msg-bubble{display:inline-block;max-width:78%;padding:14px 16px;border-radius:18px;font-size:17px;line-height:1.45;background:#dddde3;color:#181b2a}
            .sc-msg-time{margin-top:6px;color:#6d7285;font-size:12px}
            .sc-msg.mine{text-align:right}
            .sc-msg.mine .sc-msg-bubble{background:#04062b;color:#fff}
            .sc-msg.mine .sc-msg-time{text-align:right}
            .sc-composer{padding:12px 16px;background:#f2f2f5;border-top:1px solid #ddd;display:flex;gap:10px;align-items:center}
            .sc-input-wrap{flex:1;display:flex;align-items:center;height:54px;background:#dddde3;border-radius:16px;padding:0 18px}
            .sc-input{
                width:100% !important;
                min-width:0 !important;
                min-height:0 !important;
                max-height:none !important;
                height:100% !important;
                box-sizing:border-box !important;
                -webkit-appearance:none !important;
                appearance:none !important;
                border:0 !important;
                outline:0 !important;
                box-shadow:none !important;
                background:transparent !important;
                background-image:none !important;
                border-radius:0 !important;
                margin:0 !important;
                padding:0 !important;
                text-indent:0 !important;
                letter-spacing:normal !important;
                font:400 18px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif !important;
                color:#1a1f33 !important;
                caret-color:#1a1f33 !important;
            }
            .sc-input::placeholder{
                color:#777b8f !important;
                opacity:1 !important;
            }
            .sc-input:focus{border:0 !important;box-shadow:none !important;outline:none !important}
            .sc-send{border:none;background:#a9acb7;color:#fff;width:56px;height:56px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center}
            .sc-send.active{background:#04062b}
            .sc-send svg{width:24px;height:24px;display:block;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
            .sc-link-btn{border:none;background:transparent;color:#6f7488;text-decoration:underline;cursor:pointer;padding:0}
            .sc-auth-wrap{padding:14px 16px 16px 16px;overflow:auto}
            .sc-back{border:0 !important;box-shadow:none !important;background:transparent !important;-webkit-appearance:none !important;appearance:none !important;color:#6f7488 !important;font-size:16px !important;font-weight:600 !important;cursor:pointer;padding:0 !important;margin:2px 0 14px !important;text-decoration:none !important}
            .sc-auth-top{text-align:center;margin:2px 0 12px}
            .sc-auth-icon{width:64px;height:64px;border-radius:16px;background:#04062b;color:#fff;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:30px}
            .sc-auth-title{font-size:28px;font-weight:800;color:#111526;margin:0 0 6px}
            .sc-auth-sub{font-size:16px;color:#70758a;margin:0}
            .sc-auth-card{background:#f2f2f5;border:1px solid #d2d4dd;border-radius:18px;padding:14px}
            .sc-auth-label{display:block;color:#1a1f33 !important;font-size:13px !important;font-weight:700 !important;margin:0 0 6px !important}
            .sc-auth-field{display:block;width:100% !important;min-height:0 !important;box-sizing:border-box !important;-webkit-appearance:none !important;appearance:none !important;border:0 !important;outline:0 !important;box-shadow:none !important;background:#dddde3 !important;border-radius:14px !important;padding:10px 12px !important;font-size:16px !important;line-height:1.2 !important;color:#1a1f33 !important;margin:0 0 12px 0 !important;text-indent:0 !important;letter-spacing:normal !important}
            .sc-auth-field::placeholder{color:#777b8f !important;opacity:1 !important}
            .sc-auth-submit{display:block;width:100% !important;border:0 !important;outline:0 !important;box-shadow:none !important;background:#04062b !important;color:#fff !important;border-radius:14px !important;padding:11px 14px !important;font-size:18px !important;font-weight:700 !important;cursor:pointer !important;margin-top:4px !important}
            .sc-auth-swap{text-align:center;margin-top:10px;color:#6f7488;font-size:13px}
            .sc-auth-swap a{color:#111526;font-weight:700;text-decoration:none}
            .sc-tickets{padding:14px 20px 20px;overflow:auto}
            .sc-ticket-item{border:1px solid #d2d4dd;border-radius:14px;padding:12px 14px;margin-bottom:10px;background:#fff;cursor:pointer}
            .sc-ticket-item.active{border-color:#04062b;background:#f2f4ff}
            @media (max-width: 600px){
                .sc-shell{right:8px;bottom:8px;width:min(393px,calc(100vw - 16px));height:min(82svh,780px)}
                .sc-panel{border-radius:22px}
                .sc-close{top:10px;left:10px !important;right:auto !important}
                .sc-title{font-size:28px}
                .sc-subtitle{font-size:16px}
                .sc-create-btn{font-size:14px;padding:10px 12px}
                .sc-auth-title{font-size:24px}
                .sc-auth-sub{font-size:14px}
                .sc-auth-field{font-size:16px}
                .sc-auth-submit{font-size:16px}
                .sc-input-wrap{height:52px}
                .sc-input{font-size:16px !important}
            }
        </style>';

        echo '<div id="scTeaser" class="sc-teaser"><strong>' . esc_html__('Нужна срочная помощь с сайтом?', self::TEXT_DOMAIN) . '</strong>' . esc_html__('Опишите нам проблему, и мы ответим как можно быстрее.', self::TEXT_DOMAIN) . '</div>';
        echo '<button id="scLauncher" class="sc-launcher" type="button">' . esc_html__('Чат поддержки', self::TEXT_DOMAIN) . '</button>';
        echo '<div id="scShell" class="sc-shell" aria-hidden="true">';
        echo '<button id="scClose" class="sc-close" type="button" aria-label="' . esc_attr__('Закрыть', self::TEXT_DOMAIN) . '">×</button>';
        echo '<div id="scPanel" class="sc-panel">';

        echo '<div id="scViewChat" class="sc-view active">';
        echo '<div class="sc-header">';
        echo '<div class="sc-brand"><div class="sc-logo">◌</div><div><p class="sc-title">' . esc_html__('Поддержка', self::TEXT_DOMAIN) . '</p><p class="sc-subtitle">' . esc_html__('Онлайн', self::TEXT_DOMAIN) . '</p></div></div>';
        echo '<button id="scCreateTicket" class="sc-create-btn" type="button">+ ' . esc_html__('Создать чат', self::TEXT_DOMAIN) . '</button>';
        echo '</div>';
        echo '<div class="sc-divider"></div>';
        echo '<div id="scProfile" class="sc-profile"></div>';
        echo '<div id="scMessages" class="sc-messages"></div>';
        echo '<div class="sc-composer">';
        echo '<div class="sc-input-wrap"><input id="scInput" class="sc-input" type="text" placeholder="' . esc_attr__('Введите сообщение...', self::TEXT_DOMAIN) . '" /></div>';
        echo '<button id="scSend" class="sc-send" type="button" aria-label="' . esc_attr__('Отправить', self::TEXT_DOMAIN) . '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M22 2L11 13"></path><path d="M22 2L15 22L11 13L2 9L22 2Z"></path></svg></button>';
        echo '</div>';
        echo '</div>';

        echo '<div id="scViewTickets" class="sc-view">';
        echo '<div class="sc-auth-wrap">';
        echo '<button id="scBackFromTickets" class="sc-back" type="button">← ' . esc_html__('Назад к чату', self::TEXT_DOMAIN) . '</button>';
        echo '<div id="scTickets" class="sc-tickets"></div>';
        echo '</div>';
        echo '</div>';

        echo '<div id="scViewAuth" class="sc-view">';
        echo '<div class="sc-auth-wrap">';
        echo '<button id="scBackFromAuth" class="sc-back" type="button">← ' . esc_html__('Назад к чату', self::TEXT_DOMAIN) . '</button>';
        echo '<div class="sc-auth-top"><div class="sc-auth-icon">◌</div><h3 class="sc-auth-title">' . esc_html__('Вход', self::TEXT_DOMAIN) . '</h3><p class="sc-auth-sub">' . esc_html__('Войдите, чтобы управлять чатами', self::TEXT_DOMAIN) . '</p></div>';
        echo '<div class="sc-auth-card">';
        echo '<label class="sc-auth-label" for="scLogin">' . esc_html__('Email', self::TEXT_DOMAIN) . '</label>';
        echo '<input id="scLogin" class="sc-auth-field" type="text" placeholder="' . esc_attr__('ваш@email.com', self::TEXT_DOMAIN) . '" />';
        echo '<label class="sc-auth-label" for="scLoginPassword">' . esc_html__('Пароль', self::TEXT_DOMAIN) . '</label>';
        echo '<input id="scLoginPassword" class="sc-auth-field" type="password" placeholder="••••••••" />';
        echo '<div id="scRegisterFields" style="display:none;">';
        echo '<label class="sc-auth-label" for="scRegisterUsername">' . esc_html__('Логин', self::TEXT_DOMAIN) . '</label>';
        echo '<input id="scRegisterUsername" class="sc-auth-field" type="text" placeholder="' . esc_attr__('логин', self::TEXT_DOMAIN) . '" />';
        echo '</div>';
        echo '<button id="scAuthSubmit" class="sc-auth-submit" type="button">' . esc_html__('Войти', self::TEXT_DOMAIN) . '</button>';
        if ($allow_registration === 'yes') {
            echo '<p class="sc-auth-swap"><span id="scSwapLabel">' . esc_html__('Нет аккаунта?', self::TEXT_DOMAIN) . '</span> <a id="scAuthSwap" href="#">' . esc_html__('Зарегистрироваться', self::TEXT_DOMAIN) . '</a></p>';
        }
        echo '</div></div></div>';
        echo '</div>';
        echo '</div>';

        $ajax_url = admin_url('admin-ajax.php');
        $ajax_nonce = wp_create_nonce('support_chat_widget');
        $is_logged = is_user_logged_in() ? 'true' : 'false';
        $i18n_support = esc_js(__('Поддержка', self::TEXT_DOMAIN));
        $i18n_you = esc_js(__('Вы', self::TEXT_DOMAIN));
        $i18n_no_messages = esc_js(__('Сообщений пока нет.', self::TEXT_DOMAIN));
        $i18n_fill_guest = esc_js(__('Для первого сообщения укажите имя и email.', self::TEXT_DOMAIN));
        $i18n_error = esc_js(__('Ошибка', self::TEXT_DOMAIN));
        $i18n_send_failed = esc_js(__('Не удалось отправить сообщение.', self::TEXT_DOMAIN));
        $i18n_connection_error = esc_js(__('Ошибка соединения.', self::TEXT_DOMAIN));
        $i18n_no_tickets = esc_js(__('Чатов пока нет.', self::TEXT_DOMAIN));
        $i18n_fill_login = esc_js(__('Введите логин и пароль.', self::TEXT_DOMAIN));
        $i18n_fill_register = esc_js(__('Заполните логин, email и пароль (мин. 6 символов).', self::TEXT_DOMAIN));
        $i18n_login_failed = esc_js(__('Неверный логин или пароль.', self::TEXT_DOMAIN));
        $i18n_register_failed = esc_js(__('Не удалось создать аккаунт.', self::TEXT_DOMAIN));
        $i18n_profile_label = esc_js(__('Профиль:', self::TEXT_DOMAIN));
        $i18n_logout = esc_js(__('выйти', self::TEXT_DOMAIN));
        $i18n_edit = esc_js(__('изменить', self::TEXT_DOMAIN));
        $i18n_login_for_tickets = esc_js(__('Для управления чатами аккаунта выполните вход.', self::TEXT_DOMAIN));
        $i18n_auth_login_title = esc_js(__('Вход', self::TEXT_DOMAIN));
        $i18n_auth_register_title = esc_js(__('Регистрация', self::TEXT_DOMAIN));
        $i18n_auth_login_submit = esc_js(__('Войти', self::TEXT_DOMAIN));
        $i18n_auth_register_submit = esc_js(__('Создать аккаунт', self::TEXT_DOMAIN));
        $i18n_auth_swap_to_register = esc_js(__('Зарегистрироваться', self::TEXT_DOMAIN));
        $i18n_auth_swap_to_login = esc_js(__('Войти', self::TEXT_DOMAIN));
        $i18n_auth_no_account = esc_js(__('Нет аккаунта?', self::TEXT_DOMAIN));
        $i18n_auth_have_account = esc_js(__('Уже есть аккаунт?', self::TEXT_DOMAIN));
        echo '<script>
            (function(){
                var launcher = document.getElementById("scLauncher");
                var shell = document.getElementById("scShell");
                var panel = document.getElementById("scPanel");
                var closeBtn = document.getElementById("scClose");
                var teaser = document.getElementById("scTeaser");
                var viewChat = document.getElementById("scViewChat");
                var viewAuth = document.getElementById("scViewAuth");
                var viewTickets = document.getElementById("scViewTickets");
                var messagesBox = document.getElementById("scMessages");
                var ticketsBox = document.getElementById("scTickets");
                var profileBox = document.getElementById("scProfile");
                var sendBtn = document.getElementById("scSend");
                var input = document.getElementById("scInput");
                var createTicketBtn = document.getElementById("scCreateTicket");
                var backFromAuth = document.getElementById("scBackFromAuth");
                var backFromTickets = document.getElementById("scBackFromTickets");
                var loginInput = document.getElementById("scLogin");
                var loginPassword = document.getElementById("scLoginPassword");
                var authSubmit = document.getElementById("scAuthSubmit");
                var authSwap = document.getElementById("scAuthSwap");
                var swapLabel = document.getElementById("scSwapLabel");
                var registerFields = document.getElementById("scRegisterFields");
                var authTitle = viewAuth ? viewAuth.querySelector(".sc-auth-title") : null;
                var registerUsername = document.getElementById("scRegisterUsername");
                if(!launcher || !shell || !panel || !closeBtn || !teaser || !viewChat || !viewAuth || !viewTickets || !messagesBox || !ticketsBox || !profileBox || !sendBtn || !input){ return; }
                var ajaxUrl = ' . wp_json_encode($ajax_url) . ';
                var nonce = ' . wp_json_encode($ajax_nonce) . ';
                var loggedIn = ' . $is_logged . ';
                var allowRegistration = ' . wp_json_encode($allow_registration === 'yes') . ';
                var tSupport = ' . wp_json_encode($i18n_support) . ';
                var tYou = ' . wp_json_encode($i18n_you) . ';
                var tNoMessages = ' . wp_json_encode($i18n_no_messages) . ';
                var tFillGuest = ' . wp_json_encode($i18n_fill_guest) . ';
                var tError = ' . wp_json_encode($i18n_error) . ';
                var tSendFailed = ' . wp_json_encode($i18n_send_failed) . ';
                var tConnectionError = ' . wp_json_encode($i18n_connection_error) . ';
                var tNoTickets = ' . wp_json_encode($i18n_no_tickets) . ';
                var tFillLogin = ' . wp_json_encode($i18n_fill_login) . ';
                var tFillRegister = ' . wp_json_encode($i18n_fill_register) . ';
                var tLoginFailed = ' . wp_json_encode($i18n_login_failed) . ';
                var tRegisterFailed = ' . wp_json_encode($i18n_register_failed) . ';
                var tProfileLabel = ' . wp_json_encode($i18n_profile_label) . ';
                var tLogout = ' . wp_json_encode($i18n_logout) . ';
                var tEdit = ' . wp_json_encode($i18n_edit) . ';
                var tLoginForTickets = ' . wp_json_encode($i18n_login_for_tickets) . ';
                var tAuthLoginTitle = ' . wp_json_encode($i18n_auth_login_title) . ';
                var tAuthRegisterTitle = ' . wp_json_encode($i18n_auth_register_title) . ';
                var tAuthLoginSubmit = ' . wp_json_encode($i18n_auth_login_submit) . ';
                var tAuthRegisterSubmit = ' . wp_json_encode($i18n_auth_register_submit) . ';
                var tAuthSwapToRegister = ' . wp_json_encode($i18n_auth_swap_to_register) . ';
                var tAuthSwapToLogin = ' . wp_json_encode($i18n_auth_swap_to_login) . ';
                var tAuthNoAccount = ' . wp_json_encode($i18n_auth_no_account) . ';
                var tAuthHaveAccount = ' . wp_json_encode($i18n_auth_have_account) . ';
                var tMyTickets = ' . wp_json_encode(__('Мои чаты', self::TEXT_DOMAIN)) . ';
                var tLoginLink = ' . wp_json_encode(__('Войти', self::TEXT_DOMAIN)) . ';
                var profileStorageKey = "support_chat_widget_profile";
                var visitorStorageKey = "support_chat_widget_visitor";
                var lastSignature = "";
                var loading = false;
                var activeTicketId = 0;
                var forceNewTicket = false;
                var composingNewTicket = false;
                var authMode = "login";
                var guestProfile = loadGuestProfile();
                var visitorId = loadVisitorId();
                function escapeHtml(v){
                    return String(v || "").replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");
                }
                function setView(name){
                    viewChat.classList.toggle("active", name === "chat");
                    viewAuth.classList.toggle("active", name === "auth");
                    viewTickets.classList.toggle("active", name === "tickets");
                }
                function loadGuestProfile(){
                    try {
                        var raw = localStorage.getItem(profileStorageKey);
                        if(!raw){ return null; }
                        var data = JSON.parse(raw);
                        if(!data || !data.name || !data.email){ return null; }
                        return data;
                    } catch(e){
                        return null;
                    }
                }
                function saveGuestProfile(name, email){
                    guestProfile = {name: name, email: email};
                    try { localStorage.setItem(profileStorageKey, JSON.stringify(guestProfile)); } catch(e){}
                }
                function clearGuestProfile(){
                    guestProfile = null;
                    try { localStorage.removeItem(profileStorageKey); } catch(e){}
                }
                function loadVisitorId(){
                    try {
                        var raw = localStorage.getItem(visitorStorageKey);
                        if(raw && /^[A-Za-z0-9_-]{16,128}$/.test(raw)){
                            return raw;
                        }
                    } catch(e){}
                    var next = "v_" + Math.random().toString(36).slice(2) + Date.now().toString(36);
                    try { localStorage.setItem(visitorStorageKey, next); } catch(e){}
                    return next;
                }
                function resetVisitorId(){
                    var next = "v_" + Math.random().toString(36).slice(2) + Date.now().toString(36);
                    visitorId = next;
                    try { localStorage.setItem(visitorStorageKey, next); } catch(e){}
                }
                function isValidEmail(email){
                    return /^[^\\s@]+@[^\\s@]+\\.[^\\s@]+$/.test(String(email || "").trim());
                }
                function getGuestPayload(){
                    if(guestProfile && guestProfile.name && guestProfile.email && isValidEmail(guestProfile.email)){
                        return {guest_name: guestProfile.name, guest_email: guestProfile.email};
                    }
                    return {};
                }
                function applyProfileUI(viewer){
                    var hasViewer = viewer && viewer.is_logged_in;
                    if(hasViewer){
                        profileBox.style.display = "block";
                        profileBox.innerHTML = "<div class=\"sc-profile-main\"><span class=\"sc-profile-label\">"+tProfileLabel+"</span><span>" + escapeHtml(viewer.name || "") + "</span><span>·</span><span>" + escapeHtml(viewer.email || "") + "</span></div><div class=\"sc-profile-links\"><a href=\"#\" id=\"scTicketsLink\">"+tMyTickets+"</a><a href=\"#\" id=\"scLogoutLink\">"+tLogout+"</a></div>";
                    } else if (guestProfile && guestProfile.name && guestProfile.email){
                        profileBox.style.display = "block";
                        profileBox.innerHTML = "<div class=\"sc-profile-main\"><span class=\"sc-profile-label\">"+tProfileLabel+"</span><span>" + escapeHtml(guestProfile.name) + "</span><span>·</span><span>" + escapeHtml(guestProfile.email) + "</span></div><div class=\"sc-profile-links\"><a href=\"#\" id=\"scEditProfile\">"+tEdit+"</a><a href=\"#\" id=\"scOpenAuth\">"+tLoginLink+"</a><a href=\"#\" id=\"scOpenTickets\">"+tMyTickets+"</a></div>";
                    } else {
                        profileBox.style.display = "block";
                        profileBox.innerHTML = "<div class=\"sc-profile-main\"><span class=\"sc-profile-label\">"+tProfileLabel+"</span><span>—</span></div><div class=\"sc-profile-links\"><a href=\"#\" id=\"scOpenAuth\">"+tLoginLink+"</a><a href=\"#\" id=\"scOpenTickets\">"+tMyTickets+"</a></div>";
                    }
                    var logoutLink = document.getElementById("scLogoutLink");
                    if(logoutLink){
                        logoutLink.addEventListener("click", function(ev){
                            ev.preventDefault();
                            post({action:"support_chat_widget_logout", nonce: nonce}).then(function(res){
                                if(res && res.success){
                                    loggedIn = false;
                                    clearGuestProfile();
                                    resetVisitorId();
                                    try { localStorage.removeItem(profileStorageKey); } catch(e){}
                                    setView("chat");
                                    reloadState();
                                } else {
                                    alert(tConnectionError);
                                }
                            }).catch(function(){
                                alert(tConnectionError);
                            });
                        });
                    }
                    var openAuth = document.getElementById("scOpenAuth");
                    if(openAuth){
                        openAuth.addEventListener("click", function(ev){
                            ev.preventDefault();
                            setAuthMode("login");
                            setView("auth");
                        });
                    }
                    var openTickets = document.getElementById("scOpenTickets");
                    if(openTickets){
                        openTickets.addEventListener("click", function(ev){
                            ev.preventDefault();
                            setView("tickets");
                            reloadState();
                        });
                    }
                    var ticketsLink = document.getElementById("scTicketsLink");
                    if(ticketsLink){
                        ticketsLink.addEventListener("click", function(ev){
                            ev.preventDefault();
                            setView("tickets");
                            reloadState();
                        });
                    }
                    var editProfile = document.getElementById("scEditProfile");
                    if(editProfile){
                        editProfile.addEventListener("click", function(ev){
                            ev.preventDefault();
                            clearGuestProfile();
                            profileBox.style.display = "none";
                        });
                    }
                }
                function setAuthMode(mode){
                    authMode = mode === "register" ? "register" : "login";
                    if(registerFields){
                        registerFields.style.display = authMode === "register" ? "block" : "none";
                    }
                    if(authTitle){
                        authTitle.textContent = authMode === "register" ? tAuthRegisterTitle : tAuthLoginTitle;
                    }
                    if(authSubmit){
                        authSubmit.textContent = authMode === "register" ? tAuthRegisterSubmit : tAuthLoginSubmit;
                    }
                    if(authSwap && swapLabel){
                        if(authMode === "register"){
                            swapLabel.textContent = tAuthHaveAccount;
                            authSwap.textContent = tAuthSwapToLogin;
                        } else {
                            swapLabel.textContent = tAuthNoAccount;
                            authSwap.textContent = tAuthSwapToRegister;
                        }
                    }
                }
                function renderMessages(items){
                    var html = "";
                    var authCommand = "";
                    for(var i=0;i<items.length;i++){
                        var item = items[i];
                        var mine = item.sender_type !== "admin";
                        if(!mine){
                            var commandText = String(item.message || "").trim().toLowerCase();
                            if(commandText === "/auth" || commandText === "/login" || commandText === "/register"){
                                authCommand = commandText;
                                continue;
                            }
                        }
                        var time = "";
                        if(item.created_at && item.created_at.length >= 16){
                            time = escapeHtml(item.created_at.substring(11,16));
                        } else {
                            time = escapeHtml(item.created_at || "");
                        }
                        html += "<div class=\"sc-msg"+(mine?" mine":"")+"\">";
                        if(!mine){
                            html += "<div class=\"sc-msg-head\"><span class=\"sc-msg-avatar\">◌</span><span>"+tSupport+"</span></div>";
                        }
                        html += "<div class=\"sc-msg-bubble\">"+escapeHtml(item.message)+"</div><div class=\"sc-msg-time\">"+time+"</div></div>";
                    }
                    messagesBox.innerHTML = html || "<div style=\"color:#6a7081;\">"+tNoMessages+"</div>";
                    messagesBox.scrollTop = messagesBox.scrollHeight;
                    lastSignature = JSON.stringify(items);
                    if(authCommand){
                        setAuthMode(authCommand === "/register" ? "register" : "login");
                        setView("auth");
                    }
                }
                function renderTickets(items){
                    if(!items || !items.length){
                        ticketsBox.innerHTML = "<div style=\"color:#6a7081;\">"+tNoTickets+"</div><div style=\"font-size:13px;color:#6a7081;margin-top:8px;\">"+tLoginForTickets+"</div>";
                        return;
                    }
                    var html = "";
                    for(var i=0;i<items.length;i++){
                        var t = items[i];
                        html += "<div class=\"sc-ticket-item"+(Number(t.id) === Number(activeTicketId) ? " active" : "")+"\" data-ticket-id=\""+Number(t.id)+"\"><strong>#"+Number(t.id)+" "+escapeHtml(t.subject)+"</strong><div style=\"font-size:12px;color:#666\">"+escapeHtml(t.status)+" · "+escapeHtml(t.last_message_at)+"</div></div>";
                    }
                    ticketsBox.innerHTML = html;
                    Array.prototype.slice.call(ticketsBox.querySelectorAll(".sc-ticket-item")).forEach(function(item){
                        item.addEventListener("click", function(){
                            activeTicketId = Number(item.getAttribute("data-ticket-id")) || 0;
                            setView("chat");
                            reloadState();
                        });
                    });
                }
                function post(data){
                    if(!data.visitor_id){
                        data.visitor_id = visitorId;
                    }
                    return fetch(ajaxUrl, {
                        method: "POST",
                        headers: {"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},
                        body: new URLSearchParams(data).toString()
                    }).then(function(r){ return r.json(); });
                }
                function reloadState(){
                    return post({action:"support_chat_widget_state", ticket_id: activeTicketId}).then(function(res){
                        if(res && res.success && res.data && Array.isArray(res.data.messages)){
                            if(res.data.nonce){ nonce = res.data.nonce; }
                            if(!composingNewTicket && res.data.ticket_id){
                                activeTicketId = parseInt(res.data.ticket_id, 10) || activeTicketId;
                            }
                            var signature = JSON.stringify(res.data.messages);
                            if(!composingNewTicket && signature !== lastSignature){
                                renderMessages(res.data.messages);
                            }
                            if(Array.isArray(res.data.tickets)){
                                renderTickets(res.data.tickets);
                            }
                            applyProfileUI(res.data.viewer || null);
                        }
                    }).catch(function(){});
                }
                function openPanel(){
                    shell.style.display = "block";
                    shell.setAttribute("aria-hidden", "false");
                    teaser.style.display = "none";
                    post({action:"support_chat_widget_open", nonce: nonce, page_url: window.location.href}).then(function(res){
                        if(res && res.data && res.data.nonce){ nonce = res.data.nonce; }
                    }).catch(function(){});
                    forceNewTicket = false;
                    composingNewTicket = false;
                    setView("chat");
                    reloadState();
                }
                function closePanel(){
                    shell.style.display = "none";
                    shell.setAttribute("aria-hidden", "true");
                }
                launcher.addEventListener("click", openPanel);
                closeBtn.addEventListener("click", closePanel);
                if(backFromAuth){ backFromAuth.addEventListener("click", function(){ setView("chat"); }); }
                if(backFromTickets){ backFromTickets.addEventListener("click", function(){ setView("chat"); }); }
                if(createTicketBtn){
                    createTicketBtn.addEventListener("click", function(){
                        forceNewTicket = true;
                        composingNewTicket = true;
                        activeTicketId = 0;
                        renderMessages([]);
                        input.focus();
                    });
                    createTicketBtn.addEventListener("dblclick", function(ev){ ev.preventDefault(); });
                }
                window.setTimeout(function(){
                    if(shell.style.display !== "block"){
                        teaser.style.display = "block";
                    }
                }, 3000);
                teaser.addEventListener("click", openPanel);
                sendBtn.addEventListener("click", function(){
                    if(loading){ return; }
                    var text = (input.value || "").trim();
                    if(!text){ return; }
                    var payload = {
                        action: "support_chat_widget_send",
                        nonce: nonce,
                        message: text,
                        force_new_ticket: forceNewTicket ? 1 : 0
                    };
                    if(!loggedIn){
                        Object.assign(payload, getGuestPayload());
                    }
                    loading = true;
                    sendBtn.disabled = true;
                    sendBtn.classList.remove("active");
                    post(payload).then(function(res){
                        if(res && res.success && res.data && Array.isArray(res.data.messages)){
                            if(res.data.ticket_id){
                                activeTicketId = parseInt(res.data.ticket_id, 10) || activeTicketId;
                            }
                            forceNewTicket = false;
                            composingNewTicket = false;
                            input.value = "";
                            renderMessages(res.data.messages);
                            reloadState();
                        } else if(res && res.data && res.data.error){
                            if(res.data.error === "invalid_nonce" && res.data.nonce){
                                nonce = res.data.nonce;
                                sendBtn.disabled = false;
                                loading = false;
                                sendBtn.click();
                                return;
                            }
                            var extra = res.data.details ? " (" + res.data.details + ")" : "";
                            alert(tError + ": " + res.data.error + extra);
                        } else {
                            alert(tSendFailed);
                        }
                    }).catch(function(){
                        alert(tConnectionError);
                    }).finally(function(){
                        loading = false;
                        sendBtn.disabled = false;
                        if(input.value.trim() !== ""){
                            sendBtn.classList.add("active");
                        }
                    });
                });
                if(input){
                    input.addEventListener("input", function(){
                        if(input.value.trim() !== ""){
                            sendBtn.classList.add("active");
                        } else {
                            sendBtn.classList.remove("active");
                        }
                    });
                    input.addEventListener("keydown", function(ev){
                        if(ev.key === "Enter"){
                            ev.preventDefault();
                            sendBtn.click();
                        }
                    });
                }
                if(authSubmit){
                    authSubmit.addEventListener("click", function(){
                        var login = loginInput && loginInput.value ? loginInput.value.trim() : "";
                        var password = loginPassword && loginPassword.value ? loginPassword.value : "";
                        if(authMode === "register"){
                            var username = registerUsername && registerUsername.value ? registerUsername.value.trim() : "";
                            if(!login || !password || !username){
                                alert(tFillRegister);
                                return;
                            }
                            if(!username || password.length < 6 || !isValidEmail(login)){
                                alert(tFillRegister);
                                return;
                            }
                            post({
                                action: "support_chat_widget_register",
                                nonce: nonce,
                                username: username,
                                email: login,
                                password: password
                            }).then(function(res){
                                if(res && res.success){
                                    loggedIn = true;
                                    if(res.data && res.data.nonce){ nonce = res.data.nonce; }
                                    clearGuestProfile();
                                    forceNewTicket = false;
                                    composingNewTicket = false;
                                    setView("tickets");
                                    reloadState();
                                } else {
                                    if(res && res.data && res.data.error === "invalid_nonce" && res.data.nonce){
                                        nonce = res.data.nonce;
                                        return;
                                    }
                                    alert(tRegisterFailed);
                                }
                            }).catch(function(){
                                alert(tConnectionError);
                            });
                        } else {
                            if(!login || !password){
                                alert(tFillLogin);
                                return;
                            }
                            post({
                                action: "support_chat_widget_login",
                                nonce: nonce,
                                login: login,
                                password: password
                            }).then(function(res){
                                if(res && res.success){
                                    loggedIn = true;
                                    if(res.data && res.data.nonce){ nonce = res.data.nonce; }
                                    forceNewTicket = false;
                                    composingNewTicket = false;
                                    setView("tickets");
                                    reloadState();
                                } else {
                                    if(res && res.data && res.data.error === "invalid_nonce" && res.data.nonce){
                                        nonce = res.data.nonce;
                                        return;
                                    }
                                    alert(tLoginFailed);
                                }
                            }).catch(function(){
                                alert(tConnectionError);
                            });
                        }
                    });
                }
                if(authSwap){
                    authSwap.addEventListener("click", function(ev){
                        ev.preventDefault();
                        if(!allowRegistration){ return; }
                        setAuthMode(authMode === "login" ? "register" : "login");
                    });
                }
                applyProfileUI(null);
                setAuthMode("login");
                window.setInterval(function(){
                    if(shell.style.display === "block"){
                        reloadState();
                    }
                }, 5000);
            })();
        </script>';
    }
}

new Support_Chat_Telegram_Plugin();
