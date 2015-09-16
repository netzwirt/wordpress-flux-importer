<?php
/*
Plugin Name: FluxCMS Blog Importer
Description: Import <strong>posts, comments, categories and tags</strong> from a FluxCMS Database.
*/


if ( ! is_admin() ) {
    return;
}

@session_start();



// start output chache if file download is requested
if(isset($_POST['sendfile']) && $_POST['sendfile'] == '1' ) {
    ob_start();
}
else if(isset($_POST['save_settings']) && $_POST['save_settings'] != '' ) {
    ob_start();
}

if (!defined('WP_LOAD_IMPORTERS') ) {
    return;
}



$pluginspath = plugin_dir_path(__FILE__);
$pluginspath = str_replace('fluxcmsblog-importer/','',$pluginspath);

define('ABS_PLUGIN', $pluginspath);

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';

if (! class_exists( 'WP_Importer' ) ) {
    $class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
    if (file_exists( $class_wp_importer ) ) {
        require $class_wp_importer;
    }
}

// load wordpress importer
$class_wp_import = ABS_PLUGIN . 'wordpress-importer/wordpress-importer.php';
if (file_exists( $class_wp_import ) ) {
    require_once $class_wp_import;
}

/**
 * WP_FluxCmsBlog_Import Importer
 *
 * @package WordPress
 * @subpackage Importer
 */
