<?php

class WP_FluxCmsBlog_Importer_Process_Post {


    private $all_images      = false;
    private $html            = false;
    private $host_name       = false;
    private $post_id         = false;
    private $post            = false;
    private $blogid          = false;


    /**
     *
     * @param unknown_type $postdata
     * @param unknown_type $host_name
     * @param unknown_type $blogid
     * @return boolean
     */
    public function process($postdata, $host_name, $blogid) {


        $this->html          = $postdata['post_content'];
        $this->post_id       = $postdata['import_id'];
        $this->post          = $postdata;
        $this->blogid        = $blogid;

        if( substr_count($host_name, 'http') == 0 ) {
            $host_name = str_replace('/', '', $host_name);
            $host_name = "http://$host_name/";
        }

        $this->host_name = $host_name;

        // images processing
        $this->getAllImages();
        $this->getAttributes();
        $this->fetchImages();

        // cleanup
        $this->removeBXcommentsSection();

        return $this->html;

    }

    
    /**
     *
     */
    private function removeBXcommentsSection() {

        $find_queries = array(
                
                '//a[@name="post_content_extended"]',
                '//div[@class="post_links"]',
                '//div[@class="post_content_extended"]',
                '//span[@span="post_comments_count"]',
                '//a[@name="comments"]',
                '//div[@name="comments_not"]',
                '//span[@name="post_uri"]',
                '//div[@class="post_tags"]',
                '//span[@class="post_more"]',
                '//div[@class="post_links"]',
                '//div[@class="post_meta_data"]',
                '//script[@type="text/javascript"]',
                '//a[@name="fb_share"]',
                '//a[@class="twitter-share-button"]',
                

        );


        if(class_exists('DOMDocument', false)) {
            $dom = new DOMDocument();
            $dom->loadHTML("<div id=\"removeBXcommentsSection\"> $this->html </div>");
        }
        else {
            return;
        }


        if(class_exists('DOMXPath', false)) {
            $xpath = new DOMXPath($dom);
        }
        else {
            //echo "<p><strong>DOMXPath not found</strong></p>";
            return;
        }


        $masterNode = $dom->getElementById('removeBXcommentsSection');

        foreach($find_queries as $query) {

            $entries = $xpath->query($query);

            if($entries) {
                foreach ($entries as $entry) {
                    //echo "Found {$entry->nodeName}: {$entry->nodeValue}, " ;
    
                    try {
                        $entry->parentNode->removeChild($entry);
                    }
                    catch (Exception $e) {
                        //echo "<span style=\"color: red;\">Not found {$entry->nodeName}</span>";
                    }
                }
            }

        }

        $this->html = '';

        foreach($masterNode->childNodes as $node) {
            $this->html .= $dom->saveXML($node);
        }
        
        $this->html = utf8_decode($this->html);

    }

    
    /**
     * find all images tags in a string
     */
    private function getAllImages() {

        preg_match_all('/<img[^>]+>/i', $this->html, $result);
        if(count($result[0]) > 0) {

            $this->all_images = $result[0];
        }
    }


