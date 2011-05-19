<?php
/*
Plugin Name: Geolocation
Plugin URI: http://wordpress.org/extend/plugins/geolocation/
Description: Displays post geotag information on an embedded map.
Version: 0.1.1
Author: Chris Boyd
Author URI: http://geo.chrisboyd.net
License: GPL2
*/

/*  Copyright 2010 Chris Boyd (email : chris@chrisboyd.net)

    This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License, version 2, as 
    published by the Free Software Foundation.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

add_action('wp_head', 'add_geo_support');
add_action('wp_footer', 'add_geo_div');
add_action('admin_menu', 'add_settings');
add_filter('the_content', 'display_location', 5);
admin_init();
register_activation_hook(__FILE__, 'activate');
wp_enqueue_script("jquery");

define('PROVIDER', 'google');
define('SHORTCODE', '[geolocation]');

function activate() {
	register_settings();
	add_option('geolocation_map_width', '350');
	add_option('geolocation_map_height', '150');
	add_option('geolocation_default_zoom', '16');
	add_option('geolocation_map_position', 'after');
	add_option('geolocation_wp_pin', '1');
}

function geolocation_add_custom_box() {
		if(function_exists('add_meta_box')) {
			add_meta_box('geolocation_sectionid', __( 'Geolocation', 'myplugin_textdomain' ), 'geolocation_inner_custom_box', 'post', 'advanced' );
		} 
		else {
			add_action('dbx_post_advanced', 'geolocation_old_custom_box' );
		}
}

function geolocation_inner_custom_box() {
	echo '<input type="hidden" id="geolocation_nonce" name="geolocation_nonce" value="' . 
	wp_create_nonce(plugin_basename(__FILE__) ) . '" />';
	echo '
		<label class="screen-reader-text" for="geolocation-address">Geolocation</label>
		<div class="taghint">Enter your address</div>
		<input type="text" id="geolocation-address" name="geolocation-address" class="newtag form-input-tip" size="25" autocomplete="off" value="" />
		<input id="geolocation-load" type="button" class="button geolocationadd" value="Load" tabindex="3" />
		<input type="hidden" id="geolocation-latitude" name="geolocation-latitude" />
		<input type="hidden" id="geolocation-longitude" name="geolocation-longitude" />
		<div id="geolocation-map" style="border:solid 1px #c6c6c6;width:265px;height:200px;margin-top:5px;"></div>
		<div style="margin:5px 0 0 0;">
			<input id="geolocation-public" name="geolocation-public" type="checkbox" value="1" />
			<label for="geolocation-public">Public</label>
			<div style="float:right">
				<input id="geolocation-enabled" name="geolocation-on" type="radio" value="1" />
				<label for="geolocation-enabled">On</label>
				<input id="geolocation-disabled" name="geolocation-on" type="radio" value="0" />
				<label for="geolocation-disabled">Off</label>
			</div>
		</div>
	';
}

/* Prints the edit form for pre-WordPress 2.5 post/page */
function geolocation_old_custom_box() {
  echo '<div class="dbx-b-ox-wrapper">' . "\n";
  echo '<fieldset id="geolocation_fieldsetid" class="dbx-box">' . "\n";
  echo '<div class="dbx-h-andle-wrapper"><h3 class="dbx-handle">' . 
        __( 'Geolocation', 'geolocation_textdomain' ) . "</h3></div>";   
   
  echo '<div class="dbx-c-ontent-wrapper"><div class="dbx-content">';

  geolocation_inner_custom_box();

  echo "</div></div></fieldset></div>\n";
}

function geolocation_save_postdata($post_id) {
  // Check authorization, permissions, autosave, etc
  if (!wp_verify_nonce($_POST['geolocation_nonce'], plugin_basename(__FILE__)))
    return $post_id;
  
  if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
    return $post_id;
  
  if('page' == $_POST['post_type'] ) {
    if(!current_user_can('edit_page', $post_id))
		return $post_id;
  } else {
    if(!current_user_can('edit_post', $post_id)) 
		return $post_id;
  }

  $latitude = clean_coordinate($_POST['geolocation-latitude']);
  $longitude = clean_coordinate($_POST['geolocation-longitude']);
  $address = reverse_geocode($latitude, $longitude);
  $public = $_POST['geolocation-public'];
  $on = $_POST['geolocation-on'];
  
  if((clean_coordinate($latitude) != '') && (clean_coordinate($longitude)) != '') {
  	update_post_meta($post_id, 'geo_latitude', $latitude);
  	update_post_meta($post_id, 'geo_longitude', $longitude);
  	
  	if(esc_html($address) != '')
  		update_post_meta($post_id, 'geo_address', $address);
  		
  	if($on) {
  		update_post_meta($post_id, 'geo_enabled', 1);
  		
	  	if($public)
	  		update_post_meta($post_id, 'geo_public', 1);
	  	else
	  		update_post_meta($post_id, 'geo_public', 0);
  	}
  	else {
  		update_post_meta($post_id, 'geo_enabled', 0);
  		update_post_meta($post_id, 'geo_public', 1);
  	}
  }
  
  return $post_id;
}

