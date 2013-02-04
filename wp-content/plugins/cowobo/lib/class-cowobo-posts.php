<?php
// Exit if accessed directly
if (!defined('ABSPATH'))
    exit;

class CoWoBo_Posts
{

    public function __construct() {
        $this->has_requests();
    }

    /**
     * Delete post and all links associated with it
     */
    public function delete_post() {

        $deleteid = cowobo()->query->post_ID;
        cowobo()->relations->delete_relations($deleteid);
        if ( wp_delete_post($deleteid) ) {
            wp_redirect ( add_query_arg ( array ( 'message' => 'post_deleted' ), get_bloginfo ( 'url' ) ) );
            exit;
        } else {
            cowobo()->notifications[] = array (
                "error" => "An error occurred deleting your post."
            );
            /*cowobo()->notifications[] = array (
                "success" => "Post succesfully deleted."
            );*/
        }
    }

    public function confirm_delete() {
        $postid = cowobo()->query->post_ID;
        $nonce = wp_create_nonce( 'delete_confirmed', 'delete_confirmed' );
        $post_id_field = "postid";
        $title = '<a href="' . get_permalink( $postid ) . '">' . get_the_title( $postid ) . '</a>';
        $out = "<form action='' method='POST'><p>You are about to delete $title. You <strong>cannot</stron> undo this action</p>
            <p>Are you sure you want to do this?</p>
            <input type='hidden' name='post_ID' value='$postid'>
            <p>
                <button type='submit' class='button' name='delete_confirmed' value='$nonce'>Yes, delete this post</button>
                <a href='" . get_permalink( $postid ) . "'>No, take me back!</a>
            </p>
            </form>";
        cowobo()->add_notice( $out, 'confirm_delete');

    }

