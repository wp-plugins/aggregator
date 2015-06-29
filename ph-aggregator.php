<?php
/**
 * Plugin Name: Aggregator
 * Description: Aggregates js files.
 * Version: 1.0.2
 * Author: PALASTHOTEL by Edward Bock
 * Author URI: http://www.palasthotel.de
 */

/**
 * get and set options
 */
function ph_aggregator_options( $options  = null ) {
	if( $options == null ) {
		$default = array(
			'modified' => 0,
			'rewrite' => false,
			'js' => array(),
		);
		return get_option('ph-aggregator-js', $default);
	} else {
		return update_option('ph-aggregator-js', $options);
	}
}

/**
 * add action for aggregation
 */
add_action('wp_print_scripts', 'ph_aggregator_js',9999);
function ph_aggregator_js() {
	/**
	 * no compression when is admin areas are displayed
	 */
	if(is_admin()) return;

	/**
	 * get options
	 */
	$options = ph_aggregator_options();

	/**
	 * update options
	 */
	$js_contents = ph_aggregator_script($options);

	/**
	 * write js files
	 */
	if( $options['rewrite'] ){
		ph_aggregator_rewrite($js_contents);
	}

	/**
	 * enqueues new scripts
	 */
	ph_aggregator_enqueue($options);

	/**
	 * dequeue aggregated scripts
	 */
	ph_aggregator_dequeue($options);

	/**
	 * set rewrite false and save options if was rewritten
	 */
	if($options["rewrite"]){
		$options["rewrite"] = false;
		ph_aggregator_options($options);
	}
}

function ph_aggregator_paths($place = null){
	/**
	 * separate files for logged in users and logged out users
	 */
	$logged_in = '';
	if(is_user_logged_in()){
		$logged_in = 'logged-in-';
	}
	$paths = (object) array(
		'dir' => rtrim(plugin_dir_path( __FILE__ ), "/")."/aggregated",
		'url' => rtrim(plugins_url( '', __FILE__ ), "/")."/aggregated",
		'file_pattern' => $logged_in.'%place%.js',
		'file' => '',
	);
	if($place != null){
		$paths->file = str_replace("%place%", $place, $paths->file_pattern);
	}
	return $paths;
}

function ph_aggregator_script(&$options){
	global $wp_scripts;
	$scripts = $options['js'];
	$js_contents = array();
	$js_files = array();
	$ignores = ph_aggregator_get_ignores();

	if ( !is_a($wp_scripts, "WP_Scripts") ) return;
	if (is_array($wp_scripts->queue)) {
		/**
		 * needs rebuild if
		 * - there is an aggregated script that is not queued anymore
		 * - if there is an aggregated script that is ignored
		 */
		$wp_scripts->all_deps($wp_scripts->queue);
		foreach ($scripts as $handle => $value) {
			if ( !in_array( $handle, $wp_scripts->to_do) 
				|| in_array($handle, $ignores)) {
				$js_place=$value['place'];
				unset($scripts[$handle]);
				$options["rewrite"] = true;
			}
		}
		/**
		 * needs rebuild if
		 * - script is not ignored by filter
		 * - script was not aggregated before
		 * - script has newer file time
		 */
		foreach ($wp_scripts->to_do as $js) {
			if(in_array($js, $ignores)) continue;
			$js_src=$wp_scripts->registered[$js]->src;
			$js_place=$wp_scripts->registered[$js]->extra;
			if (is_array($js_place) && isset($js_place["group"]) && $js_place['group']==1) {
				$js_place='footer';
			}
			else {
				$js_place='header';
			}
			if (
				(!(strpos($js_src,get_bloginfo('url'))===false)
					|| substr($js_src,0,1)==="/"
					|| substr($js_src,0,1)===".")

				&& (substr($js_src,strrpos($js_src,"."),3)==".js") ) {
				/**
				 * is a locally loaded js file
				 */
				if (strpos($js_src,get_bloginfo('url'))===false) {
					$js_relative_url=substr($js_src,1);
				}
				else {
					$js_relative_url=substr($js_src,strlen(get_bloginfo('url'))+1);
				}
				if (strpos($js_relative_url,"?")){
					$js_relative_url=substr($js_relative_url,0,strpos($js_relative_url,"?"));
				}
				/**
				 * does aggregated file exists?
				 */
				$paths = ph_aggregator_paths($js_place);
				if(!is_file(rtrim($paths->dir,"/")."/".$paths->file)){
					$options["rewrite"] = true;
				}
				/**
				 * have a look at modified time
				 */
				$js_time=null;
				if(file_exists($js_relative_url)){
					$js_time=filemtime($js_relative_url);
				}
				if ($js_time != null) {
					if(!isset($scripts[$js]) || !is_array($scripts[$js]) ){
						$scripts[$js] = array();
					}
					if ( !isset($scripts[$js]['modified'])
						|| !isset($scripts[$js]['place'])
						|| $js_time != $scripts[$js]['modified']
						|| $js_place != $scripts[$js]['place']
					) {
						$options['rewrite'] = true;
						$scripts[$js]['modified'] = $js_time;
						$scripts[$js]['place'] = $js_place;
						$options["rewrite"] = true;
						if($options["modified"] < $js_time){
							$options["modified"] = $js_time;
						}
					}
					if( !isset($js_files[$js_place]) || !is_array($js_files[$js_place]) ){
						$js_files[$js_place] = array();
					}
					$js_files[$js_place][] = $js_relative_url;
				}
			}
		}
	}
	$options['js'] = $scripts;
	/**
	 * add places to options
	 */
	$options["places"] = array();
	foreach ($js_files as $place => $js__files) {
		$options['places'][] = $place;
		if($options["rewrite"]){
			/**
			 * get contents if needed
			 */
			$js_contents[$place] = "";
			foreach ($js__files as $index => $file) {
				$js_contents[$place].= ph_aggregator_get_content($file);
			}
		}
	}
	return $js_contents;
}



