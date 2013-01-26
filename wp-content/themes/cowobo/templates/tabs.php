<?php


$prefix = '';
$sort = ( isset ( $sort ) ) ? $sort : '';
if($tabtype == 'cat'):
	if ( isset ( $tabposts ) && ! empty ( $tabposts ) ) $catposts = $tabposts;
    /** @todo something is wrong with $sort **/
	else $catposts = get_posts('cat='.$tabcat->term_id.'&numberposts=3&orderby='.$sort);

	if(is_single()):
		if( isset ( $postcat ) && is_object( $postcat ) && $postcat->slug !='coder') $prefix = 'Related ';
		$catlink = '?showall='.$tabcat->name;
	else:
		$catlink = get_category_link($tabcat->term_id);
	endif;

    if ( ! isset ( $catposts[0] ) ) return;

	echo '<div class="tab">';

		echo '<div class="tabthumb">';
	    cowobo()->posts->the_thumbnail($catposts[0]->ID, $tabcat->slug);
		echo '</div>';

		echo '<div class="tabtext">';
			echo '<h2><a class="black" href="'.$catlink.'">'.$prefix.$tabcat->name.' &raquo;</a></h2>';
			if($catposts):
				foreach($catposts as $catpost):
					$title = '<li class="inline"><a class="light" href="'.get_permalink($catpost->ID).'">'. cowobo()->L10n->the_title($catpost->ID).'</a></li>';
					$comments = '<li>'.get_comments_number($catpost->ID).' Comments</li>';
					$views = '<li>'.cowobo()->posts->get_views($catpost->ID).' Views</li>';
					//$coders = '<li>1&nbsp;&nbsp;Coder</li>';
					$date = '<li>'.cwb_time_passed(strtotime($catpost->post_modified)).'</li>';
					$postcat_catpost = get_the_category($catpost->ID);
					$catlink = '<li><a href="'.get_category_link($postcat_catpost[0]->term_id).'">'.$postcat_catpost[0]->name.'</a></li>';
					if($tabcat->slug == 'wiki'):
						echo '<ul class="horlist nowrap">'.$title.$date.$comments.$views.'</ul>';
					elseif($tabcat->slug == 'news'):
						echo '<ul class="horlist nowrap">'.$title.$date.$comments.$views.'</ul>';
					else:
						echo '<ul class="horlist nowrap">'.$title.$date.$comments.$views.'</ul>';
					endif;
				endforeach;
			else:
				echo '<span class="grey">No posts here yet, check back soon</span>';
			endif;
		echo '</div>';

	echo '</div>';

else:

	$title = '<a href="'.get_permalink($tabpost->ID).'">'. cowobo()->L10n->the_title($tabpost->ID).'</a>';
	$comments = '<li>'.get_comments_number($tabpost->ID).' Comments</li>';
	$views = '<li>'.cowobo()->posts->get_views($tabpost->ID).' Views</li>';
	$coders = '<li>1&nbsp;&nbsp;Coder</li>';
	$date = '<li>'.cwb_time_passed(strtotime($tabpost->post_modified)).'</li>';
	$tabcat = get_the_category($tabpost->ID);
    if ( isset ( $tabcat[0] ) ) {
        $tabtype = cowobo()->feed->get_type($tabcat[0]->term_id);
        $catlink = '<li><a href="'.get_category_link($tabcat[0]->term_id).'">'.$tabcat[0]->name.'</a></li>';
    } else {
        $tabtype = new stdClass();
        $tabtype->slug = '';
        $catlink = '';
    }

	echo '<div class="tab">';

		echo '<div class="tabthumb">';
			cowobo()->posts->the_thumbnail($tabpost->ID, $tabtype->slug);
		echo '</div>';

		echo '<div class="tabtext">';
			if($tabtype->slug == 'wiki'):
				echo $title.'<br/><ul class="horlist grey">'.$date.$comments.$views.$coders.'</ul>';
			elseif($tabtype->slug == 'location'):
				echo $title.', <a href="'.get_category_link($tabcat[0]->term_id).'">'.$tabcat[0]->name.'</a>';
				echo '<ul class="horlist grey">'.$date.$comments.$views.$coders.'</ul>';
			elseif($tabtype->slug == 'news'):
				echo $title.'<ul class="horlist grey">'.$date.$comments.$views.$coders.'</ul>';
			else:
				echo $title.'<ul class="horlist grey">'.$date.$comments.$views.$coders.'</ul>';
			endif;
		echo '</div>';

	echo '</div>';
endif;