    /**
     * Save post with new data
     * @todo This is one beast of a method - can we make some subroutines?
     */
    public function save_post(){

        global $post, $profile_id;

        cowobo()->remove_notice('post_saved');
        $linkedid = 0; $tagarray = array();

        //store all data
        $postid = cowobo()->query->post_ID;

        $post_title  = ( cowobo()->query->post_title ) ? trim(strip_tags( cowobo()->query->post_title ) ) : null;
        $post_content = ( cowobo()->query->post_content ) ? trim( strip_tags ( cowobo()->query->post_content, '<p><a><br><b><i><em><strong><ul><li><blockquote>' ) ) : null;
        $tags  = ( cowobo()->query->tags ) ? trim(strip_tags( cowobo()->query->tags ) ) : null;
        $oldcityid = get_post_meta($postid, 'cwb_city', true);
        $involvement = cowobo()->query->cwb_involvement;
        $newslug = sanitize_title($post_title);

        $postcat = ( ! cowobo()->query->new )  ?$this->get_category($postid) : get_category ( get_cat_ID( cowobo()->query->new ) );
        $tagarray = array( $postcat->term_id );

        if ( ! $postid ) {
            $postid = $GLOBALS['newpostid'] = wp_insert_post( array('post_name' =>$newslug, 'post_category' => array ( get_cat_ID( cowobo()->query->new ) ), 'post_content' => " " ) );
            add_post_meta( $postid, 'cwb_author', $profile_id);
            //$_POST['cwb_author'] = $profile_id;
        }

        //check if post is created from within another post
        //if($postid != $post->ID) $linkedid = $post->ID;

        if ( empty ( $post_content ) ) {
            $postmsg['largetext'] = "Please add some content to your post!";
            $post_content = ' ';
        }

        //if the user is not involved don't link it to their profile
        if($involvement == 'none') {
            cowobo()->relations->delete_relations($postid, $profile_id); //existing posts
            $linkedid = false;
        } elseif($postcat->slug != 'location') {
            $linkedid = $profile_id;
        }

        //check if title filled correctly
        if ($post_title == '') $postmsg['title'] = 'You forgot to add one.';

        //check if the user entered all text in english
        if(!cowobo()->query->confirmenglish)  $postmsg['confirmenglish'] = 'Please check if all text is in English and check the checbox below';


		//delete old post data in case they were cleared in the form
		foreach (get_post_custom_keys($postid) as $key ) {
		    $valuet = trim($key);
		    //if ( '_' == $valuet{0} ) continue; // don't touch wordpress fields
            if ( "cwb_" != substr ( $valuet, 0, 4 ) || $valuet == "cwb_author" ) continue;
		    delete_post_meta($postid, $key);
		}

		//now store the new data
        foreach ($_POST as $key => $value) {
            if( empty ( $value ) || "cwb_" != substr ( $key, 0, 4 ) ) continue;
            if(strpos($key,'-checked')== true) {
                foreach ($value as $newval) {
                    add_post_meta($postid, $key, $newval);
                }
            }else {
                add_post_meta($postid, $key, $value);
            }
        }

        //if its a new location post geocode its location
        if( $postcat->slug == 'location') {
            if( $country = cowobo()->query->cwb_country ) {
				if($location = cwb_geocode( $post_title.', '.$country ) ) {
					//check if location has already been added
					$countryid = get_cat_ID( $location['country']);
					$citypost = get_posts('cat='.$countryid.'&name='.sanitize_title($location['city']) );
					if( $citypost && $citypost[0]->ID != $postid ) {
                        $postmsg['title'] = 'This location already exists, <a href="'.get_permalink($$citypost[0]->ID).'?action=editpost">click here to edit it</a>';
                    } else {
						//use title and country returned from geocode to avoid spelling duplicates
                        $post_title = $location['city'];
						$coordinates = $location['lat'].','.$location['lng'];
						update_post_meta($postid, 'cwb_country', $location['country']);
						update_post_meta($postid, 'cwb_coordinates', $coordinates);
						if($countryid)
							$tagarray[] = $countryid;
						else {
							$tagid = wp_insert_term( $location['country'] , 'category', array('parent'=> get_cat_ID('Locations')));
							$tagarray[] = $tagid['term_id'];
				            $tagarray = array_map('intval', $tagarray);
						}
                    }
                } else {
                    $postmsg['title'] = 'We could not find that city. Check your spelling or internet connection.';
                }
            } else {
                $postmsg['country'] = 'Please enter a country';
            }
        }

        //if post contains a location create or link to that location post
        if( $newlocation = cowobo()->query->cwb_location ) {
			if( $location = cwb_geocode( $newlocation ) ) {
				$coordinates = $location['lat'].','.$location['lng'];
				$countryid = get_cat_ID( $location['country']);
				$citypost = get_posts('cat='.$countryid.'&name='.sanitize_title($location['city']) );
				//check if location has already been added
                if( $citypost ) {
                	$cityid = $citypost[0]->ID;
					$countrycat = get_the_category($cityid);
					$countryid  = $countrycat[0]->term_id;
                } else {
					if( ! $countryid ) {
						$tagid = wp_insert_term( $location['country'] , 'category', array('parent'=> get_cat_ID('Locations')));
						$countryid = $tagid['term_id'];
					}
					$cityid = wp_insert_post(array('post_title'=>$location['city'], 'post_category'=>array($countryid), 'post_status'=>'publish'));
					update_post_meta( $cityid, 'cwb_coordinates', $coordinates);
				}

				update_post_meta( $postid, 'cwb_country', $countryid );
				update_post_meta( $postid, 'cwb_city', $cityid );
				update_post_meta( $postid, 'cwb_coordinates', $coordinates);
                cowobo()->relations->delete_relations($postid, $oldcityid);
                cowobo()->relations->create_relations($postid, array($cityid));

				//check if streetview is available
				if( cowobo()->query->cwb_includestreet && !cwb_streetview($postid) )
				$postmsg['location'] = 'The address you entered does not have streetview, try another?';

			} else {
             	$postmsg['location'] = 'We could not find that city. Check your spelling or internet connection.';
			}
		} else {
			cowobo()->relations->delete_relations($postid, $oldcityid);
        }

        //get ids for each tag and create them if they dont already exist
        if ( ! empty ( $tags ) ) {
            foreach(explode(',', $tags) as $tag) {
                $tagid = term_exists(trim($tag), 'category', $postcat->term_id);
                if(!$tagid) $tagid = wp_insert_term(trim($tag), 'category', array('parent'=> $postcat->term_id));
                if ( is_a ( $tagid, 'WP_Error' ) ) continue;
                $tagarray[] = $tagid['term_id'];
            }
            $tagarray = array_map('intval', $tagarray);
            $tagarray = array_unique($tagarray);
        } elseif($postcat->slug != 'location') {
             //$postmsg['tags'] = 'You must add atleast one.';
        }

        //handle images
        /**
         * @todo check for malicious code in jpg?
         */
        for ($x=0; $x<3; $x++):
            $imgid = "cwb_imgid$x";
			$oldid = cowobo()->query->$imgid;
            $file = ( isset ( $_FILES['file'.$x] ) ) ? $_FILES['file'.$x]['name'] : '';
            $url_id = "cwb_url$x";
            $imgurl = cowobo()->query->$url_id;
		    $videocheck = explode("?v=", $imgurl );
            $imagecheck = $this->is_image_url ( $imgurl );

            //delete old image if url is empty or being replaced by another image/imageurl/videourl
            if(!empty($file) || empty($imgurl) || !empty($videocheck[1]) || $imagecheck ):
				wp_delete_attachment($oldid, true);
                delete_post_meta($postid, 'cwb_imgid'.$x);
            else:
				update_post_meta($postid, 'cwb_imgid'.$x, $oldid);
			endif;

            //add new image
            if(!empty($file)) {
                $newid = $this->insert_attachment('file'.$x, $postid);
                update_post_meta($postid, 'cwb_imgid'.$x, $newid);
            }

        endfor;

        // if there are no errors publish post, add links, and show thanks for saving message
        if(empty($postmsg)) {
            $post_content = preg_replace( '@(?<![.*">])\b(?:(?:https?|ftp|file)://|[a-z]\.)[-A-Z0-9+&#/%=~_|$?!:,.]*[A-Z0-9+&#/%=~_|$]@i', '<a href="\0" target="_blank">\0</a>', $post_content );
            wp_update_post( array('ID' => $postid,'post_status' => 'publish', 'post_title' => $post_title, 'post_content' => $post_content, 'post_category' => $tagarray ) );

            if ( ! isset ( $GLOBALS['newpostid'] ) || empty ( $GLOBALS['newpostid'] ) ) {
                do_action( 'cowobo_post_updated', $postid, $post_title );
            }

            if ( cowobo()->query->link_to ) cowobo()->relations->create_relations($postid, cowobo()->query->link_to );

			if(!empty($linkedid)) cowobo()->relations->create_relations($postid, $linkedid );

            wp_redirect ( add_query_arg ( array ( "action" => "editpost", "message" => "post_saved" ), get_permalink ( $postid ) ) );

            //$GLOBALS['newpostid'] = null;
        } else {
            cowobo()->add_notice ( "There has been an error saving your post. Please check all the fields below.", "savepost" );
            foreach ( $postmsg as $key => $msg ) {
                cowobo()->add_notice ( $msg, $key );
            }
        }

    }