if (!class_exists( 'WP_Import' ) ) {

    // create fake class which is just showing an error
    class WP_FluxCmsBlog_Import {

        function WP_FluxCmsBlog_Import() { /* nothing */ }

        function dispatch() {
            // show error and stop
            echo '<div class="wrap">';
            echo '<h2>FluxCMS Blog importer</h2>';
            echo '<p><strong>Wordpress importer is not installed</strong></p>';
            echo '</div>';
            return false;
        }
    }

} else {

    // start real importer class
    class WP_FluxCmsBlog_Import extends WP_Import {

        private $flux_dbconn     = false;
        private $flux_prefix     = false;
        private $flux_db         = false;
        private $flux_user       = false;
        private $flux_pw         = false;
        private $flux_host       = false;
        private $flux_blogid     = false;
        private $flux_blogpath   = false;
        private $add_category    = false;

        function WP_FluxCmsBlog_Import() { /* nothing */ }

        /**
         * Registered callback function for the WordPress Importer
         *
         * Manages the three separate stages of the WXR import process
         */
        function dispatch() {
            
            
        // check for save options
            if(isset($_POST['save_settings'])) {
                $this->send_settings();
            }
            
            
            // check for load options
            if(isset($_POST['load_settings'])) {
                $this->load_settings();
            }
            
            
            
            $this->header();

            $step = empty( $_GET['step'] ) ? 0 : (int) $_GET['step'];
            switch ( $step ) {
                case 0:
                    $this->greet();
                    break;
                case 1:
                    check_admin_referer( 'database-connection' );
                    if ( $this->handle_import() ) {
                        $this->import_options();
                    }
                    else {
                        // back to step one
                        $this->greet();
                    }
                    break;
                case 2:
                    check_admin_referer( 'import-wordpress' );
                    $this->fetch_attachments = ( ! empty( $_POST['fetch_attachments'] ) && $this->allow_fetch_attachments() );
                    $this->id = (int) $_POST['import_id'];
                    $file = get_attached_file( $this->id );
                    set_time_limit(0);
                    $this->import( $file );
                    break;
            }

            $this->footer();
        }
        
        
        private function get_post_options() {
        
            $this->flux_prefix     = @trim($_POST['prefix']);
            $this->flux_db         = @trim($_POST['database_name']);
            $this->flux_user       = @trim($_POST['db_username']);
            $this->flux_pw         = @trim($_POST['db_password']);
            $this->flux_host       = @trim($_POST['hostname']);
            $this->flux_blogid     = @trim($_POST['blogid']);
            $this->flux_blogpath   = @trim($_POST['blogpath']);
            $this->add_category    = @trim($_POST['add_category']);
            
        }
        
        
        
        
        private function send_settings() {
            
            
            $this->get_post_options();
            
            
            unset($_POST['save_settings']);
            
            $content = serialize($_POST);
            
            header('Content-Type: text/plain');
            header("Content-disposition: attachment; filename=\"import-settings-{$this->flux_host}.txt\"");
            
            
            // clean ouput buffer
            // we just want the xml
            ob_end_clean();
            
            echo $content;
            
            die();
            
        }
        
        
        
        private function load_settings() {
        
        
            if(isset($_FILES['load_settings']['tmp_name']) ) {
                $settings = unserialize(implode('',file($_FILES['load_settings']['tmp_name']) ) );
                
                $_POST = $settings;
                
                $_GET['step'] = 0;
                
            }

        }

        // just a copy of original function
        // the only change is the action in the form
        // from wordpress to
        function import_options() {
            $j = 0;
            ?>
<form
	action="<?php echo admin_url( 'admin.php?import=fluxcmsblog&amp;step=2' ); ?>"
	method="post">
	<?php wp_nonce_field( 'import-wordpress' ); ?>
	<input type="hidden" name="import_id" value="<?php echo $this->id; ?>" />

	<?php if ( ! empty( $this->authors ) ) : ?>
	<h3>
		<?php _e( 'Assign Authors', 'wordpress-importer' ); ?>
	</h3>
	<p>
		<?php _e( 'To make it easier for you to edit and save the imported content, you may want to reassign the author of the imported item to an existing user of this site. For example, you may want to import all the entries as <code>admin</code>s entries.', 'wordpress-importer' ); ?>
	</p>
	<?php if ( $this->allow_create_users() ) : ?>
	<p>
		<?php printf( __( 'If a new user is created by WordPress, a new password will be randomly generated and the new user&#8217;s role will be set as %s. Manually changing the new user&#8217;s details will be necessary.', 'wordpress-importer' ), esc_html( get_option('default_role') ) ); ?>
	</p>
	<?php endif; ?>
	<ol id="authors">
		<?php foreach ( $this->authors as $author ) : ?>
		<li><?php $this->author_select( $j++, $author ); ?></li>
		<?php endforeach; ?>
	</ol>
	<?php endif; ?>

	<?php if ( $this->allow_fetch_attachments() ) : ?>
	<h3>
		<?php _e( 'Import Attachments', 'wordpress-importer' ); ?>
	</h3>
	<p>
		<input type="checkbox" value="1" name="fetch_attachments"
			id="import-attachments" /> <label for="import-attachments"><?php _e( 'Download and import file attachments', 'wordpress-importer' ); ?>
		</label>
	</p>
	<?php endif; ?>

	<p class="submit">
		<input type="submit" class="button"
			value="<?php esc_attr_e( 'Submit', 'wordpress-importer' ); ?>" />
	</p>
</form>
<?php
        }


        // Display import page title
        function header() {
            echo '<div class="wrap">';
            screen_icon();
            echo '<h2>FluxCMS Blog importer</h2>';

            $updates = get_plugin_updates();
            $basename = plugin_basename(__FILE__);
            if ( isset( $updates[$basename] ) ) {
                $update = $updates[$basename];
                echo '<div class="error"><p><strong>';
                printf( __( 'A new version of this importer is available. Please update to version %s to ensure compatibility with newer export files.', 'wordpress-importer' ), $update->update->new_version );
                echo '</strong></p></div>';
            }
        }

        // Close div.wrap
        function footer() {
            echo '</div>';
        }

        /**
         * Display introductory text and file upload form
         */
        function greet() {
            echo '<div class="narrow">';
            echo '<p>Enter all connection infos and press <b>"Start import"</b></p>';
            WP_FluxCmsBlog_Importer_connection_form('admin.php?import=fluxcmsblog&amp;step=1' );
            echo '</div>';
        }

        function im_not_error_object() {
            // just a dummy function for add_filter
        }

        /**
         * Handles the WXR import and initial parsing of the file to prepare for
         * displaying author import options
         *
         * @return bool
         */
        function handle_import() {

            $file = $this->import_and_save();

            if(!$file) {

                // stop buffering to see the errors
                if(isset($_POST['sendfile']) && $_POST['sendfile'] == '1' ) {
                    ob_end_flush();
                }

                echo "<p><strong>Could not create import file</strong></p>";
                return false;
            }


            //  from function
            //  wp_import_handle_upload() {
            //  the wordpress importer want to work with the file id
            //  Construct the object array
            $object = array( 'post_title'  => "title:$file",
                    'post_content'         => "content:$file",
                    'post_mime_type'       => 'text/xml',
                    'guid'                 => "guid:$file",
                    'context'              => 'import',
                    'post_status'          => 'private'
            );

            // Save the data
            mysql_select_db( DB_NAME );
            $id = wp_insert_attachment($object, $file);

            // schedule a cleanup for one day from now in case of
            // failed import or missing wp_import_cleanup() call
            wp_schedule_single_event( time() + DAY_IN_SECONDS, 'importer_scheduled_cleanup', array( $id ) );

            $this->id = $id;
             
            // from here the rest of the function is identical to original function
            $import_data = $this->parse( $file );
            if ( is_wp_error( $import_data ) ) {
                echo '<p><strong>' . __( 'Sorry, there has been an error.', 'wordpress-importer' ) . '</strong><br />';
                echo esc_html( $import_data->get_error_message() ) . '</p>';
                return false;
            }

            $this->version = $import_data['version'];
            if ( $this->version > $this->max_wxr_version ) {
                echo '<div class="error"><p><strong>';
                printf( __( 'This WXR file (version %s) may not be supported by this version of the importer. Please consider updating.', 'wordpress-importer' ), esc_html($import_data['version']) );
                echo '</strong></p></div>';
            }

            // now its time to delete old posts and comments
            // we have a new import file and it looks ok :)

            $this->delete_all_posts_and_comments();

            $this->get_authors_from_import( $import_data );

            return true;
        }

        /**
         * delete all entries with type 'post'
         * and it's comments
         */
        private function delete_all_posts_and_comments() {

            if ( isset($_POST['clean_db']) && $_POST['clean_db'] == '1' ) {

                global $wp_query;

                $options = array(
                        'post_type' => 'post',
                        'nopaging' => true,
                        'meta_key' => '_fluxcms_blog_import',
                        'meta_value' => "{$this->flux_blogid}",
                        'meta_compare' => '=',
                        );
                $posts = $wp_query->query($options);

                echo "<h3>Deleting posts</h3>";

                $delete_counter = 1;
                foreach ($posts as $post) {

                    // delete post
                    wp_delete_post($post->ID, true);

                    if($delete_counter%5 == 1) {
                        echo "$delete_counter .. ";
                        flush();
                    }
                    echo str_repeat(' ',200);
                    $delete_counter++;
                    flush();
                }


                $options = array(
                        'post_type'     => 'attachment',
                        'numberposts'   => null,
                        'post_status'   => 'any',
                        'post_parent'   => null,
                        'nopaging'      => true,
                        'meta_key'      => '_wp_attachment_context',
                        'meta_value'    => "fluxcms_blog_import_{$this->flux_blogid}",
                        'meta_compare'  => '=',
                        );

                $attachments = $wp_query->query($options);

                foreach ($attachments as $att) {
                    //echo "delete $att->ID <br>";
                    wp_delete_attachment( $att->ID, true );
                }


            }

        }


        /**
         * Get posts from flux db
         * and save generated file to tmp dir
         *
         * @return null | false | string
         */
        private function import_and_save() {


            $this->get_post_options();
            
            $check_common = true;

            if($this->flux_host == '') {
                echo '<p><strong>Please provide a hostname</strong></p>';
                $check_common =  false;
            }

            if($this->flux_user == '') {
                echo '<p><strong>Please provide a username</strong></p>';
                $check_common =  false;
            }

            if($this->flux_db == '') {
                echo '<p><strong>Please provide a database name</strong></p>';
                $check_common =  false;
            }

            if($check_common === false) {
                return null;
            }

            $this->dbconn = @mysql_connect($this->flux_host, $this->flux_user, $this->flux_pw);

            if(!$this->dbconn) {
                echo '<p><strong>Could not connect to: "'.$this->flux_host.'"</strong></p>';
                return null;
            }

            if(!@mysql_select_db($this->flux_db, $this->dbconn)) {
                echo '<p><strong>Could not select database: "'.$this->flux_db.'"</strong></p>';
                return null;
            }

            //$this->debugCharset();
            mysql_query(" SET NAMES 'utf8' ");
            //$this->debugCharset();

            // save hostname in session
            // needed to replcae pictures later
            $_SESSION['WP_FluxCmsBlog_Import_HOST'] = $this->flux_host;
            return $this->get_posts();

        }


        private function debugCharset() {

            $sql = "SHOW VARIABLES LIKE 'character_set%';";

            $result = @mysql_query($sql);

            // check for valid result
            if(!$result ) {
                echo '<p><strong>Could not debug charset</strong></p>';
                return false;
            }

            echo "<strong>DEBUG Charset</strong><br />";

            while($row = mysql_fetch_assoc($result)) {

                echo $row['Variable_name']." : ".$row['Value']."<br />";

            }

            echo "<br />";

        }

        // ----------------------------------------------------------------------------
        // blog posts functions
        // ----------------------------------------------------------------------------

        /**
         * Query flux db
         *
         * @return null | false | string
         */
        private function get_posts() {

            /*
             $sql = "SELECT
            {$this->flux_prefix}blogposts.ID                         AS `wp:post_id`,
            {$this->flux_prefix}blogposts.post_title                 AS title,
            {$this->flux_prefix}blogposts.post_author                AS `dc:creator`,
            CONCAT({$this->flux_prefix}blogposts.post_content,
                    ' ',{$this->flux_prefix}blogposts.post_content_extended) AS `_cdata_:content:encoded`,
            {$this->flux_prefix}blogposts.post_content_summary       AS `_cdata_:excerpt:encoded` ,
            {$this->flux_prefix}blogposts.post_date                  AS `wp:post_date`,
            {$this->flux_prefix}blogposts.post_comment_mode          AS `_transliterate_:wp:comment_status`,
            {$this->flux_prefix}blogposts.post_comment_mode          AS `_transliterate_:wp:ping_status`,
            {$this->flux_prefix}blogposts.post_status                AS `_transliterate_:wp:status`,
            '0'                                                      AS `wp:post_parent`,
            '0'                                                      AS `wp:menu_order`,
            'post'                                                   AS `wp:post_type`,
            'novalue'                                                AS `_replace_:category`,
            'novalue'                                                AS `_replace_:wp:comment`

            FROM {$this->flux_prefix}blogposts

            ORDER BY {$this->flux_prefix}blogposts.post_date DESC ";
            */
            $sql = "SELECT
            {$this->flux_prefix}blogposts.ID                         AS `wp:post_id`,
            {$this->flux_prefix}blogposts.post_title                 AS title,
            {$this->flux_prefix}blogposts.post_author                AS `dc:creator`,
            {$this->flux_prefix}blogposts.post_content               AS `flux:post_content`,
            {$this->flux_prefix}blogposts.post_content_extended      AS `flux:post_content_extended`,
            {$this->flux_prefix}blogposts.post_content_summary       AS `flux:post_content_summary` ,
            {$this->flux_prefix}blogposts.post_date                  AS `wp:post_date`,
            {$this->flux_prefix}blogposts.post_comment_mode          AS `_transliterate_:wp:comment_status`,
            {$this->flux_prefix}blogposts.post_comment_mode          AS `_transliterate_:wp:ping_status`,
            {$this->flux_prefix}blogposts.post_status                AS `_transliterate_:wp:status`,
            '0'                                                      AS `wp:post_parent`,
            '0'                                                      AS `wp:menu_order`,
            'post'                                                   AS `wp:post_type`,
            {$this->flux_prefix}blogposts.ID                         AS `_replace_:category`,
            {$this->flux_prefix}blogposts.ID                         AS `_replace_:wp:comment`

            FROM {$this->flux_prefix}blogposts

            WHERE {$this->flux_prefix}blogposts.blog_id = '{$this->flux_blogid}'
            

            ";
            /*
             ORDER BY {$this->flux_prefix}blogposts.post_date DESC
            LIMIT 0,30
            
            
                        AND {$this->flux_prefix}blogposts.ID  = 188
                        
            ";
            */

            $this->flux_posts_result = @mysql_query($sql);

            // check for valid result
            if(!$this->flux_posts_result ) {
                echo '<p><strong>Query failed: "'.mysql_error().'"</strong></p>';
                return false;
            }

            return $this->save_posts_asxml();

        }


        /*
         EXAMPLE of full channel header
        <title>wordpress</title>
        <link>http://wordpress.lo</link>
        <description>Eine weitere WordPress-Seite</description>
        <pubDate>Mon, 06 May 2013 10:55:00 +0000</pubDate>
        <language>de-DE</language>
        <wp:wxr_version>1.2</wp:wxr_version>
        <wp:base_site_url>http://wordpress.lo</wp:base_site_url>
        <wp:base_blog_url>http://wordpress.lo</wp:base_blog_url>
        <wp:author><wp:author_id>1</wp:author_id><wp:author_login>admin</wp:author_login><wp:author_email>wordpress@gassi.tv</wp:author_email><wp:author_display_name><![CDATA[admin]]></wp:author_display_name><wp:author_first_name><![CDATA[]]></wp:author_first_name><wp:author_last_name><![CDATA[]]></wp:author_last_name></wp:author>
        <wp:category><wp:term_id>1</wp:term_id><wp:category_nicename>allgemein</wp:category_nicename><wp:category_parent></wp:category_parent><wp:cat_name><![CDATA[Allgemein]]></wp:cat_name></wp:category>
        <generator>http://wordpress.org/?v=3.5.1</generator>
        */
        /**
         * Loops over results from flux db.
        * Creates XML String in WP format.
        * Save it to file.
        * Returns filename on success or false in case of error.
        *
        * @return false|string
        */
        private function save_posts_asxml() {



            $XML = '<rss version="2.0"
            xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
            xmlns:content="http://purl.org/rss/1.0/modules/content/"
            xmlns:wfw="http://wellformedweb.org/CommentAPI/"
            xmlns:dc="http://purl.org/dc/elements/1.1/"
            xmlns:wp="http://wordpress.org/export/1.2/">
            <channel>

            <wp:wxr_version>1.2</wp:wxr_version>
            <wp:base_site_url>http://'.$this->flux_host.'</wp:base_site_url>
            <generator>flux2wp</generator>
            '."\n\n\n";

            while($row = mysql_fetch_assoc($this->flux_posts_result)) {


                $row = $this->preprocess_content_sections($row);

                $XML .= "<item>\n";

                // mark as imported
                $XML .= "        <wp:postmeta>
                <wp:meta_key>_fluxcms_blog_import</wp:meta_key>
                <wp:meta_value><![CDATA[{$this->flux_blogid}]]></wp:meta_value>
                </wp:postmeta>
                ";


                foreach($row as $fieldname => $value) {

                    $fragment = $this->getFormattetXML($fieldname, $value, $row);

                    if($fragment === null) {
                        echo "<p><strong>Failed to get XML-fragment: $fieldname</strong></p>";
                        return false;
                    }

                    $XML .= "    $fragment\n";
                }

                $XML .= "</item>\n";
            }

            $XML .= "</channel>\n</rss>\n";


            // no import
            // just send the file
            if(isset($_POST['sendfile']) && $_POST['sendfile'] == '1' ){

                header('Content-Type: text/xml');
                header('Content-disposition: attachment; filename="__flux_export_for_wordpress.xml"');

                // clean ouput buffer
                // we just want the xml
                ob_end_clean();

                echo $XML;

                die();

            }

            // create tmp file
            $filename = tempnam(sys_get_temp_dir(), "__wp_importer");

            if(@file_put_contents($filename, $XML) !== false) {
                return $filename;
            }
            else {
                echo "<p><strong>Could not write XML-file to: $filename</strong></p>";
                return false;
            }

        }

        /**
         * wordpress blog just knows content and excerpt
         * flux blog has content, content_extended and content_summary
         *
         * @param array $row
         */
        private function preprocess_content_sections($row) {

            // we have a summary
            if( trim( strip_tags($row['flux:post_content_summary'] ) ) != '') {

                $row['_cdata_:excerpt:encoded'] = '';
                $row['_cdata_:content:encoded'] = $row['flux:post_content']."\n<!--more-->\n".$row['flux:post_content_extended'];
            }
            // extended post
            else if( trim( strip_tags($row['flux:post_content'] ) ) != ''
                    && trim( strip_tags($row['flux:post_content_extended'] ) ) != '' ) {

                $row['_cdata_:excerpt:encoded'] = '';
                $row['_cdata_:content:encoded'] = $row['flux:post_content']."\n<!--more-->\n".$row['flux:post_content_extended'];
            }
            // standard post
            else {

                $row['_cdata_:excerpt:encoded'] = '';
                $row['_cdata_:content:encoded'] = $row['flux:post_content'];
            }

            unset($row['flux:post_content']);
            unset($row['flux:post_content_summary']);
            unset($row['flux:post_content_extended']);

            return $row;

        }


        /**
         * Helper to get formattet XML-fragment for a single field.
         * Returns string on success or NULL in case of error.
         *
         * @param string $fieldname
         * @param string $value
         * @param array  $data
         *
         * @return NULL|string
         */
        private function getFormattetXML($fieldname, $value, $data) {

            $check = explode(':', $fieldname);
            $functionname =  implode('_', $check);
            $matcher = array_shift($check);
            $new_fieldname = implode(':', $check);

            switch($matcher) {

                case '_transliterate_':

                    if(!method_exists ($this, $functionname)) {

                        echo '<p><strong>Method: "'.$functionname.' does not exist."</strong></p>';
                        return null;

                    }

                    $value = call_user_func_array(array($this, $functionname), array($value, $data));
                    $xmlFragment = "<{$new_fieldname}>{$value}</{$new_fieldname}>";

                    break;

                case '_cdata_':

                    $xmlFragment = "<{$new_fieldname}><![CDATA[{$value}]]></{$new_fieldname}>";
                    break;

                case '_replace_':

                    if(!method_exists ($this, $functionname)) {
                        echo '<p><strong>Method: "'.$functionname.' does not exist."</strong></p>';
                        return null;
                    }

                    $xmlFragment = call_user_func_array(array($this, $functionname), array($value, $data));
                    break;

                default:

                    $xmlFragment = "<{$fieldname}>{$value}</{$fieldname}>";


            }

            return $xmlFragment;
        }


        // ----------------------------------------------------------------------------
        // blog comments functions
        // ----------------------------------------------------------------------------

        /**
         * Query flux db
         *
         * @return null | false | string
         */
        private function get_comments($posts_id) {

            /*
             id 	1
            127
            Georg Raffael
            g.raffael@bluemail.ch
             
            85.5.214.48
            comment_date 	2007-09-17 10:45:28
            Die Zust&#228;nde in den Weltmeeren sind dermassen...
            changed 	2007-09-17 12:45:28
             
            comment_status 	1
            comment_rejectreason 	NULL
            comment_hash 	r341b1a1197791c229a194f177cc8ef05
            comment_notification 	0
            comment_notification_hash 	f029fd7be059a7e2ee9246fda939eec4
            openid 	0
            comment_username 	blogcomments`

            // comment status -----------------------------------
            <option value="0">none</option>
            <option selected="selected" value="1">Approved</option>
            <option value="2">Moderated</option>
            <option value="3">Rejected</option>

            //

            */
            $sql = "SELECT
            {$this->flux_prefix}blogcomments.id                     AS `wp:comment_id`,
            {$this->flux_prefix}blogcomments.comment_author         AS `_cdata_:wp:comment_author`,
            {$this->flux_prefix}blogcomments.comment_author_email   AS `wp:comment_author_email`,
            {$this->flux_prefix}blogcomments.comment_author_url     AS `wp:comment_author_url`,
            {$this->flux_prefix}blogcomments.comment_author_ip      AS `wp:comment_author_IP` ,
            {$this->flux_prefix}blogcomments.comment_date           AS `wp:comment_date` ,
            {$this->flux_prefix}blogcomments.comment_content        AS `_cdata_:wp:comment_content`,
            {$this->flux_prefix}blogcomments.comment_status         AS `_transliterate_:wp:comment_approved`,
            {$this->flux_prefix}blogcomments.comment_type           AS `_transliterate_:wp:comment_type`,
            '0'                                                     AS `wp:comment_parent`,
            {$this->flux_prefix}blogcomments.comment_username       AS `_transliterate_:comment_user_id`

            FROM {$this->flux_prefix}blogcomments

            WHERE comment_posts_id = '$posts_id' ";

            $this->flux_comments_result = @mysql_query($sql);


            // check for valid result
            if(!$this->flux_comments_result ) {
                echo '<p><strong>Query failed: "'.mysql_error().'"</strong></p>';
                return false;
            }

            return $this->get_comments_asxml();

        }

        /**
         * converts comment from mysql result to
         * xml string
         *
         * @return boolean | string
         */
        private function get_comments_asxml() {

            $XML = '';

            while($row = mysql_fetch_assoc($this->flux_comments_result)) {


                $XML .= "    <wp:comment>\n";
                foreach($row as $fieldname => $value) {

                    $fragment = $this->getFormattetXML($fieldname, $value, $row);

                    if($fragment === null) {
                        echo "<p><strong>Failed to get XML-fragment: $fieldname</strong></p>";
                        return false;
                    }

                    $XML .= "        $fragment\n";
                }
                $XML .= "    </wp:comment>\n";

            }

            return $XML;

        }



        // ----------------------------------------------------------------------------
        // replace functions
        // ----------------------------------------------------------------------------
        private function _replace__category($value, $data) {

            $XML = '';


            if($this->add_category != false && $this->add_category != '') {
                $tmp = explode(':',$this->add_category);
                if(count($tmp) != 2) {
                    echo "<p><strong>Pleas enter valid nicename:category</strong></p>";
                    return null;
                }

                $XML .= '<category domain="category" nicename="'.$tmp[0].'"><![CDATA['.$tmp[1].']]></category>'."\n";
            }



            // categories --------------------------
            $post_id = (int) $value;

            $sql = "SELECT `name`,`uri`
            FROM `{$this->flux_prefix}blogcategories`
            JOIN `{$this->flux_prefix}blogposts2categories`
            ON `{$this->flux_prefix}blogcategories`.`id` = `{$this->flux_prefix}blogposts2categories`.`blogcategories_id`
            WHERE `{$this->flux_prefix}blogposts2categories`.`blogposts_id`  = $post_id";
             

            $result =  @mysql_query($sql);

            // check for valid result
            if(!$result) {
                return $XML;
            }



            while($row = mysql_fetch_assoc($result)) {

                $XML .= '<category domain="category" nicename="'.$row['uri'].'"><![CDATA['.$row['name'].']]></category>'."\n";
            }



            // tags ---------------------------------

            $sql = "SELECT DISTINCT({$this->flux_prefix}properties.value) AS Tags  FROM  {$this->flux_prefix}blogposts

            JOIN {$this->flux_prefix}properties2tags ON {$this->flux_prefix}properties2tags.path LIKE (CONCAT('%',post_uri,'.html'))
            JOIN {$this->flux_prefix}tags ON {$this->flux_prefix}tags.id  = {$this->flux_prefix}properties2tags.tag_id
            JOIN {$this->flux_prefix}properties ON {$this->flux_prefix}properties.path  = {$this->flux_prefix}properties2tags.path

            WHERE {$this->flux_prefix}blogposts.ID = $post_id
            AND {$this->flux_prefix}properties.name = 'subject'
            AND {$this->flux_prefix}properties.ns = 'http://purl.org/dc/elements/1.1/'";

            $result =  @mysql_query($sql);

            // check for valid result
            if(!$result) {
                return $XML;
            }



            while($row = mysql_fetch_assoc($result)) {
                if(isset($row['Tags'])) {

                    $tags = explode(' ',$row['Tags']);

                    foreach($tags as $tag) {
                        $nice = trim(strtolower($tag));
                        if($nice != '') {
                            $XML .= '<category domain="post_tag" nicename="'.$nice.'"><![CDATA['.$tag.']]></category>'."\n";
                        }
                    }
                }
            }


            return $XML;
        }






        private function _replace__wp_comment($value, $data) {
            if(isset($_POST['incl_comments']) && $_POST['incl_comments'] == '1' ) {
                $post_id = (int) $value;
                return $this->get_comments($post_id);
            }
            else {
                return '';
            }
        }


        // ----------------------------------------------------------------------------
        // transliteration functions
        // ----------------------------------------------------------------------------

        /* COMMENTS */

        /*
         ----- comment status in flux db
        <option value="0">none</option>
        <option selected="selected" value="1">Approved</option>
        <option value="2">Moderated</option>
        <option value="3">Rejected</option>

        ----- in wordpress we have 0 or 1
        */
        private function _transliterate__wp_comment_approved ($value, $data) {

            $value = (int) $value;

            if($value == 1){
                return '1';
            }
            return '0';

        }

        /*
         ----- only thing found in flux is 'TRACKBACK'
        ----- wordpress knows 'comment', 'trackback', 'pingback'
        */
        private function _transliterate__wp_comment_type ($value, $data) {

            $value = trim(strtolower($value));

            if($value == 'trackback') {
                return 'trackback';
            }

            return 'comment';

        }

        // only works if users are also importet with the same userid !!!!
        private function _transliterate__comment_user_id ($value, $data) {

            return '0';

            $sql = "SELECT `ID` FROM `{$this->flux_prefix}users`
            WHERE `user_login` = '$value' LIMIT 1;";

            $result = @mysql_query($sql);

            // check for valid result
            if(!$result) {
                return '0';
            }

            return @mysql_result ($res,0,0);

        }

        /* POSTS */
        /*
         ----- post_comment_mode  in flux db
        <option selected="selected" value="99">Standard: Erlaube Kommentare für einen Monat) </option>
        <option value="1">Erlaube Kommentare für einen Monat</option>
        <option value="2">Kommentare immer erlauben</option>
        <option value="3">Keine Kommentare erlauben</option>
        <option value="4">Moderated comments</option>

        ----- possible status in wordpress
        open (comments open to everyone)
        closed (comments closed to everyone)
        registered_only (comments open for registered/logged-in users)
        */
        private function _transliterate__wp_comment_status($value, $data) {
            $value = (int) $value;
            switch($value) {
                case 99:
                case 1:
                    return $this->_check_flux_post_comment_mode($value, $data);
                    die('default');
                    break;
                case 2:
                    return 'open';
                    break;
                case 3:
                    return 'closed';
                    break;
                case 0:
                case 4:
                    return 'registered_only';
                    break;
                default:
                    return 'closed';
            }
        }

        private function _transliterate__wp_ping_status($value, $data) {
            // same as comment status
            return $this->_transliterate__wp_comment_status($value, $data);

        }


        /**
         * Helper to check post_comment_mode 99
         *
         * @param string $value
         * @param array $data
         * @return string
         */
        private function _check_flux_post_comment_mode($value, $data) {
            
            $posttime = strtotime($data['wp:post_date']);
            $plusone = strtotime("+1 month", $posttime);
            $now = time();
            
            if( $plusone >  $now ) {
  
                return 'open';
            }
            
            return 'closed';
        }


        /*
         ----- post_status in flux db
        <option value="1">Öffentlich</option>
        <option value="2">Privat</option>
        <option value="4">Entwurf</option>

        ----- status in wordpress
        'new' - When there's no previous status
        'publish' - A published post or page
        'pending' - post in pending review
        'draft' - a post in draft status
        'auto-draft' - a newly created post, with no content
        'future' - a post to publish in the future
        'private' - not visible to users who are not logged in
        'inherit' - a revision or attachment. see get_children.
        'trash' - post is in trashbin. added with Version 2.9.
        */

        private function _transliterate__wp_status($value, $data) {
            $value = (int) $value;
            switch ($value) {
                case 1:
                    return 'publish';
                    break;
                case 2:
                    return 'private';
                    break;
                default:
                    return 'draft';
            }

        }

    } // end custom class

    // callback for raw post preprocessing
    //$post = apply_filters( 'wp_import_post_data_raw', $post );

} // end check for parent class