function admin_init() {
	add_action('admin_head-post-new.php', 'admin_head');
	add_action('admin_head-post.php', 'admin_head');
	add_action('admin_menu', 'geolocation_add_custom_box');
	add_action('save_post', 'geolocation_save_postdata');
}

function admin_head() {
	global $post;
	$post_id = $post->ID;
	$post_type = $post->post_type;
	$zoom = (int) get_option('geolocation_default_zoom');
	?>
		<script type="text/javascript" src="http://www.google.com/jsapi"></script>
		<script type="text/javascript" src="http://maps.google.com/maps/api/js?sensor=true"></script>
		<script type="text/javascript">
		 	var $j = jQuery.noConflict();
			$j(function() {
				$j(document).ready(function() {
				    var hasLocation = false;
					var center = new google.maps.LatLng(0.0,0.0);
					var postLatitude =  '<?php echo esc_js(get_post_meta($post_id, 'geo_latitude', true)); ?>';
					var postLongitude =  '<?php echo esc_js(get_post_meta($post_id, 'geo_longitude', true)); ?>';
					var public = '<?php echo get_post_meta($post_id, 'geo_public', true); ?>';
					var on = '<?php echo get_post_meta($post_id, 'geo_enabled', true); ?>';
					
					if(public == '0')
						$j("#geolocation-public").attr('checked', false);
					else
						$j("#geolocation-public").attr('checked', true);
					
					if(on == '0')
						disableGeo();
					else
						enableGeo();
					
					if((postLatitude != '') && (postLongitude != '')) {
						center = new google.maps.LatLng(postLatitude, postLongitude);
						hasLocation = true;
						$j("#geolocation-latitude").val(center.lat());
						$j("#geolocation-longitude").val(center.lng());
						reverseGeocode(center);
					}
						
				 	var myOptions = {
				      'zoom': <?php echo $zoom; ?>,
				      'center': center,
				      'mapTypeId': google.maps.MapTypeId.ROADMAP
				    };
				    var image = '<?php echo esc_js(esc_url(plugins_url('img/wp_pin.png', __FILE__ ))); ?>';
				    var shadow = new google.maps.MarkerImage('<?php echo esc_js(esc_url(plugins_url('img/wp_pin_shadow.png', __FILE__ ))); ?>',
						new google.maps.Size(39, 23),
						new google.maps.Point(0, 0),
						new google.maps.Point(12, 25));
						
				    var map = new google.maps.Map(document.getElementById('geolocation-map'), myOptions);	
					var marker = new google.maps.Marker({
						position: center, 
						map: map, 
						title:'Post Location'<?php if(get_option('geolocation_wp_pin')) { ?>,
						icon: image,
						shadow: shadow
					<?php } ?>
					});
					
					if((!hasLocation) && (google.loader.ClientLocation)) {
				      center = new google.maps.LatLng(google.loader.ClientLocation.latitude, google.loader.ClientLocation.longitude);
				      reverseGeocode(center);
				    }
				    else if(!hasLocation) {
				    	map.setZoom(1);
				    }
					
					google.maps.event.addListener(map, 'click', function(event) {
						placeMarker(event.latLng);
					});
					
					var currentAddress;
					var customAddress = false;
					$j("#geolocation-address").click(function(){
						currentAddress = $j(this).val();
						if(currentAddress != '')
							$j("#geolocation-address").val('');
					});
					
					$j("#geolocation-load").click(function(){
						if($j("#geolocation-address").val() != '') {
							customAddress = true;
							currentAddress = $j("#geolocation-address").val();
							geocode(currentAddress);
						}
					});
					
					$j("#geolocation-address").keyup(function(e) {
						if(e.keyCode == 13)
							$j("#geolocation-load").click();
					});
					
					$j("#geolocation-enabled").click(function(){
						enableGeo();
					});
					
					$j("#geolocation-disabled").click(function(){
						disableGeo();
					});
									
					function placeMarker(location) {
						marker.setPosition(location);
						map.setCenter(location);
						if((location.lat() != '') && (location.lng() != '')) {
							$j("#geolocation-latitude").val(location.lat());
							$j("#geolocation-longitude").val(location.lng());
						}
						
						if(!customAddress)
							reverseGeocode(location);
					}
					
					function geocode(address) {
						var geocoder = new google.maps.Geocoder();
					    if (geocoder) {
							geocoder.geocode({"address": address}, function(results, status) {
								if (status == google.maps.GeocoderStatus.OK) {
									placeMarker(results[0].geometry.location);
									if(!hasLocation) {
								    	map.setZoom(16);
								    	hasLocation = true;
									}
								}
							});
						}
						$j("#geodata").html(latitude + ', ' + longitude);
					}
					
					function reverseGeocode(location) {
						var geocoder = new google.maps.Geocoder();
					    if (geocoder) {
							geocoder.geocode({"latLng": location}, function(results, status) {
							if (status == google.maps.GeocoderStatus.OK) {
							  if(results[1]) {
							  	var address = results[1].formatted_address;
							  	if(address == "")
							  		address = results[7].formatted_address;
							  	else {
									$j("#geolocation-address").val(address);
									placeMarker(location);
							  	}
							  }
							}
							});
						}
					}
					
					function enableGeo() {
						$j("#geolocation-address").removeAttr('disabled');
						$j("#geolocation-load").removeAttr('disabled');
						$j("#geolocation-map").css('filter', '');
						$j("#geolocation-map").css('opacity', '');
						$j("#geolocation-map").css('-moz-opacity', '');
						$j("#geolocation-public").removeAttr('disabled');
						$j("#geolocation-map").removeAttr('readonly');
						$j("#geolocation-disabled").removeAttr('checked');
						$j("#geolocation-enabled").attr('checked', 'checked');
						
						if(public == '1')
							$j("#geolocation-public").attr('checked', 'checked');
					}
					
					function disableGeo() {
						$j("#geolocation-address").attr('disabled', 'disabled');
						$j("#geolocation-load").attr('disabled', 'disabled');
						$j("#geolocation-map").css('filter', 'alpha(opacity=50)');
						$j("#geolocation-map").css('opacity', '0.5');
						$j("#geolocation-map").css('-moz-opacity', '0.5');
						$j("#geolocation-map").attr('readonly', 'readonly');
						$j("#geolocation-public").attr('disabled', 'disabled');
						
						$j("#geolocation-enabled").removeAttr('checked');
						$j("#geolocation-disabled").attr('checked', 'checked');
						
						if(public == '1')
							$j("#geolocation-public").attr('checked', 'checked');
					}
				});
			});
		</script>
	<?php
}