    /**
     * Get primal category of post
     */
    public function get_category( $postid = 0 ) {
        if ( ! $postid )
            $postid = get_the_ID();

        if ( ! $postid ) return false;

        $cat = get_the_category($postid);
        $ancestors = get_ancestors($cat[0]->term_id,'category');
        if (empty($ancestors)) return $cat[0];
        return get_category(array_pop($ancestors));
    }

    //insert and resize uploaded attachments
    private function insert_attachment( $file_handler, $post_id, $setthumb='false' ) {
      if ($_FILES[$file_handler]['error'] !== UPLOAD_ERR_OK ) return false;

      require_once(ABSPATH . "wp-admin" . '/includes/image.php');
      require_once(ABSPATH . "wp-admin" . '/includes/file.php');
      require_once(ABSPATH . "wp-admin" . '/includes/media.php');

      $attach_id = media_handle_upload( $file_handler, $post_id );
      if ($setthumb) update_post_meta($post_id,'_thumbnail_id',$attach_id);
      return $attach_id;
    }

    /**
     * Store post views
     */
    public function update_views( $postID ) {
        $count_key = 'cwb_post_views';
        $count = get_post_meta($postID, $count_key, true);
        if( empty ( $count ) )
            $count = 0;

        $count++;

        update_post_meta($postID, $count_key, $count);


    }

    /**
     * Retrieve post views
     */
    public function get_views($postID){
        $count_key = 'cwb_post_views';
        $count = get_post_meta($postID, $count_key, true);
        if($count==''){
            delete_post_meta($postID, $count_key);
            add_post_meta($postID, $count_key, '0');
            $count = '0';
        }
        return $count;
    }

