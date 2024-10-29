<?php
/*
Plugin Name: Auto Thumbnailer
Plugin URI:
Description: Automatically create thumbnails for posts when images are uploaded and the post is saved. Includes support for Oembed.
Author: Modern Tribe, Inc.
Author URI: http://tri.be
Version: 1.0
*/

if(!class_exists('Auto_Thumbnailer')) {
	class Auto_Thumbnailer {

		public function __construct() {
			add_theme_support( 'post-thumbnails' );
			$this->addActions();
		}

		private function addActions() {
			add_action('admin_notices', array($this, 'possiblyShowPostThumbnailWarning'));
			add_action('save_post', array($this, 'possiblyAddPostThumbnail'), 10, 2);
			add_filter('oembed_dataparse', array($this, 'oembedThumb'), 10, 2);
			add_filter('manage_posts_columns', array($this,'columns'), 100, 1);
			add_action('manage_posts_custom_column', array($this,'custom_column'), 10, 2);
			add_filter('manage_pages_columns', array($this,'columns'), 100, 1);
			add_action('manage_pages_custom_column', array($this,'custom_column'), 10, 2);
		}

		/// CALLBACKS

		public function possiblyAddPostThumbnail($postId, $post=false) {
			if (!$post) {
				$post = get_post($postId);
			}
			if(false === (wp_is_post_autosave($postId) || wp_is_post_revision($postId)) && post_type_supports($post->post_type, 'thumbnail')) {
				$thumb = get_post_thumbnail_id($postId);
				if(empty($thumb)) {
					// get images as attachements
					$images = get_posts(array('post_type'=>'attachment','post_mime_type'=>'image','post_parent'=>$postId,'order'=>'ASC','orderby'=>'post_date', 'numberposts'=>1));
					if (!is_array($images) || count($images)==0) {
						// if there are no attachments, search post content
						preg_match_all( '|<img.*?src=[\'"](.*?)[\'"].*?>|i', $post->post_content, $matches ); // search for uploaded images in the post
						if ( isset($matches) && isset($matches[1][0]) && strlen(trim($matches[1][0])) > 0 ) {
							$image = $matches[1][0];
							$this->uploadThumb( $post, $image );
						}
					} else {
						$image = array_shift($images);
						update_post_meta($postId, '_thumbnail_id', $image->ID);
						return true;
					}
				}
			}
		}

		public function possiblyShowPostThumbnailWarning() {
			global $pagenow, $post;
			if($pagenow == 'post.php' && $post->post_status != 'auto-draft' && post_type_supports($post->post_type, 'thumbnail')) {
				$thumb = get_post_thumbnail_id($post->ID);
				if(empty($thumb)) {
					include('views/warning.php');
				}
			}
		}

		public function uploadThumb( $post, $image ) {
			if ( ! ( ( $uploads = wp_upload_dir( current_time('mysql') ) ) && false === $uploads['error'] ) )
				return false; // upload dir is not accessible

			$content = '';
			$image = preg_replace('/\?.*/', '', $image);
			$name_parts = pathinfo($image);
			$filename = wp_unique_filename( $uploads['path'], $name_parts['basename'] );
			$unique_name_parts = pathinfo($filename);
			$newfile = $uploads['path'] . "/$filename";

			// try to upload

			if ( ini_get( 'allow_url_fopen' ) ) { // check php setting for remote file access
				$content = @file_get_contents( $image );
			}
			elseif ( function_exists( 'curl_init' ) ) { // curl library enabled
				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_URL, $image );
				curl_setopt( $ch, CURLOPT_HEADER, 0 );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
				curl_setopt( $ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10_5_8; en-us) AppleWebKit/531.9 (KHTML, like Gecko) Version/4.0.3 Safari/531.9' );
				$content = curl_exec( $ch );
				curl_close( $ch );
			}
			else { // custom connect
				$parsed_url = parse_url( $image );
				$host = $parsed_url['host'];
				$path = ( isset( $parsed_url['path'] ) ) ? $parsed_url['path'] : '/';
				$port = ( isset( $parsed_url['port'] ) ) ? $parsed_url['port'] : '80';
				$timeout = 10;
				if ( isset( $parsed_url['query'] ) )
					$path .= '?' . $parsed_url['query'];
				$fp = @fsockopen( $host, '80', $errno, $errstr, $timeout );

				if( !$fp )
					return false; // give up on connecting to remote host

				fputs( $fp, "GET $path HTTP/1.0\r\n" .
					   "Host: $host\r\n" .
					   "User-Agent: Mozilla/5.0 (Macintosh; U; Intel Mac OS X 10_5_8; en-us) AppleWebKit/531.9 (KHTML, like Gecko) Version/4.0.3 Safari/531.9\r\n" .
					   "Accept: */*\r\n" .
					   "Accept-Language: en-us,en;q=0.5\r\n" .
					   "Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7\r\n" .
					   "Keep-Alive: 300\r\n" .
					   "Connection: keep-alive\r\n" .
					   "Referer: http://$host\r\n\r\n");
				stream_set_timeout( $fp, $timeout );
				// retrieve the response from the remote server
				while ( $line = fread( $fp, 4096 ) ) {
					$content .= $line;
				}
				fclose( $fp );
				$pos     = strpos( $content, "\r\n\r\n" );
				$content = substr( $content, $pos + 4 );
			}

			if ( empty( $content ) ) // nothing was found
				return false;

			file_put_contents( $newfile, $content ); // save image

			if (! file_exists( $newfile ) ) // upload was not successful
				return false;

			// Set correct file permissions
			$stat = stat( dirname( $newfile ) );
			$perms = $stat['mode'] & 0000666;
			@chmod( $newfile, $perms );
			// get file type
			$wp_filetype = wp_check_filetype( $newfile );
			extract($wp_filetype);

			// No file type! No point to proceed further
			if ( ( !$type || !$ext ) && !current_user_can( 'unfiltered_upload' ) )
				return false;
			$title = $unique_name_parts['filename'];
			$content = '';

			// use image exif/iptc data for title and caption defaults if possible
			if ( $image_meta = @wp_read_image_metadata($newfile) ) {
				if ( trim($image_meta['title']) )
					$title = $image_meta['title'];
				if ( trim($image_meta['caption']) )
					$content = $image_meta['caption'];
			}

			// Compute the URL
			$url = $uploads['url'] . "/$filename";

			// Construct the attachment array
			$attachment = array(
								'post_mime_type' => $type,
								'guid' => $url,
								'post_parent' => $post->ID,
								'post_title' => $title,
								'post_content' => $content,
								);
			$thumb_id = wp_insert_attachment( $attachment, $newfile, $post->ID );
			if ( !is_wp_error($thumb_id) ) {
				wp_update_attachment_metadata( $thumb_id, wp_generate_attachment_metadata( $thumb_id, $newfile ) );
				update_post_meta( $post->ID, '_thumbnail_id', $thumb_id );
				return true;
			}
		}

		// generate a featured image from the featured video thumbnail
		public function oembedThumb( $return, $data, $url=null ) {
			global $post;

			if (!isset($post)) {
				// in case oembed is out of order with $post being set.
				global $wpdb;
				$post = $wpdb->last_result[0];
			}

			$thumbnail = $data->thumbnail_url;
			if( $thumbnail && post_type_supports($post->post_type, 'thumbnail') ) {
				$thumb = get_post_thumbnail_id($post->ID);

				if( empty( $thumb ) ) {
					$response = wp_remote_get( $thumbnail );

					if( !is_wp_error( $response ) ) {

						$filename = strtolower(pathinfo($thumbnail, PATHINFO_BASENAME  ) );
						$upload   = wp_upload_bits( $filename, null, $response['body'] );

						if( !$upload['error'] ) {
							$filename = $upload['file'];
							$wp_filetype = wp_check_filetype(basename($filename), null );

							$attachment = array(
								'post_mime_type' => $wp_filetype['type'],
								'post_title' => preg_replace('/\.[^.]+$/', '', basename($filename)),
								'post_content' => '',
								'post_status' => 'inherit'
								);

								$attach_id = wp_insert_attachment( $attachment, $filename, $post->ID );

								require_once(ABSPATH . "wp-admin" . '/includes/image.php');

								$attach_data = wp_generate_attachment_metadata( $attach_id, $filename );
								wp_update_attachment_metadata( $attach_id,  $attach_data );

								update_post_meta($post->ID, '_thumbnail_id', $attach_id);
						}
					}
				}
			}

			return $return;
		}

		public function columns($posts_columns){
			if (!$this->supports_thumbs()) { return $posts_columns; }
			$columns = array();
			foreach ($posts_columns as $column => $name){
				if ($column == 'title'){
					$columns['Thumbnail'] = __('Thumbnail');
					$columns[$column] = $name;
				} else $columns[$column] = $name;
			}
			return $columns;
		}

		public function custom_column($column_name, $id){
			if (!$this->supports_thumbs()) { return; }
			if($column_name == 'Thumbnail') {
				if ((function_exists('has_post_thumbnail')) && (has_post_thumbnail())){
					the_post_thumbnail(array(80,60));
				}
			}
		}

		private function supports_thumbs() {
			$post_type = get_post_type();
			return post_type_supports($post_type,'thumbnail');
		}

	}

	global $Auto_Thumbnailer;
	$Auto_Thumbnailer = new Auto_Thumbnailer;
}