// callback function for hook
function WP_FluxCmsBlog_Importer_init() {

    $GLOBALS['WP_FluxCmsBlog_Import'] = new WP_FluxCmsBlog_Import();
    register_importer( 'fluxcmsblog', 'Flux CMS Blog', 'Import <strong>posts, comments, categories and tags</strong> from a FluxCMS Database', array( $GLOBALS['WP_FluxCmsBlog_Import'], 'dispatch' ) );

    // check for dummy or real importer
    if(method_exists ($GLOBALS['WP_FluxCmsBlog_Import'] , 'im_not_error_object')) {
        // add_filter ( 'hook_name', 'your_filter', [priority], [accepted_args] );
        add_filter('wp_import_post_data_processed', 'WP_FluxCmsBlog_Importer_post_process', 20, 2);

        // load helper class for preprocessing
        $class_fluxcms_import_preprocessor = ABS_PLUGIN . 'fluxcmsblog-importer/inc/fluxcms-blogimporter-filters.php';
        if (file_exists( $class_fluxcms_import_preprocessor ) ) {
            require_once $class_fluxcms_import_preprocessor;
        }

    }

}
// add hook
add_action( 'init', 'WP_FluxCmsBlog_Importer_init' );

$import_counter = 1;
// every post goes trough this function before it's written to db
function WP_FluxCmsBlog_Importer_post_process($postdata, $post ) {


    $blogid = false;

    //  return if its not a post
    if($postdata['post_type'] != 'post') {
        return $postdata;
    }


    if(isset($post['postmeta'])) {
        foreach($post['postmeta'] as $row) {
            if($row['key'] == '_fluxcms_blog_import') {
                $blogid = $row['value'];
            }
        }
    }

    global $import_counter;
    /*
     array (size=16)
    'import_id' => int 161989
    'post_author' => int 1
    'post_date' => string '2013-03-27 11:21:32' (length=19)
    'post_date_gmt' => string '' (length=0)
    'post_content' => string 'bla bla bla'
    'post_excerpt' => string '' (length=0)
    'post_title' => string 'Schonen Sie Ihre Nerven - verzichten Sie!' (length=41)
    'post_status' => string 'publish' (length=7)
    'post_name' => string '' (length=0)
    'comment_status' => string 'open' (length=4)
    'ping_status' => string 'open' (length=4)
    'guid' => string '' (length=0)
    'post_parent' => int 0
    'menu_order' => int 0
    'post_type' => string 'post' (length=4)
    'post_password' => string '' (length=0)
    */
    $filter = new WP_FluxCmsBlog_Importer_Process_Post();
    $postdata['post_content'] = $filter->process($postdata, $_SESSION ['WP_FluxCmsBlog_Import_HOST'], $blogid);

    if($import_counter%5 == 1) {
        echo "$import_counter .. ";
        flush();
    }
    echo str_repeat(' ',200);
    $import_counter++;
    flush();

    $import_counter++;

    return $postdata;
}