    /**
     * Gets the featured image, or the first image of a post
     *
     * If no attachments are found, returns first img in source (external link)
     *
     * @param str|WP_Post $post
     * @return string Image src
     */
    public function get_first_image ( $post ) {
        $image_size = 'medium';

        if ( is_numeric( $post ) )
            $post = get_post ( $post );

        if ( $post_thumbnail_id = get_post_thumbnail_id( $post->ID ) ) {
            $image = wp_get_attachment_image_src( $post_thumbnail_id, $image_size, false );
            return $image[0];
        }

        $images = get_children ( array (
            'post_parent'    => $post->ID,
            'numberposts'    => 1,
            'post_mime_type' =>'image'
        ) );

        if( ! empty ( $images ) ) {
            $images = current ( $images );
            $src = wp_get_attachment_image_src ( $images->ID, $size = $image_size );
            return $src[0];
        }

        if ( ! empty ( $post->post_content ) ) {
            $xpath = new DOMXPath( @DOMDocument::loadHTML( $post->post_content ) );
            $src = $xpath->evaluate( "string(//img/@src)" );
            return $src;
        }

        return '';
    }

    /**
     * Return gallery with captions and thumbs
     */
    public function loadgallery( $postid = false ) {

        $slides = array();
		$thumbs = array();
		$viewratio = 0.4; //default aspect ratio of image viewer
		$imgfolder = get_bloginfo('template_url').'/images';
		$postcat = cowobo()->posts->get_category($postid);

		if($postid) {

			for ($x=0; $x<3; $x++):

				//store slide info
				$imgpos = get_post_meta($postid, 'cwb_pos'.$x, true);
				$imgid = get_post_meta($postid, 'cwb_imgid'.$x, true);
				$image_check = false;
				$top = 0; $url = ''; $thumb = '';

				if ($imgurl = wp_get_attachment_image_src($imgid, $size = 'large')) {
					$thumbsrc = wp_get_attachment_image_src($imgid, $size = 'thumbnail');
					$thumb = '<img src="'.$thumbsrc[0].'" height="100%" alt=""/>';
					$url = $imgurl[0];
					$image_check = true;
				} elseif ( $url = get_post_meta($postid, 'cwb_url'.$x, true) ) {
					$videocheck = explode( "?v=", $url );
					if($image_check = $this->is_image_url( $url ))
					$thumb = '<img src="'.$url.'" style="margin:-100px" alt=""/>';
					//note: scaling large image to thumb results underlying gallery to drag slower
				}

				//determine image position
				if( $image_check && $imgpos != 'top') {
					list($width, $height) = getimagesize($url);
					$imgratio = $height/$width;
					$offset = $imgratio - $viewratio;
					if($imgpos == 'middle') $top = $offset * -100;
					elseif($imgpos == 'bottom') $top = $offset * -200;
				}

				//check if the slide is uploaded image, youtube video, or image url;
				if( isset ( $videocheck[1] ) && $videourl = $videocheck[1]) {
	                $slides[$x] = '<div class="slide hide" id="slide-'.$x.'"><object>';
	                    $slides[$x] .= '<param name="movie" value="http://www.youtube.com/v/'.$url.'">';
	                    $slides[$x] .= '<param NAME="wmode" VALUE="transparent">';
	                    $slides[$x] .= '<param name="allowFullScreen" value="true"><param name="allowScriptAccess" value="always">';
	                    $slides[$x] .= '<embed src="http://www.youtube.com/v/'.$videourl.'" type="application/x-shockwave-flash" allowfullscreen="true" allowScriptAccess="always" wmode="opaque" width="100%" height="100%"/>';
	                $slides[$x] .= '</object></div>';
					$thumbs[] = '<a class="'.$x.'" href="?img='.$x.'"><img src="http://img.youtube.com/vi/'.$videourl.'/1.jpg" height="100%" alt=""/></a>';
	            } elseif ( $image_check ) {
	                $slides[$x] = '<div class="slide hide" id="slide-'.$x.'" style="top:'.$top.'%">';
	                    $slides[$x] .= '<img class="slideimg" src="'.$url.'" width="100%" alt=""/>';
	                $slides[$x] .= '</div>';
					$thumbs[] = '<a class="'.$x.'" href="?img='.$x.'">'.$thumb.'</a>';

	            }

	           unset($imgid); unset($zoom2src);

	        endfor;

		}

		//include streetview if available
		$isstreet = get_post_meta($postid, 'cwb_includestreet', true);
		if( ( $isstreet or $postcat->slug == 'location' ) && $streetcheck = cwb_streetview($postid) ) {
			$slides[] = $streetcheck;
			$coordinates = get_post_meta($postid, 'cwb_coordinates', true);
			$thumbs[] = '<a class="street" href="?img=street"><img src="http://maps.googleapis.com/maps/api/streetview?size=50x50&location='.$coordinates.'&sensor=false" /></a>';
		}

		//include map if available
		$ismap = get_post_meta($postid, 'cwb_includemap', true);
		if( $ismap or $postcat->slug == 'location' or empty ( $thumbs ) ) {
			$thumbs[] = '<a class="map" href="?img=map"><img src="'.$imgfolder.'/maps/day_thumb.jpg" height="100%" /></a>';
			$slides[] = cwb_loadmap();
		}

		//show map first on homepage
		if( ! is_home() ) {
			$thumbs = array_reverse( $thumbs );
		} else {
			$slides = array_reverse($slides);
		}

		//show first slide
		$slides[0] = str_replace('slide hide' , 'slide' , $slides[0]);

		//echo html
		echo '<div class="imageholder">';
			echo implode('', $slides);
			echo '<img src="'.$imgfolder.'/ratio-map.png" width="100%" alt=""/>';
		echo '</div>';

		echo '<div class="titlebar">';
			echo '<div class="shade"></div>';
			echo '<img class="resizeicon" src="'.$imgfolder.'/resizeicon.png" title="Toggle viewer height" alt=""/>';
			echo '<div class="container">';
				echo '<div class="titlepadding"><div>';
					echo '<div class="feedtitle">'.cowobo()->feed->feed_title().'</div>';
					echo '<div class="smallthumbs">'.implode('', $thumbs).'</div>';
					echo '<div class="dragbar"></div>';
				echo '</div></div>';
			echo '</div>';
		echo '</div>';
    }


