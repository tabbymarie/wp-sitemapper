<?php
/*
Plugin Name: WP SiteMapper
Description: Google Sitemap Generator with Admin Tools. Saves your sitemap index to http://yourdomain.com/sitemap.xml
Version: 1.0
Author: Tabby
*/

$sitemap = new wpSiteMapper();

/**
 * Class wpSiteMapper
 */
register_deactivation_hook(__FILE__, array('wpSiteMapper', 'wp_sitemapper_cron_deactivation'));

/**
 * Class wpSiteMapper
 */
class wpSiteMapper
{
    /**
     * @var int
     * This allows for the sitemap to do queries on a smaller scale to avoid memory issues
     */
    public $max_rows;


    /**
     * Initializes the plugin and starts the cron
     */
    function __construct()
    {

        add_action('wp_sitemapper_cron', array($this, 'wp_sitemapper_cron_function'));
        
	    if (!wp_next_scheduled('wp_sitemapper_cron')) {
            wp_schedule_event(time(), 'hourly', 'wp_sitemapper_cron');
        }

        $this->max_rows = 300;

        add_action('admin_menu', array($this, 'wp_sitemapper_plugin_menu'));

    }

    /**
     * This is the beast that does it all.
     */
    public function printSiteMapIndex()
    {
        $xml = new SimpleXMLElement('<sitemapindex/>');
        $post_types = $this->getAllPostTypes();

        foreach ($post_types as $key => $type) {

            $name = $key;
            $filename = "sitemap_" . $name . ".xml";
            $public_filename = get_site_url() . "/" . $filename;
            $this->makeSubMap($name);
            $filetime = date("c", filemtime(ABSPATH . $filename));
            $sitemap = $xml->addChild('sitemap');
            $sitemap->addChild('loc', $public_filename);
            $sitemap->addChild('lastmod', $filetime);
        }
        $xml->saveXML(ABSPATH . "sitemap.xml");

    }

    /**
     * @return array
     */
    private function getAllPostTypes()
    {
        $selected_post_types = unserialize(get_option("wp_sitemapper_posttypes"));

        $post_types = get_post_types(array("public" => true));
        unset($post_types["attachment"]);

        return array_intersect($post_types, $selected_post_types);
    }

    /**
     * @param $post_type
     */
    public function makeSubMap($post_type)
    {
        $filename = ABSPATH . "sitemap_" . $post_type . ".xml";

        $xml = new SimpleXMLElement('<urlset/>');
        $xml->addAttribute("xmlns", "http://www.sitemaps.org/schemas/sitemap/0.9");
        $exclude_posts = unserialize(get_option("wp_sitemapper_exclude_postIDs"));


        global $wpdb;
        $sql = "select count(ID) as cnt from " . $wpdb->posts . " where post_type='" . $post_type . "' and post_status='publish'";

        $countObj = $wpdb->get_row($sql);


        if ($this->max_rows) {
            $numLoops = $countObj->cnt / $this->max_rows;
            if ($countObj->cnt % $this->max_rows != 0) {
                $numLoops = intval($numLoops) + 1;
            }
        } else {
            $numLoops = 0;
        }

        $start = 0;
        for ($i = 1; $i <= $numLoops; $i++) {
            $sql2 = "select ID from " . $wpdb->posts . " where post_type='" . $post_type . "' and post_status='publish' ";
            if ($exclude_posts) {
                $sql2 .= "and ID not in (" . implode(",", $exclude_posts) . ")";
            }
            $sql2 .= " order by post_date desc limit " . $start . "," . $this->max_rows;
            $posts = $wpdb->get_results($sql2);
            foreach ($posts as $post) {

                $sitemap = $xml->addChild('url');
                $sitemap->addChild('loc', get_permalink($post->ID));
                $postdate = new DateTime($post->post_date_gmt);
                $sitemap->addChild('lastmod', date_format($postdate, "c"));

            }
            $start = $start + $this->max_rows;
        }

        $xml->saveXML($filename);

    }

    /**
     *
     */
    function wp_sitemapper_cron_function()
    {
        $selected_post_types = get_option("wp_sitemapper_posttypes");
        if($selected_post_types){
            $this->printSiteMapIndex();
        }
    }


    /**
     *
     */
    function wp_sitemapper_cron_deactivation()
    {
        wp_clear_scheduled_hook('wp_sitemapper_cron');
    }


    /**
     *
     */
    function wp_sitemapper_plugin_menu()
    {
        add_options_page('WP SiteMapper Plugin', 'WP SiteMapper Plugin', 'manage_options', 'wp_sitemapper_plugin', array($this, 'wp_sitemapper_plugin_options'));
    }


    /**
     *
     */
    function wp_sitemapper_plugin_options()
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.'));
        }

        if ($_REQUEST['wpsmpr_submit']) {

            $selected_post_types_array = $_REQUEST['sitemapposttypes'];

            if (count($selected_post_types_array) > 0) {
                update_option("wp_sitemapper_posttypes", serialize($selected_post_types_array));
            } else {
                update_option("wp_sitemapper_posttypes", "");
            }
            if ($_REQUEST['wpsmpr_exclude_postIDs']) {
                $exclude_posts_items = $_REQUEST['wpsmpr_exclude_postIDs'];
                $exclude_posts_array = explode("\r\n", trim($exclude_posts_items));

                $exclude_posts_array = array_unique($exclude_posts_array, SORT_NUMERIC);
                if (count($exclude_posts_array) > 0) {
                    update_option("wp_sitemapper_exclude_postIDs", serialize($exclude_posts_array));
                } else {
                    update_option("wp_sitemapper_exclude_postIDs", "");
                }
            } else {
                update_option("wp_sitemapper_exclude_postIDs", "");
            }
        }


        echo '<div class="wrap"><form id="wpsmpr_options" method="POST" action="">';

        $selected_post_types = unserialize(get_option("wp_sitemapper_posttypes"));

        $post_types = get_post_types(array("public" => true));
        unset($post_types["attachment"]);
        echo "<h2>WP SiteMapper</h2>";
        echo "<h3>Select which post types to create sitemaps for</h3>";
        if (count($post_types) >= 1) {
            echo "<ul>";
        }
        foreach ($post_types as $type) {
            echo "<li><input type='checkbox' name='sitemapposttypes[]' value='" . $type . "' ";
            if (in_array($type, $selected_post_types)) {
                echo " checked=checked ";
            }
            echo "/>" . $type . "</li>";
        }
        if (count($post_types) >= 1) {
            echo "</ul>";
        }
        echo "<h3>To exclude posts, please put one ID per line in the text box below.</h3>";
        $exclude_posts = unserialize(get_option("wp_sitemapper_exclude_postIDs"));
        $poststoexclude = "";
        if ($exclude_posts) {
            $poststoexclude = implode("\r\n", $exclude_posts);
        }
        echo "<textarea name='wpsmpr_exclude_postIDs'>" . $poststoexclude . "</textarea>";
        echo "<br />";
        echo "<input type='submit' name='wpsmpr_submit' value='Update Options' />";
        echo '</form></div>';
    }
}