// form helper
function WP_FluxCmsBlog_Importer_connection_form( $action ) {

    ?>
<form id="import-connection-form" method="post"  enctype="multipart/form-data" class="wp-form"
	action="<?php echo esc_attr(wp_nonce_url($action, 'database-connection')); ?>">

	<table>

		<tr>
			<td><label for="hostname">Host</label>
			</td>
			<td><input type="text" id="hostname" name="hostname" size="25"
				value="<?php 
			if(isset($_POST['hostname'])) { echo $_POST['hostname']; } else { echo 'localhost'; } 
			?>" />
			</td>
		</tr>

		<tr>
			<td><label for="db_username">User</label>
			</td>
			<td><input type="text" id="db_username" name="db_username" size="25"
				value="<?php 
			if(isset($_POST['db_username'])) { echo $_POST['db_username']; } else { echo 'root'; } 
			?>" />
			</td>
		</tr>

		<tr>
			<td><label for="db_password">Password</label>
			</td>
			<td><input type="password" id="db_password" name="db_password"
				value="<?php 
			if(isset($_POST['db_password'])) { echo $_POST['db_password']; } else { echo ''; } 
			?>"
				size="25" />
			</td>
		</tr>

		<tr>
			<td><label for="database">Database</label>
			</td>
			<td><input type="text" id="database_name" name="database_name"
				value="<?php 
			if(isset($_POST['database_name'])) { echo $_POST['database_name']; } else { echo ''; } 
			?>"
				size="25" />
			</td>
		</tr>

		<tr>
			<td><label for="prefix">Table prefix</label>
			</td>
			<td><input type="text" id="prefix" name="prefix" size="25"
				value="<?php 
			if(isset($_POST['prefix'])) { echo $_POST['prefix']; } else { echo 'fluxcms_'; } 
			?>" />
			</td>
		</tr>

		<tr>
			<td><label for="blogid">Blog ID</label>
			</td>
			<td><input type="text" id="blogid" name="blogid" size="3"
				value="<?php 
			if(isset($_POST['blogid'])) { echo $_POST['blogid']; } else { echo '1'; } 
			?>" />
			</td>
		</tr>

		<tr>
			<td colspan="2">
				<h3>Options</h3>
			</td>
		</tr>

		<tr>
			<td><label for="add_category">Add categories to all posts</label>
			</td>
			<td><input type="text" id="add_category" name="add_category"
				size="25"
				value="<?php 
			if(isset($_POST['add_category'])) { echo $_POST['add_category']; } else { echo ''; } 
			?>" />
			</td>
		</tr>

		<tr>
			<td><label for="incl_comments">Import Comments</label>
			</td>
			<td><input type="checkbox" id="incl_comments" name="incl_comments"
				value="1" <?php 
				if(isset($_POST['incl_comments']) && $_POST['incl_comments'] == '1' ) { echo ' checked="checked" '; } else { echo ''; }
				?>" />
			</td>
		</tr>

		<tr>
			<td><label for="clean_db">Delete previous import</label>
			</td>
			<td><input type="checkbox" id="clean_db" name="clean_db" value="1" <?php 
			if(isset($_POST['clean_db']) && $_POST['clean_db'] == '1' ) { echo ' checked="checked" '; } else { echo ''; }
			?>" />
			</td>
		</tr>

		<tr>
			<td><label for="sendfile">Send File</label>
			</td>
			<td><input type="checkbox" id="sendfile" name="sendfile" value="1" <?php 
			if(isset($_POST['sendfile']) && $_POST['sendfile'] == '1' ) { echo ' checked="checked" '; } else { echo ''; }
			?>" />
			</td>
		</tr>

		<tr>
			<td>
			</td>
			<td><?php submit_button('Start import'); ?>
			</td>
		</tr>
		
		
		<tr>
			<td colspan="2">
			    <b>Save/restore settings</b>
			</td>
		</tr>
		
		
		<tr>
			<td colspan="2">
			    <input type="file" id="load_settings" name="load_settings" />
			</td>
		</tr>
		
		<tr>
			<td>
			<?php submit_button('Save settings', 'additional', 'save_settings'); ?>
			</td>
			<td>
			<?php submit_button('Load settings', 'additional', 'load_settings'); ?>
			</td>
		</tr>

	</table>

</form>
<?php

}



