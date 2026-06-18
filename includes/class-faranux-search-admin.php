<?php
if (!defined('ABSPATH')) {
    exit;
}

class Faranux_Search_Admin
{

    public function __construct()
    {
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_post_faranux_clean_orphans', array($this, 'clean_orphans'));
        add_action('admin_post_faranux_rebuild_dict', array($this, 'rebuild_dict'));
        add_action('admin_post_faranux_reindex', array($this, 'reindex_all'));
        add_action('admin_post_faranux_save_settings', array($this, 'save_settings'));
    }

    public function register_admin_page()
    {
        add_options_page('Faranux Search Metrics', 'Faranux Search', 'manage_options', 'faranux-search-admin', array($this, 'render_dashboard'));
    }

    public function render_dashboard()
    {
        global $wpdb;
        $table = Faranux_Search_DB::instance()->table_name();

        $total_rows = $wpdb->get_var("SELECT COUNT(*) FROM {$table}") ?: 0;
        $indexed_items = $wpdb->get_var("SELECT COUNT(DISTINCT post_id) FROM {$table}") ?: 0;
        $orphans = $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})") ?: 0;

        if (isset($_GET['dict_rebuilt'])) {
            echo '<div class="notice notice-success"><p>Dictionary rebuilt successfully!</p></div>';
        }
        if (isset($_GET['reindex_done'])) {
            echo '<div class="notice notice-success"><p>Full re-index completed successfully!</p></div>';
        }
        if (isset($_GET['settings_saved'])) {
            echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
        }

        $default_stops = 'a, also, am, an, and, are, as, at, be, but, by, call, can, co, con, de, do, due, eg, eight, etc, even, ever, every, for, from, full, go, had, has, hasnt, have, he, hence, her, here, his, how, ie, if, in, inc, into, is, it, its, ltd, me, my, no, none, nor, not, now, of, off, on, once, one, only, onto, or, our, ours, out, over, own, part, per, put, re, see, so, some, ten, than, that, the, their, there, these, they, this, three, thru, thus, to, too, top, un, up, us, very, via, was, we, well, were, what, when, where, who, why, will';
        $stop_words = get_option('faranux_stop_words', $default_stops);
        $max_results = get_option('faranux_max_results', 12);
        ?>
        <div class="wrap">
            <h1>Faranux Search Settings & Metrics</h1>
            <div style="display:flex; gap: 20px; margin-top: 20px; flex-wrap: wrap;">
                <div style="background:#fff; padding: 20px; border:1px solid #ccc; border-radius: 8px; width: 220px;">
                    <h3 style="margin-top:0;">Total Rows</h3>
                    <p style="font-size: 28px; font-weight:bold; margin:0;"><?php echo number_format($total_rows); ?></p>
                </div>
                <div style="background:#fff; padding: 20px; border:1px solid #ccc; border-radius: 8px; width: 220px;">
                    <h3 style="margin-top:0;">Indexed Products</h3>
                    <p style="font-size: 28px; font-weight:bold; margin:0;"><?php echo number_format($indexed_items); ?></p>
                </div>
                <div
                    style="background:#fff; padding: 20px; border:1px solid #ccc; border-radius: 8px; width: 220px; border-left: 4px solid #d63638;">
                    <h3 style="margin-top:0; color:#d63638;">Orphaned Rows</h3>
                    <p style="font-size: 28px; font-weight:bold; margin:0; color:#d63638;">
                        <?php echo number_format($orphans); ?></p>
                    <?php if ($orphans > 0): ?>
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST" style="margin-top:15px;">
                            <input type="hidden" name="action" value="faranux_clean_orphans">
                            <?php wp_nonce_field('faranux_clean_nonce'); ?>
                            <button type="submit" class="button button-primary">Sweep Orphans</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Settings Section -->
            <div
                style="margin-top: 30px; background:#fff; padding: 20px; border:1px solid #ccc; border-radius: 8px; max-width: 800px;">
                <h2 style="margin-top:0;">Engine Configuration</h2>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="faranux_save_settings">
                    <?php wp_nonce_field('faranux_settings_nonce'); ?>

                    <p><strong>Select fields to index:</strong></p>
                    <?php
                    $fields = get_option('faranux_index_fields', ['title' => 1, 'content' => 1, 'excerpt' => 0, 'sku' => 1, 'attributes' => 1]);
                    ?>
                    <label><input type="checkbox" name="faranux_index_fields[title]" value="1" <?php checked($fields['title'], 1); ?>> Title</label><br>
                    <label><input type="checkbox" name="faranux_index_fields[content]" value="1" <?php checked($fields['content'], 1); ?>> Description (Content)</label><br>
                    <label><input type="checkbox" name="faranux_index_fields[excerpt]" value="1" <?php checked($fields['excerpt'], 1); ?>> Short Description</label><br>
                    <label><input type="checkbox" name="faranux_index_fields[sku]" value="1" <?php checked($fields['sku'], 1); ?>> SKU</label><br>
                    <label><input type="checkbox" name="faranux_index_fields[attributes]" value="1" <?php checked($fields['attributes'], 1); ?>> Attributes</label><br>

                    <hr>
                    <p><strong>Stop Words List</strong></p>
                    <p style="font-size:13px; color:#666; margin-top:-10px;">Comma-separated list of words to ignore. This keeps
                        your index incredibly fast.</p>
                    <textarea name="faranux_stop_words" rows="5"
                        style="width:100%; border-radius:4px; border:1px solid #8c8f94; padding:10px;"><?php echo esc_textarea($stop_words); ?></textarea>

                    <hr>
                    <p><strong>Max AJAX Results</strong></p>
                    <p style="font-size:13px; color:#666; margin-top:-10px;">The maximum number of items to show in the live
                        search dropdown.</p>
                    <input type="number" name="faranux_max_results" value="<?php echo esc_attr($max_results); ?>" min="1"
                        max="50" style="width:80px; padding: 5px;">

                    <hr>
                    <p><label><input type="checkbox" name="faranux_enable_logging" value="1" <?php checked(get_option('faranux_enable_logging', 0), 1); ?>> Enable internal debugging logs</label>
                    </p>

                    <p style="margin-top:20px;"><input type="submit" class="button button-primary" value="Save Settings"></p>
                </form>
                <p style="font-size:12px; color:#d63638;"><strong>Note:</strong> If you change fields or stop words, you must
                    click <strong>Re-index All</strong> below to apply the changes to existing components.</p>
            </div>

            <!-- Maintenance Tools -->
            <div
                style="margin-top: 30px; background:#fff; padding: 20px; border:1px solid #ccc; border-radius: 8px; max-width: 800px;">
                <h2 style="margin-top:0;">Maintenance Tools</h2>
                <div style="display:flex; gap: 15px; flex-wrap: wrap;">
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST">
                        <input type="hidden" name="action" value="faranux_rebuild_dict">
                        <?php wp_nonce_field('faranux_dict_nonce'); ?>
                        <button type="submit" class="button button-secondary" style="padding: 8px 20px;">🔄 Rebuild
                            Dictionary</button>
                    </form>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST">
                        <input type="hidden" name="action" value="faranux_reindex">
                        <?php wp_nonce_field('faranux_reindex_nonce'); ?>
                        <button type="submit" class="button button-primary" style="padding: 8px 20px;">⚡ Re-index All
                            Products</button>
                    </form>
                </div>
            </div>

        </div>
        <?php
    }

    public function save_settings()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'faranux_settings_nonce')) {
            wp_die('Unauthorized');
        }
        if (isset($_POST['faranux_index_fields']) && is_array($_POST['faranux_index_fields'])) {
            $fields = array_map('intval', $_POST['faranux_index_fields']);
            update_option('faranux_index_fields', $fields);
        } else {
            update_option('faranux_index_fields', ['title' => 0, 'content' => 0, 'excerpt' => 0, 'sku' => 0, 'attributes' => 0]);
        }

        // Save the Stop Words
        if (isset($_POST['faranux_stop_words'])) {
            update_option('faranux_stop_words', sanitize_textarea_field(wp_unslash($_POST['faranux_stop_words'])));
        }

        // Save Max Results
        if (isset($_POST['faranux_max_results'])) {
            update_option('faranux_max_results', absint($_POST['faranux_max_results']));
        }

        $logging = isset($_POST['faranux_enable_logging']) ? 1 : 0;
        update_option('faranux_enable_logging', $logging);
        wp_redirect(add_query_arg('settings_saved', '1', wp_get_referer()));
        exit;
    }

    public function rebuild_dict()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'faranux_dict_nonce')) {
            wp_die('Unauthorized');
        }
        delete_transient('faranux_search_dictionary');
        delete_transient('faranux_search_dictionary_data');
        wp_redirect(add_query_arg('dict_rebuilt', '1', wp_get_referer()));
        exit;
    }

    public function reindex_all()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'faranux_reindex_nonce')) {
            wp_die('Unauthorized');
        }
        Faranux_Search_Indexer::bulk_reindex();
        delete_transient('faranux_search_dictionary');
        delete_transient('faranux_search_dictionary_data');
        wp_redirect(add_query_arg('reindex_done', '1', wp_get_referer()));
        exit;
    }

    public function clean_orphans()
    {
        if (!current_user_can('manage_options') || !wp_verify_nonce($_POST['_wpnonce'], 'faranux_clean_nonce')) {
            wp_die();
        }
        global $wpdb;
        $table = Faranux_Search_DB::instance()->table_name();
        $wpdb->query("DELETE FROM {$table} WHERE post_id NOT IN (SELECT ID FROM {$wpdb->posts})");
        wp_redirect(admin_url('options-general.php?page=faranux-search-admin&cleaned=true'));
        exit;
    }
}