function ph_aggregator_enqueue(&$options){
	foreach ($options['places'] as $place) {
		$paths = ph_aggregator_paths($place);
		$footer = false;
		if($place == "footer"){
			$footer = true;
		}
		$path = rtrim($paths->url,"/")."/".$paths->file;
		wp_enqueue_script('ph-aggregated-'.$place, $path, array(), null, $footer );
	}
}

function ph_aggregator_dequeue(&$options){
	foreach ($options['js'] as $handle => $script) {
		wp_dequeue_script($handle);
	}
	global $wp_scripts;
	if(!is_array($wp_scripts->queue)){
		return;
	}
	$wp_scripts->all_deps($wp_scripts->queue);
	foreach ($wp_scripts->to_do as $handle) {
		if(isset($options['js'][$handle])){
			$wp_scripts->remove($handle);
			unset($wp_scripts->registered[$handle]);
			array_shift($wp_scripts->to_do);
		}
	}
}
function ph_aggregator_get_content($js_relative_url){
	$js_content="";
	if(!is_writable(dirname(__FILE__))) return "";
	$source_file=fopen($js_relative_url,'r');
	if($source_file){
		$js_content.= "/**\n * Aggregated\n * ". $js_relative_url." content:\n */\n";
		$js_content.= fread($source_file,filesize($js_relative_url))."\n";
		fclose($source_file);
		/**
		 * remove source maps
		 */
		$js_content = str_replace( "sourceMappingURL", "", $js_content);
	}
	return $js_content;
}
/**
 * rewrites the aggregated scripts
 */
function ph_aggregator_rewrite($js_contents){
	if(!is_writable(dirname(__FILE__))) return;
	foreach ($js_contents as $place => $content) {
		$paths = ph_aggregator_paths($place);
		$the_file = rtrim($paths->dir,"/")."/".$paths->file;
		$aggregated_file=fopen($the_file, 'w');
		fwrite($aggregated_file, $content);
		fclose($aggregated_file);
		
		// chmod($the_file, 0666);
		
		ph_aggregator_purge($paths->url."/".$paths->file);
	}
}

function ph_aggregator_purge($file_url){
	wp_remote_request( $file_url, array('method' => 'PURGE') );
}

function ph_aggregator_get_ignores(){
	return apply_filters("ph_aggregator_ignore", array());
}