function add_geo_div() {
	$width = esc_attr(get_option('geolocation_map_width'));
	$height = esc_attr(get_option('geolocation_map_height'));
	echo '<div id="map" class="geolocation-map" style="width:'.$width.'px;height:'.$height.'px;"></div>';
}

function add_geo_support() {
	global $geolocation_options, $posts;
	
	// To do: add support for multiple Map API providers
	switch(PROVIDER) {
		case 'google':
			echo add_google_maps($posts);
			break;
		case 'yahoo':
			echo add_yahoo_maps($posts);
			break;
		case 'bing':
			echo add_bing_maps($posts);
			break;
	}
	echo '<link type="text/css" rel="stylesheet" href="'.esc_url(plugins_url('style.css', __FILE__)).'" />';
}

function add_google_maps($posts) {
	default_settings();
	$zoom = (int) get_option('geolocation_default_zoom');
	global $post_count;
	$post_count = count($posts);
	
	echo '<script type="text/javascript" src="http://maps.google.com/maps/api/js?sensor=false"></script>
	<script type="text/javascript">
		var $j = jQuery.noConflict();
		$j(function(){
			var center = new google.maps.LatLng(0.0, 0.0);
			var myOptions = {
		      zoom: '.$zoom.',
		      center: center,
		      mapTypeId: google.maps.MapTypeId.ROADMAP
		    };
		    var map = new google.maps.Map(document.getElementById("map"), myOptions);
		    var image = "'.esc_js(esc_url(plugins_url('img/wp_pin.png', __FILE__ ))).'";
		    var shadow = new google.maps.MarkerImage("'.plugins_url('img/wp_pin_shadow.png', __FILE__ ).'",
		    	new google.maps.Size(39, 23),
				new google.maps.Point(0, 0),
				new google.maps.Point(12, 25));
		    var marker = new google.maps.Marker({
					position: center, 
					map: map, 
					title:"Post Location"';
				if(get_option('geolocation_wp_pin')) {
					echo ',
					icon: image,
					shadow: shadow';
				}
				echo '});
			
			var allowDisappear = true;
			var cancelDisappear = false;
		    
			$j(".geolocation-link").mouseover(function(){
				$j("#map").stop(true, true);
				var lat = $j(this).attr("name").split(",")[0];
				var lng = $j(this).attr("name").split(",")[1];
				var latlng = new google.maps.LatLng(lat, lng);
				placeMarker(latlng);
				
				var offset = $j(this).offset();
				$j("#map").fadeTo(250, 1);
				$j("#map").css("z-index", "99");
				$j("#map").css("visibility", "visible");
				$j("#map").css("top", offset.top + 20);
				$j("#map").css("left", offset.left);
				
				allowDisappear = false;
				$j("#map").css("visibility", "visible");
			});
			
			$j(".geolocation-link").mouseover(function(){
			});
			
			$j(".geolocation-link").mouseout(function(){
				allowDisappear = true;
				cancelDisappear = false;
				setTimeout(function() {
					if((allowDisappear) && (!cancelDisappear))
					{
						$j("#map").fadeTo(500, 0, function() {
							$j("#map").css("z-index", "-1");
							allowDisappear = true;
							cancelDisappear = false;
						});
					}
			    },800);
			});
			
			$j("#map").mouseover(function(){
				allowDisappear = false;
				cancelDisappear = true;
				$j("#map").css("visibility", "visible");
			});
			
			$j("#map").mouseout(function(){
				allowDisappear = true;
				cancelDisappear = false;
				$j(".geolocation-link").mouseout();
			});
			
			function placeMarker(location) {
				map.setZoom('.$zoom.');
				marker.setPosition(location);
				map.setCenter(location);
			}
			
			google.maps.event.addListener(map, "click", function() {
				window.location = "http://maps.google.com/maps?q=" + map.center.lat() + ",+" + map.center.lng();
			});
		});
	</script>';
}

function geo_has_shortcode($content) {
	$pos = strpos($content, SHORTCODE);
	if($pos === false)
		return false;
	else
		return true;
}

function display_location($content)  {
	default_settings();
	global $post, $shortcode_tags, $post_count;

	// Backup current registered shortcodes and clear them all out
	$orig_shortcode_tags = $shortcode_tags;
	$shortcode_tags = array();
	$post_id = $post->ID;
	$latitude = clean_coordinate(get_post_meta($post->ID, 'geo_latitude', true));
	$longitude = clean_coordinate(get_post_meta($post->ID, 'geo_longitude', true));
	$address = get_post_meta($post->ID, 'geo_address', true);
	$public = (bool)get_post_meta($post->ID, 'geo_public', true);
	
	$on = true;
	if(get_post_meta($post->ID, 'geo_enabled', true) != '')
		$on = (bool)get_post_meta($post->ID, 'geo_enabled', true);
	
	if(empty($address))
		$address = reverse_geocode($latitude, $longitude);
	
	if((!empty($latitude)) && (!empty($longitude) && ($public == true) && ($on == true))) {
		$html = '<a class="geolocation-link" href="#" id="geolocation'.$post->ID.'" name="'.$latitude.','.$longitude.'" onclick="return false;">Posted from '.esc_html($address).'.</a>';
		switch(esc_attr(get_option('geolocation_map_position')))
		{
			case 'before':
				$content = str_replace(SHORTCODE, '', $content);
				$content = $html.'<br/><br/>'.$content;
				break;
			case 'after':
				$content = str_replace(SHORTCODE, '', $content);
				$content = $content.'<br/><br/>'.$html;
				break;
			case 'shortcode':
				$content = str_replace(SHORTCODE, $html, $content);
				break;
		}
	}
	else {
		$content = str_replace(SHORTCODE, '', $content);
	}

	// Put the original shortcodes back
	$shortcode_tags = $orig_shortcode_tags;
	
    return $content;
}

function reverse_geocode($latitude, $longitude) {
	$url = "http://maps.google.com/maps/api/geocode/json?latlng=".$latitude.",".$longitude."&sensor=false";
	$result = wp_remote_get($url);
	$json = json_decode($result['body']);
	foreach ($json->results as $result)
	{
		foreach($result->address_components as $addressPart) {
			if((in_array('locality', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$city = $addressPart->long_name;
	    	else if((in_array('administrative_area_level_1', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$state = $addressPart->long_name;
	    	else if((in_array('country', $addressPart->types)) && (in_array('political', $addressPart->types)))
	    		$country = $addressPart->long_name;
		}
	}
	
	if(($city != '') && ($state != '') && ($country != ''))
		$address = $city.', '.$state.', '.$country;
	else if(($city != '') && ($state != ''))
		$address = $city.', '.$state;
	else if(($state != '') && ($country != ''))
		$address = $state.', '.$country;
	else if($country != '')
		$address = $country;
		
	return $address;
}

function clean_coordinate($coordinate) {
	$pattern = '/^(\-)?(\d{1,3})\.(\d{1,15})/';
	preg_match($pattern, $coordinate, $matches);
	return $matches[0];
}

function add_settings() {
	if ( is_admin() ){ // admin actions
		add_options_page('Geolocation Plugin Settings', 'Geolocation', 'administrator', 'geolocation.php', 'geolocation_settings_page', __FILE__);
  		add_action( 'admin_init', 'register_settings' );
	} else {
	  // non-admin enqueues, actions, and filters
	}
}

function register_settings() {
  register_setting( 'geolocation-settings-group', 'geolocation_map_width', 'intval' );
  register_setting( 'geolocation-settings-group', 'geolocation_map_height', 'intval' );
  register_setting( 'geolocation-settings-group', 'geolocation_default_zoom', 'intval' );
  register_setting( 'geolocation-settings-group', 'geolocation_map_position' );
  register_setting( 'geolocation-settings-group', 'geolocation_wp_pin');
}

function is_checked($field) {
	if (get_option($field))
 		echo ' checked="checked" ';
}

function is_value($field, $value) {
	if (get_option($field) == $value) 
 		echo ' checked="checked" ';
}

function default_settings() {
	if(get_option('geolocation_map_width') == '0')
		update_option('geolocation_map_width', '450');
		
	if(get_option('geolocation_map_height') == '0')
		update_option('geolocation_map_height', '200');
		
	if(get_option('geolocation_default_zoom') == '0')
		update_option('geolocation_default_zoom', '16');
		
	if(get_option('geolocation_map_position') == '0')
		update_option('geolocation_map_position', 'after');
}

function geolocation_settings_page() {
	default_settings();
	$zoomImage = get_option('geolocation_default_zoom');
	if(get_option('geolocation_wp_pin'))
		$zoomImage = 'wp_'.$zoomImage.'.png';
	else
		$zoomImage = $zoomImage.'.png';
	?>
	<style type="text/css">
		#zoom_level_sample { background: url('<?php echo esc_url(plugins_url('img/zoom/'.$zoomImage, __FILE__)); ?>'); width:390px; height:190px; border: solid 1px #999; }
		#preload { display: none; }
		.dimensions strong { width: 50px; float: left; }
		.dimensions input { width: 50px; margin-right: 5px; }
		.zoom label { width: 50px; margin: 0 5px 0 2px; }
		.position label { margin: 0 5px 0 2px; }
	</style>
	<script type="text/javascript">
		var file;
		var zoomlevel = <?php echo (int) esc_attr(get_option('geolocation_default_zoom')); ?>;
		var path = '<?php echo esc_js(plugins_url('img/zoom/', __FILE__)); ?>';
		function swap_zoom_sample(id) {
			zoomlevel = document.getElementById(id).value;
			pin_click();
		}
		
		function pin_click() {
			var div = document.getElementById('zoom_level_sample');
			file = path + zoomlevel + '.png';
			if(document.getElementById('geolocation_wp_pin').checked)
				file = path + 'wp_' + zoomlevel + '.png';
			div.style.background = 'url(' + file + ')';
		}
	</script>
	<div class="wrap"><h2>Geolocation Plugin Settings</h2></div>
	
	<form method="post" action="options.php">
    <?php settings_fields( 'geolocation-settings-group' ); ?>
    <table class="form-table">
        <tr valign="top">
	        <tr valign="top">
	        <th scope="row">Dimensions</th>
	        <td class="dimensions">
	        	<strong>Width:</strong><input type="text" name="geolocation_map_width" value="<?php echo esc_attr(get_option('geolocation_map_width')); ?>" />px<br/>
	        	<strong>Height:</strong><input type="text" name="geolocation_map_height" value="<?php echo esc_attr(get_option('geolocation_map_height')); ?>" />px
	        </td>
        </tr>
        <tr valign="top">
        	<th scope="row">Position</th>
        	<td class="position">        	
				<input type="radio" id="geolocation_map_position_before" name="geolocation_map_position" value="before"<?php is_value('geolocation_map_position', 'before'); ?>><label for="geolocation_map_position_before">Before the post.</label><br/>
				
				<input type="radio" id="geolocation_map_position_after" name="geolocation_map_position" value="after"<?php is_value('geolocation_map_position', 'after'); ?>><label for="geolocation_map_position_after">After the post.</label><br/>
				<input type="radio" id="geolocation_map_position_shortcode" name="geolocation_map_position" value="shortcode"<?php is_value('geolocation_map_position', 'shortcode'); ?>><label for="geolocation_map_position_shortcode">Wherever I put the <strong>[geolocation]</strong> shortcode.</label>
	        </td>
        </tr>
        <tr valign="top">
	        <th scope="row">Default Zoom Level</th>
	        <td class="zoom">        	
				<input type="radio" id="geolocation_default_zoom_globe" name="geolocation_default_zoom" value="1"<?php is_value('geolocation_default_zoom', '1'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="geolocation_default_zoom_globe">Globe</label>
				
				<input type="radio" id="geolocation_default_zoom_country" name="geolocation_default_zoom" value="3"<?php is_value('geolocation_default_zoom', '3'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="geolocation_default_zoom_country">Country</label>
				<input type="radio" id="geolocation_default_zoom_state" name="geolocation_default_zoom" value="6"<?php is_value('geolocation_default_zoom', '6'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="geolocation_default_zoom_state">State</label>
				<input type="radio" id="geolocation_default_zoom_city" name="geolocation_default_zoom" value="9"<?php is_value('geolocation_default_zoom', '9'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="geolocation_default_zoom_city">City</label>
				<input type="radio" id="geolocation_default_zoom_street" name="geolocation_default_zoom" value="16"<?php is_value('geolocation_default_zoom', '16'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="geolocation_default_zoom_street">Street</label>
				<input type="radio" id="geolocation_default_zoom_block" name="geolocation_default_zoom" value="18"<?php is_value('geolocation_default_zoom', '18'); ?> onclick="javascipt:swap_zoom_sample(this.id);"><label for="geolocation_default_zoom_block">Block</label>
				<br/>
				<div id="zoom_level_sample"></div>
	        </td>
        </tr>
        <tr valign="top">
        	<th scope="row"></th>
        	<td class="position">        	
				<input type="checkbox" id="geolocation_wp_pin" name="geolocation_wp_pin" value="1" <?php is_checked('geolocation_wp_pin'); ?> onclick="javascript:pin_click();"><label for="geolocation_wp_pin">Show your support for WordPress by using the WordPress map pin.</label>
	        </td>
        </tr>
    </table>
    
    <p class="submit">
    <input type="submit" class="button-primary" value="<?php _e('Save Changes') ?>" />
    </p>
	<input type="hidden" name="action" value="update" />
	<input type="hidden" name="page_options" value="geolocation_map_width,geolocation_map_height,geolocation_default_zoom,geolocation_map_position,geolocation_wp_pin" />
</form>
	<div id="preload">
		<img src="<?php echo esc_url(plugins_url('img/zoom/1.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/3.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/6.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/9.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/16.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/18.png', __FILE__)); ?>"/>
		
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_1.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_3.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_6.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_9.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_16.png', __FILE__)); ?>"/>
		<img src="<?php echo esc_url(plugins_url('img/zoom/wp_18.png', __FILE__)); ?>"/>
	</div>
	<?php
}

?>