    /**
     * Echo thumbnail of post
     */
    function the_thumbnail($postid, $catslug = false){
        if($catslug == 'location') {
			$coordinates = get_post_meta($postid, 'cwb_coordinates', true);
            $position = get_map_position(149, 100, $coordinates);
			echo '<img style="'.$position.'" src="'.get_bloginfo('template_url').'/images/maps/day_thumb.jpg"/>';
            return;
        }

        if ( $catslug == 'coder' ) {
            $fallback = '';
            if ( $attached = get_children( 'post_parent='.$postid.'&numberposts=1&post_mime_type=image' ) ) {
                $attached_src = wp_get_attachment_image_src( current ( $attached )->ID );
                if ( is_array ( $attached_src ) )
                    $fallback = $attached_src[0];
            }
            if ( $user = cowobo()->users->get_users_by_profile_id( $postid, true ) ) {
                echo get_avatar( $user->ID, '149', $fallback );
                return;
            }
        }

		for ($x=0; $x<3; $x++):
			$url = get_post_meta($postid, 'cwb_url'.$x, true);
	        $imgid = get_post_meta($postid, 'cwb_imgid'.$x, true);
	        $videocheck = explode( "?v=", $url );
	        $image_check = $this->is_image_url( $url );
			if($imgsrc = wp_get_attachment_image_src($imgid, $size ='thumbnail')) {
				echo '<img src="'.$imgsrc[0].'" width="100%" alt=""/></a>'; return;
			} elseif( is_array ( $videocheck ) && isset ( $videocheck[1] ) && $videourl = $videocheck[1]) {
				echo '<img src="http://img.youtube.com/vi/'.$videourl.'/1.jpg" width="100%" alt=""/></a>'; return;
	        } elseif ( $image_check ) {
				echo '<img src="'.$url.'" width="100%" alt=""/></a>'; return;
			}
		endfor;

    }

