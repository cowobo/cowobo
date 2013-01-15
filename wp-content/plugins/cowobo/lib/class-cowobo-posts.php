<?php
// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class CoWoBo_Posts
{
    /**
     * Create a new post
     */
    public function create_post(){
        global $cowobo, $profile_id;

        $newcat = $cowobo->query->new;
        $catid = get_cat_ID( $newcat );

        //insert the post
        $current_user = wp_get_current_user();
        $postid = wp_insert_post( array(
            'post_status' => 'auto-draft',
            'post_title' => ' ',
            'post_category' => array( $catid ),
            'post_author' => $current_user->ID,
        ));

        //add the user to the authors list (used for multiple author checks)
        add_post_meta( $postid, 'author', $profile_id );

        return $postid;
    }

    /**
     * Delete post and all links associated with it
     */
    public function delete_post() {
        global $related, $cowobo;

        $deleteid = $cowobo->query->id;
        $related->delete_relations($deleteid);
        if ( wp_delete_post($deleteid) ) {
            $cowobo->notifications[] = array (
                "error" => "An error occurred deleting your post."
            );
        } else {
            $cowobo->notifications[] = array (
                "success" => "Post succesfully deleted."
            );
        }
    }
}


//Save post with new data
function cwb_save_post(){
	global $related; global $post; global $social;

	//store all data
	$postid = $_POST['post_ID'];

	$post_title  = (isset($_POST['post_title'])) ? trim(strip_tags($_POST['post_title'])) : null;
	$post_content = ( isset($_POST['post_content']) ) ? trim($_POST['post_content']) : null;
	$tags  = (isset($_POST['tags'])) ? trim(strip_tags($_POST['tags'])) : null;
	$oldcity = get_post_meta($postid, 'cityid', true);
	$oldslug = $post->post_name;
	$involvement = $_POST['involvement'];
	$newslug = sanitize_title($post_title);
	$postcat = cwob_get_category($postid);
	$author = true;

	//check if post is created from within another post
	if($postid != $post->ID) $linkedid = $post->ID;


	//if the user is not involved don't link it to their profile
	if($involvement == 'none'):
		$related->delete_relations($postid, $social->post_id); //existing posts
		$linkedid = false;
	else:
		$linkedid = $social->profile_id;
	endif;

	//check if title filled correctly
	if ($post_title == '') $postmsg['post_title'] = 'You forgot to add one.';

	//check if the user entered all text in english
	if(!$_POST['confirmenglish'])  $postmsg['confirmenglish'] = 'Please check if all text is in English and check the checbox below';

	//update all the custom fields
	foreach ($_POST as $key => $value) :
		if($value != ''):
			delete_post_meta($postid, $key);
			if(strpos($key,'-checked')== true):
				foreach ($value as $newval):
					add_post_meta($postid, $key, $newval);
				endforeach;
			else:
				add_post_meta($postid, $key, $value);
			endif;
		endif;
	endforeach;

	//if its a new location post geocode its location
	if($postcat->slug == 'location'):
		if($countryid = $_POST['country']):
			$tagarray = array($countryid);
			if($latlng = cwb_geocode($post_title.', '.$country)):
				$coordinates = $latlng['lat'].','.$latlng['lng'];
				$citypost = get_posts('meta_key=coordinates&meta_value='.$coordinates);
				//check if coordinates have already been added (avoids international spelling differences)
				if($citypost && $citypost[0]->ID != $postid):
					$postmsg['post_title'] = 'The location you are trying to add already exists';
				else:
					add_post_meta($postid, 'coordinates', $coordinates);
				endif;
				if(!empty($linkedid)): $related->create_relations($postid, array($linkedid)); endif;
			else:
				$postmsg['post_title'] = 'We could not find that city. Check your spelling or internet connection.';
			endif;
		else:
			$postmsg['country'] = 'Please select a country';
		endif;
	endif;

	//if post contains a location create or link to that location post
	if($city = $_POST['city']):
		if($city != get_post_meta($postid, 'cityid', true)):
			if($countryid = $_POST['country']):
				$countrycat = get_category($countryid);
				if($latlng = cwb_geocode($city.', '.$countrycat->name)):
					$coordinates = $latlng['lat'].','.$latlng['lng'];
					$citypost = get_posts('meta_key=coordinates&meta_value='.$coordinates);
					//check if coordinates have already been added (avoids international spelling differences)
					if($citypost):
						$cityid = $citypost[0]->ID;
					else:
						//todo use returned geocoding city name
						$cityid = wp_insert_post(array('post_title'=>$city, 'post_category'=>array($countryid), 'post_status'=>'Publish'));
						add_post_meta($cityid, 'coordinates', $coordinates);
					endif;
					$related->delete_relations($postid, $oldcity);
					$related->create_relations($postid, array($cityid));
					add_post_meta($postid, 'cityid', $cityid);  //save ID to check city next time
					update_post_meta($postid, 'coordinates', $coordinates);
				else:
					$postmsg['location'] = 'We could not find that city. Check your spelling or internet connection.';
				endif;
			else:
				$postmsg['location'] = 'Please select a country';
			endif;
		endif;
		add_post_meta($postid, $key, $value);
	endif;

	//get ids for each tag and create them if they dont already exist
	if ($tags != ''):
		foreach(explode(',', $tags) as $tag):
			$tagid = term_exists(trim($tag), 'category', $postcat->term_id);
			if(!$tagid) $tagid = wp_insert_term(trim($tag), 'category', array('parent'=> $postcat->term_id));
			$tagarray[] = $tagid['term_id'];
		endforeach;
		$tagarray = array_map('intval', $tagarray);
	    $tagarray = array_unique($tagarray);
	elseif($postcat->slug != 'location'):
		 $postmsg['tags'] = 'You must add atleast one.';
	endif;

	//handle images
    /**
     * @todo check for malicious code in jpg?
     */
	for ($x=0; $x<5; $x++):
		$imgid = $_POST['imgid'.$x];
		$file = $_FILES['file'.$x]['name'];
		$videocheck = explode("?v=", $_POST['caption'.$x]);
		//delete image if selected or being replaced by something else
		if($_POST['delete'.$x] or !empty($file) or !empty($videocheck[1])):
			wp_delete_attachment($imgid, true);
			delete_post_meta($postid, 'imgid'.$x);
		endif;
		//add new image
		if(!empty($file)):
			$imgid = cwb_insert_attachment('file'.$x, $postid);
			update_post_meta($postid, 'imgid'.$x, $imgid);
		endif;
	endfor;

	//update draft post
	$postdata = array('ID' => $postid, 'post_title' => $post_title, 'post_content' => $post_content, 'post_status' => 'draft', 'post_category' => $tagarray);
	wp_update_post($postdata);

	// if there are no errors publish post, add links, and show thanks for saving message
	if(empty($postmsg)):
		wp_update_post( array('ID' => $postid,'post_status' => 'publish', 'post_name' =>$newslug));
		if(!empty($linkedid)) $related->create_relations($postid, array($linkedid));
		$postmsg = 'saved';
	endif;

	return $postmsg;
}