    /**
     * parse attributes from an image tag
     */
    private function getAttributes() {

        $images_fullinfo = array();

        if(is_array($this->all_images)) {

            $images = array();
            foreach( $this->all_images as $img_tag) {

                $search_tag = str_replace("'",'"',$img_tag);
                preg_match_all('/(alt|title|src)=("[^"]*")/i',$search_tag, $images[$img_tag]);
            }

            foreach($images as $fulltag => $meta) {

                $tmp = array();
                $tmp['fulltag'] = $fulltag;

                if(isset($meta[1]) && isset ($meta[2]) ) {
                     
                    foreach($meta[1] as $attr_key => $attr_name) {

                        if(isset( $meta[2][$attr_key]) ) {

                            $meta_value = trim($meta[2][$attr_key]);
                            $meta_value = str_replace('"', '', $meta_value);
                            if($meta_value != '') {
                                $tmp[$attr_name] = $meta_value;
                            }
                        }
                    }
                }

                // src attribute is required
                if(isset($tmp['src'])) {
                    $images_fullinfo[] = $tmp;
                }

            }

            $this->all_images = $images_fullinfo;
        }
    }

    
    /**
     *
     */
    private function fetchImages() {

        if(is_array($this->all_images) && count($this->all_images) > 0) {


            foreach( $this->all_images as $image) {

                if( isset( $image['src'] ) && isset( $image['fulltag'] ) ) {


                    $upload = $this->getImageFromRemote( $image['src'] );
                    if ( is_wp_error( $upload ) ) {
                        //return $upload;
                        // TO DO
                        // error handling

                    }
                    else {


                        $strip_url = get_bloginfo( 'wpurl', 'raw' ) ;

                        $wp_filetype = wp_check_filetype(basename($upload['file']), null );

                        $upload['url'] = str_replace($strip_url, '', $upload['url']);

                        $image_alt_title = preg_replace('/\.[^.]+$/', '', basename($upload['url']));

                        $wp_upload_dir = wp_upload_dir();
                        // prepare post
                        $attachment_post = array(
                                'guid'           => '',
                                'post_mime_type' => $wp_filetype['type'],
                                'post_title'     => '',
                                'post_content'   => '',
                                'post_status'    => 'inherit',
                                'context'        => 'fluxcms_blog_import_'.$this->blogid
                        );


                        // add to media database
                        $attach_id = wp_insert_attachment( $attachment_post, $upload['file'], $this->post_id  );
                        $metadata = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
                        wp_update_attachment_metadata( $attach_id, $metadata);


                        // finaly replcae image tag in content
                        $new_image_tag = '<img src="'.$upload['url'] .'"';

                        if(isset($image['alt'])) {
                            $new_image_tag .= ' alt="'.$image['alt'].'"';
                        }
                        else {
                            $new_image_tag .= ' alt="'.$image_alt_title.'"';
                        }

                        if(isset($image['title'])) {
                            $new_image_tag .= ' title="'.$image['title'].'"';
                        }
                        else {
                            $new_image_tag .= ' title="'.$image_alt_title.'"';
                        }

                        $new_image_tag .= '/>';
                        $this->html = str_replace($image['fulltag'], $new_image_tag, $this->html);
                    }

                }
            }
        }
    }


    /**
     *
     * @param unknown_type $url
     */
    private function getImageFromRemote( $url ) {


        // check for http in url
        if( substr_count($url, 'http') == 0 ) {
            $url = $this->host_name.$url;
        }

        // extract the file name and extension from the url
        $file_name = basename( $url );

        // get placeholder file in the upload dir with a unique, sanitized filename
        // $upload = wp_upload_bits( $file_name, 0, '', $post['upload_date'] );
        $upload = wp_upload_bits( $file_name, 0, '' );
        if ( $upload['error'] )
            return new WP_Error( 'upload_dir_error', $upload['error'] );

        // fetch the remote url and write it to the placeholder file
        $headers = wp_get_http( $url, $upload['file'] );

        // request failed
        if ( ! $headers ) {
            @unlink( $upload['file'] );
            return new WP_Error( 'import_file_error', __('Remote server did not respond', 'wordpress-importer') );
        }

        // make sure the fetch was successful
        if ( $headers['response'] != '200' ) {
            @unlink( $upload['file'] );
            return new WP_Error( 'import_file_error', sprintf( __('Remote server returned error response %1$d %2$s', 'wordpress-importer'), esc_html($headers['response']), get_status_header_desc($headers['response']) ) );
        }

        $filesize = filesize( $upload['file'] );

        if ( isset( $headers['content-length'] ) && $filesize != $headers['content-length'] ) {
            @unlink( $upload['file'] );
            return new WP_Error( 'import_file_error', __('Remote file is incorrect size', 'wordpress-importer') );
        }

        if ( 0 == $filesize ) {
            @unlink( $upload['file'] );
            return new WP_Error( 'import_file_error', __('Zero size file downloaded', 'wordpress-importer') );
        }

        return $upload;
    }

}