    /**
     * Handle requests to edit posts
     *
     * @todo add BP notifications
     */
    public function cwb_upload_form($postid, $rows) {
		$query = cowobo()->query;
		$unsaved_data = ( ( $query->url || $query->save ) && ! cowobo()->has_notice ( 'saved' ) ) ? true : false;

		//include labels
		echo '<div class="lefthalf headerrow">';
			echo '<div class="thumbcol left">Thumb</div>';
			echo '<div class="urlcol">URL of image or youtube video</div>';
		echo '</div>';
		echo '<div class="righthalf">';
			echo '<div class="poscol right">Position</div>';
			echo '<div class="browsecol">Upload a new image</div>';
		echo '</div>';

		for ($x=0; $x<$rows; $x++):
			$url_id = "cwb_url$x";
			$pos_id = "cwb_pos$x";
			$imgurl = '';
			$thumb = '';
			$options = '';

			//store image data
			if ( $imgid = get_post_meta($postid, 'cwb_imgid'.$x, true) ) {
				$uploadurl = wp_get_attachment_image_src( $imgid, $size = 'full' );
				$urlbits = explode( '/', $uploadurl[0] );
				$imgurl = end( $urlbits );
				$thumb = wp_get_attachment_image( $imgid, $size = 'thumbnail' );
			} else {
				$imgurl = get_post_meta( $postid, $url_id, true );
				$thumb = '<img src="'.$imgurl.'" alt=""/>';
			}

	       	if ( $unsaved_data ) {
	           	$imgurl =  $query->$url_id;
				$imgpos = $query->$pos_id;
				if ( cowobo()->posts->is_image_url ( $imgurl ) ) {
			    	$thumb = '<img src="'.$imgurl.'" alt=""/>';
				}
			} else {
				$imgpos = get_post_meta( $postid, $pos_id, true );
			}

			//setup positions dropdown
			$positions = array('top', 'middle','bottom');
			foreach($positions as $pos){
				if($pos == $imgpos) $state ='selected'; else $state= '';
				$options .= '<option value="'.$pos.'" '.$state.'/>'.$pos.'</option>';
			}

			//include media form
			echo '<div class="lefthalf imgrow">';
				echo '<div class="thumbcol left">'.$thumb.'<input type="hidden" name="cwb_imgid'.$x.'" value="'. $imgid .'"/></div>';
				echo '<div class="urlcol"><input type="text" name="cwb_url'.$x.'" class="full" value="'. $imgurl .'"/></div>';
			echo '</div>';
			echo '<div class="righthalf imgrow">';
				echo '<div class="poscol right"><select name="cwb_pos'.$x.'">'.$options.'</select></div>';
				echo '<div class="browsecol"><input type="file" class="full" name="file'.$x.'"></div>';
			echo '</div>';
		endfor;

    }




    /**
     * Handle requests to edit posts
     *
     * @todo add BP notifications
     */
    public function edit_request(){
        global $post, $cowobo, $profile_id;
        $rqtype = cowobo()->query->requesttype;
        $rquser = cowobo()->query->requestuser;
        $rqpost = cowobo()->query->requestpost;
        $rqmsg = cowobo()->query->requestmsg;

        //if request is coming from a post use that data instead
        if(!$rquser) $rquser = $profile_id;
        if(!$rqpost) $rqpost = $post->ID;

        //if we are dealing with an existing request get its meta
        $toedit = '';
        if($rqtype != 'add'):
            $requests = get_post_meta($rqpost, 'cwb_request', false);
            foreach($requests as $request):
                $rqdata = explode('|', $request);
                if ($rqdata[0] == $rquser) $toedit =  $request;
            endforeach;
        endif;

        //handle the request
        if($rqtype == 'add'):
            add_post_meta($rqpost, 'cwb_request', $rquser.'|'.$rqmsg);
            $notices = 'editrequest_sent';
        elseif($rqtype == 'accept'):
            delete_post_meta($rqpost, 'cwb_request', $toedit);
            add_post_meta($rqpost, 'cwb_author', $rquser);
            do_action ( 'editrequest_accepted', $rquser, $rqpost );
            $notices = 'editrequest_accepted';
        elseif($rqtype == 'deny'):
            delete_post_meta($rqpost, 'cwb_request', $toedit);
            add_post_meta($rqpost, 'cwb_request', $requestuser.'|deny');
            $notices = 'editrequest_denied';
        elseif($rqtype == 'cancel'):
            delete_post_meta($rqpost, 'cwb_request', $toedit);
            $notices = 'editrequest_cancelled';
        endif;

        cowobo()->redirect( "message", $notices );
    }

    //Get list of all published IDs
    public function get_published_ids() {
        global $wpdb;
        $postobjs = $wpdb->get_results("SELECT ID FROM $wpdb->posts WHERE post_status = 'publish'");
        $postids = array();
        foreach ( $postobjs as $post ) {
            $postids[] = $post->ID;
        }
        return $postids;
    }