//insert and resize uploaded attachments
function cwb_insert_attachment($file_handler,$post_id,$setthumb='false') {
  if ($_FILES[$file_handler]['error'] !== UPLOAD_ERR_OK) __return_false();
  require_once(ABSPATH . "wp-admin" . '/includes/image.php');
  require_once(ABSPATH . "wp-admin" . '/includes/file.php');
  require_once(ABSPATH . "wp-admin" . '/includes/media.php');
  $attach_id = media_handle_upload( $file_handler, $post_id );
  if ($setthumb) update_post_meta($post_id,'_thumbnail_id',$attach_id);
  return $attach_id;
}

//Link post to another related post
function cwb_link_post(){
	global $related; global $post;
	$related->create_relations($post->ID, array($_POST['linkto']));
}


//Get primal category of post
function cwob_get_category($postid) {
	$cat = get_the_category($postid);
	$ancestors = get_ancestors($cat[0]->term_id,'category');
	if (empty($ancestors)) return $cat[0];
	return get_category(array_pop($ancestors));
}

//Return time passed since publish date
function cwb_time_passed($timestamp){
    $timestamp = (int) $timestamp;
    $current_time = time();
    $diff = $current_time - $timestamp;
    $intervals = array ('day' => 86400, 'hour' => 3600, 'minute'=> 60);
    //now we just find the difference
    if ($diff == 0) return 'just now &nbsp;';
    if ($diff < $intervals['hour']){
        $diff = floor($diff/$intervals['minute']);
        return $diff == 1 ? $diff . ' min ago' : $diff . ' mins ago';
    }
    if ($diff >= $intervals['hour'] && $diff < $intervals['day']){
        $diff = floor($diff/$intervals['hour']);
        return $diff == 1 ? $diff . ' hour ago' : $diff . ' hours ago';
    }
    if ($diff >= $intervals['day']){
        $diff = floor($diff/$intervals['day']);
        return $diff == 1 ? $diff . ' day ago' : $diff . ' days ago';
    }
}

//Store post views
function cwb_update_views($postID) {
    $count_key = 'cowobo_post_views';
    $count = get_post_meta($postID, $count_key, true);
    if($count==''){
        $count = 0;
        delete_post_meta($postID, $count_key);
        add_post_meta($postID, $count_key, '0');
    }else{
        $count++;
        update_post_meta($postID, $count_key, $count);
    }
}

//Retrieve post views
function cwb_get_views($postID){
    $count_key = 'cowobo_post_views';
    $count = get_post_meta($postID, $count_key, true);
    if($count==''){
        delete_post_meta($postID, $count_key);
        add_post_meta($postID, $count_key, '0');
        $count = '0';
    }
    return $count;
}

//returns the first image in a post
function cwb_get_first_image($postID){
	foreach(get_children('post_parent='.$postID.'&numberposts=1&post_mime_type=image') as $image):
		$src = wp_get_attachment_image_src($image->ID, $size = 'medium');
	endforeach;
	return $src[0];
}