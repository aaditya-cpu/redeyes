<?php
/**
 * Plugin Name: Native Network Monitor with Server Resources
 * Description: Monitors network-wide traffic, database queries, server memory usage, disk usage per site, and CPU load without relying on third-party services. Provides a detailed view of site visits, database query counts, server memory usage, disk usage, and CPU load for each site within a WordPress Multisite Network.
 * Version: 1.2
 * Author: Death 301
 * Network: true
 */

if (!defined('WPINC')) {
    die;
}

class Native_Network_Monitor {
    public function __construct() {
        add_action('wp_loaded', [$this, 'track_site_usage']);
        add_filter('query', [$this, 'track_database_queries']);
        add_action('admin_menu', [$this, 'add_admin_page']);
    }

    public function track_site_usage() {
        if (!is_multisite()) {
            return;
        }
        $current_site_id = get_current_blog_id();
        $visits = get_site_option("site_visits_{$current_site_id}", 0);
        update_site_option("site_visits_{$current_site_id}", ++$visits);
    }

    public function track_database_queries($query) {
        if (!is_multisite()) {
            return $query;
        }
        $current_site_id = get_current_blog_id();
        $query_count = get_site_option("site_queries_{$current_site_id}", 0);
        update_site_option("site_queries_{$current_site_id}", ++$query_count);
        return $query;
    }

    public function add_admin_page() {
        if (function_exists('is_network_admin') && is_network_admin()) {
            add_menu_page('Network Monitor', 'Network Monitor', 'manage_network', 'network-monitor', [$this, 'admin_page_html'], 'dashicons-networking');
        }
    }

    public function admin_page_html() {
        if (!current_user_can('manage_network')) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>Network Monitor Dashboard</h1>';

        $memoryUsage = $this->get_server_memory_usage();
        $cpuLoad = $this->get_cpu_load();

        echo "<h2>Server Memory Usage</h2>";
        echo "<p>Total Memory: {$memoryUsage['total']} MB</p>";
        echo "<p>Used Memory: {$memoryUsage['used']} MB</p>";
        echo "<p>Free Memory: {$memoryUsage['free']} MB</p>";

        echo "<h2>CPU Load</h2>";
        echo "<p>Last 1 minute: {$cpuLoad['1min']}</p>";
        echo "<p>Last 5 minutes: {$cpuLoad['5min']}</p>";
        echo "<p>Last 15 minutes: {$cpuLoad['15min']}</p>";

        $sites = get_sites();
        foreach ($sites as $site) {
            switch_to_blog($site->blog_id);

            $visits = get_site_option("site_visits_{$site->blog_id}", 0);
            $queries = get_site_option("site_queries_{$site->blog_id}", 0);
            $diskUsage = $this->get_site_disk_usage($site->blog_id);

            echo "<h2>Site: " . get_bloginfo('name') . " (ID: {$site->blog_id})</h2>";
            echo "<p>Visits: $visits</p>";
            echo "<p>Database Queries: $queries</p>";
            echo "<p>Database Size: {$diskUsage['database']} MB</p>";
            echo "<p>Uploads Directory Size: {$diskUsage['uploads']} MB</p>";

            restore_current_blog();
        }

        echo '</div>';
    }

    private function get_server_memory_usage() {
        $output = shell_exec('free');
        preg_match('/Mem:\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $output, $matches);
        $memoryTotal = round($matches[1] / 1024, 2);
        $memoryUsed = round($matches[2] / 1024, 2);
        $memoryFree = round($matches[3] / 1024, 2);

        return [
            'total' => $memoryTotal,
            'used' => $memoryUsed,
            'free' => $memoryFree,
        ];
    }

    private function get_cpu_load() {
        $load = sys_getloadavg();
        return [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2],
        ];
    }

    private function get_site_disk_usage($blog_id) {
        switch_to_blog($blog_id);
        global $wpdb;
        $rows = $wpdb->get_results("SHOW TABLE STATUS");
        $databaseSize = array_sum(array_map(function ($row) { return $row->Data_length + $row->Index_length; }, $rows)) / 1024 / 1024;

        $upload_dir = wp_upload_dir();
        $uploadsSize = $this->folderSize($upload_dir['basedir']) / 1024 / 1024;

        restore_current_blog();

        return [
            'database' => round($databaseSize, 2),
            'uploads' => round($uploadsSize, 2),
        ];
    }

    private function folderSize($dir) {
        $size = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }
}

new Native_Network_Monitor();