    /**
     * Prints RSS links for current feed
     *
     * @param str (optional) feedlink
     * @param str (optional) what to print before the link
     * @param str (optional) what to print after the link
     * @return boolean
     */
    public function print_rss_links( $feed_link = false, $before = '', $after = '' ) {
        $rss_services = array(
            'yahoo' => array(
                'name' => 'myYahoo',
                'url' => 'http://add.my.yahoo.com/rss?url=%enc_feed%',
                ),
			'facebook' => array(
                'name' => 'Facebook',
                'url' => 'http://www.facebook.com/cowobo',
                ),
            'google' => array(
                'name' => 'iGoogle',
                'url' => 'http://fusion.google.com/add?feedurl=%enc_feed%',
                ),
			'bloglines' => array(
                'name' => 'Bloglines',
                'url' => 'http://www.bloglines.com/sub/%feed%',
                ),
			'netvibes' => array(
                'name' => 'netvibes',
                'url' => 'http://www.netvibes.com/subscribe.php?url=%enc_feed%',
                ),
            'newsgator' => array(
                'name' => 'newsgator',
                'url' => 'http://www.newsgator.com/ngs/subscriber/subext.aspx?url=%enc_feed%',
                ),
			'rss_feed' => array(
                'name' => 'Other RSS Feed Readers',
                'url' => '%feed%',
             	),
        );

        if ( ! $feed_link ) $feed_link = $this->current_feed_url();
        $output = "";
        foreach ( $rss_services as $rss ) {
            $output .= "$before<a href ='" .$this->get_feed_url ( $rss['url'], $feed_link ) . "'>{$rss['name']}</a>$after";
        }

        echo $output;
        return true;
    }

    /**
     * Converts the url to the right one
      *
      * @param str $url for the rss service with either %enc_feed% or %feed%
      * @param str $feed_url url for the feed to be added
      * @return str Url for the service with feed url
    */
    private function get_feed_url($url, $feed_url) {
        $url = str_replace(
            array('%enc_feed%', '%feed%'),
            array(urlencode($feed_url), esc_url($feed_url),
        ),$url);
        return $url;
    }

    /**
     * Returns the RSS URL for the current feed in the feederbar
     *
     * @return str RSS URL for the current feed in the feederbar
     */
    private function current_feed_url() {
        $url = 'http';
        if ( isset ( $_SERVER["HTTPS"] ) && $_SERVER["HTTPS"] == "on") {$url .= "s";}
        $url .= "://";
        if ($_SERVER["SERVER_PORT"] != "80") {
            $url .= $_SERVER["SERVER_NAME"].":".$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"];
        } else {
            $url .= $_SERVER["SERVER_NAME"].$_SERVER["REQUEST_URI"];
        }

        if ( substr ( $url, -1 ) != '/' ) $url .= '/';

        $url .= "feed";
        return $url;
    }

    private function has_requests() {
        global $profile_id;
        //check if the user has any pending author requests
        $requestposts = get_posts(array('meta_query'=>array(array('key'=>'cwb_author', 'value'=> $profile_id ), array('key'=>'request')), ));

        if( ! empty ( $requestposts ) ) {
            foreach($requestposts as $requestpost) {
                $requests = get_post_meta($requestpost->ID, 'cwb_request', false);
                $msg = '';
                foreach($requests as $request) {
                    $requestdata = explode('|', $request);
                    if($requestdata[1] != 'deny') {
                        $profile = get_post($requestdata[0]);
                        $msg .= '<form method="post" action="">';
                        $msg .= '<a href="'.get_permalink($profile->ID).'">'.$profile->post_title.'</a> sent you a request for ';
                        $msg .= '<a href="'.get_permalink($requestpost->ID).'">'.$requestpost->post_title.'</a>:<br/> '.$requestdata[1].'<br/>';
                        $msg .= '<input type="hidden" name="requestuser" value="'.$requestdata[0].'"/>';
                        $msg .= '<input type="hidden" name="requestpost" value="'.$requestpost->ID.'"/>';
                        $msg .= '<ul class="horlist">';
                        $msg .= '<li><input type="radio" name="requesttype" value="accept" selected="selected"/>Accept</li>';
                        $msg .= '<li><input type="radio" name="requesttype" value="deny"/>Deny</li>';
                        $msg .= wp_nonce_field( 'request', 'request', true, false );
                        $msg .= '<li><input type="submit" class="auto" value="Update"/></li>';
                        $msg .= '</ul>';
                        $msg .= '</form>';
                    }
                }
            }
            if ( ! empty ( $msg ) ) {
                cowobo()->add_notice( $msg, 'editrequest' );
            }
        }
    }

    public function get_post_authors( $postid = 0 ) {
        if ( ! $postid ) $postid = get_the_ID();
        if ( ! $postid ) return array();

        return get_post_meta( $postid, 'cwb_author', false );
    }

    public function is_user_post_author ( $postid = 0, $profile_id = 0 ) {

		if ( ! is_user_logged_in() ) return false;

        if ( ! $profile_id ) $profile_id = $GLOBALS['profile_id'];
        $authors = $this->get_post_authors( $postid );

		if(current_user_can('edit_others_posts') || in_array( $profile_id, $authors ))

        return true;
    }

    public function post_by_url ( $url = '' ) {
        if ( empty ( $url ) ) $url = cowobo()->query->url;
        //if ( empty ( $url ) ) return;

		$scheme = parse_url($url, PHP_URL_SCHEME);
		if (! $scheme || ! preg_match ( '/^https?$/', $scheme ) )
			$url = "http://{$url}";

        $warning = "There has been an error processing your link";
        if ( empty ( $url ) ) {
            cowobo()->add_notice( $warning,'error');
            return;
        }

		$images = array();
		$title = '';
		$text = '';

		$page = $this->get_page_contents($url);

        if ( empty ( $page ) ) {
            cowobo()->add_notice( $warning,'error');
            return;
        }

        if ( ! class_exists( 'simple_html_dom' ) )
            require_once( COWOBO_PLUGIN_LIB . 'external/simple_html_dom.php');

		$html = str_get_html($page);
		$str = $html->find('text');

		if ($str) {
			$image_els = $html->find('img');

			foreach ($image_els as $el) {
				if ($el->width > 100 && $el->height > 100) // Disregard spacers
                        $images[] = $el->src;

                if ( count ( $images ) == 5 ) break;
			}
			$og_image = $html->find('meta[property=og:image]', 0);
			if ($og_image) array_unshift($images, $og_image->content);

			$title = $html->find('title', 0);
			$title = $title ? $title->plaintext: $url;

            $selectors = array (
                '.instapaper_body', // ReadWriteWeb (or anything with instapaper)
                '.entrytext', // WordPress.com (most WP based blogs)
                '.entry',
                '.post-body', // LifeHacker
                '.DetailedSummary', // Al Jazeera
                '#mw-content-text p', // Wikipedia
                'h2 + div',
                'h1 + div', // CNN
                'p.introduction', // BBC
            );

            $text = '';
            foreach ( $selectors as $selector ) {
                $obj = $html->find( $selector, 0);
                if ( empty ( $obj ) ) continue;
                if ( $text = $html->find( $selector, 0)->innertext ) break;
            }

			$text = preg_replace("/<div[^>]*?>/", "<p>", $text);
			$text = str_replace("</div>", "</p>", $text);
			$text = strip_tags ( $text, '<p><a><br><b><strong><i><em><u>' );
            if ( empty ( $text ) ) cowobo()->add_notice('We could not parse the content for this article, try pasting it in manually.', 'error');
		} else {
			$url = '';
		}

        $query = cowobo()->query;
        $query->post_title = trim ( $title );
        $query->post_content = trim ( $text );
        $query->website = $url;

        $x = 0;
        foreach ( $images as $image ) {
            $caption_id = "cwb_url$x";
            $query->$caption_id = $image;
            $x++;
        }
    }

	/**
	 * Remote page retrieving routine.
	 *
	 * @param string Remote URL
	 * @return mixed Remote page as string, or (bool)false on failure
	 * @access private
	 */
	private function get_page_contents ($url) {
		$response = wp_remote_get($url);
		if (is_wp_error($response)) return false;
		return $response['body'];
	}

    public function is_image_url ( $url ) {
        $image_extensions = array ( 'jpg', 'jpeg', 'png', 'gif' );
        $check_extensions = in_array ( substr(strrchr ( $url,'.'), 1 ), $image_extensions );
		if( strpos( $url , 'http') === 0 && $check_extensions) return true;
		else return false;
    }

}
